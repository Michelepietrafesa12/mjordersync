<?php
/**
 * MJ Order Sync - Queue processor (consumer).
 *
 * Processes the ps_mjordersync_queue table:
 *   - claims pending rows whose next_retry_at <= now
 *   - posts the payload via WebhookSender
 *   - on success: status=done
 *   - on failure: increment retries, exponential backoff on next_retry_at,
 *                 status=failed once MAX_RETRIES is reached
 *
 * Logging into ps_mjordersync_log happens here (the consumer), NOT in the hooks.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSyncQueueProcessor
{
    /** Maximum delivery attempts before marking as failed. */
    const MAX_RETRIES = 6;

    /** Base for exponential backoff in seconds (1m, 2m, 4m, 8m, 16m, 32m). */
    const BACKOFF_BASE_SECONDS = 60;

    /** Hard cap for a single backoff window (avoid overflow). */
    const BACKOFF_MAX_SECONDS = 3600;

    /**
     * Process up to $batchSize pending rows.
     *
     * @return array{processed:int,success:int,failed:int,retry:int}
     */
    public function processBatch(int $batchSize = 25): array
    {
        $stats = ['processed' => 0, 'success' => 0, 'failed' => 0, 'retry' => 0];

        $webhookUrl = (string) Configuration::get('MJORDERSYNC_WEBHOOK_URL');
        if ($webhookUrl === '') {
            return $stats;
        }
        $secretKey = (string) Configuration::get('MJORDERSYNC_SECRET_KEY');

        $rows = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'mjordersync_queue`
             WHERE `status` = "pending"
               AND `next_retry_at` <= "' . pSQL(date('Y-m-d H:i:s')) . '"
             ORDER BY `next_retry_at` ASC, `id_queue` ASC
             LIMIT ' . (int) $batchSize
        );
        if (empty($rows)) {
            return $stats;
        }

        require_once __DIR__ . '/WebhookSender.php';
        $sender = new MjOrderSyncWebhookSender();

        foreach ($rows as $row) {
            try {
                if (!$this->claim((int) $row['id_queue'])) {
                    // Another concurrent worker grabbed it.
                    continue;
                }

                $stats['processed']++;

                $payload = json_decode((string) $row['payload_snapshot'], true);
                if (!is_array($payload)) {
                    $this->markFailed((int) $row['id_queue'], 'Invalid payload snapshot (JSON decode failed)');
                    $this->writeLog(
                        (int) $row['id_order'],
                        (string) $row['event'],
                        ['http_code' => 0, 'error' => 'Invalid payload snapshot', 'response' => ''],
                        []
                    );
                    $stats['failed']++;
                    continue;
                }

                $result = $sender->send($webhookUrl, $payload, $secretKey);

                $this->writeLog(
                    (int) $row['id_order'],
                    (string) $row['event'],
                    $result,
                    $payload
                );

                if (!empty($result['success'])) {
                    $this->markDone((int) $row['id_queue']);
                    $stats['success']++;
                    continue;
                }

                $newRetries = (int) $row['retries'] + 1;
                if ($newRetries >= self::MAX_RETRIES) {
                    $this->markFailed(
                        (int) $row['id_queue'],
                        (string) ($result['error'] ?? 'Max retries reached')
                    );
                    $stats['failed']++;
                    continue;
                }

                $delaySec = min(
                    self::BACKOFF_MAX_SECONDS,
                    self::BACKOFF_BASE_SECONDS * (1 << ($newRetries - 1))
                );
                $this->scheduleRetry(
                    (int) $row['id_queue'],
                    $newRetries,
                    $delaySec,
                    (string) ($result['error'] ?? '')
                );
                $stats['retry']++;
            } catch (\Throwable $e) {
                // Don't let a single bad row kill the batch: log, schedule a retry
                // and move on. Cron will pick it up again on the next tick.
                $this->scheduleRetry(
                    (int) $row['id_queue'],
                    (int) $row['retries'] + 1,
                    self::BACKOFF_BASE_SECONDS,
                    'Worker exception: ' . $e->getMessage()
                );
                $stats['retry']++;
            }
        }

        return $stats;
    }

    /**
     * Atomically move a row from pending -> processing.
     * Returns true if this worker won the race.
     */
    private function claim(int $idQueue): bool
    {
        $db = Db::getInstance();
        $ok = $db->execute(
            'UPDATE `' . _DB_PREFIX_ . 'mjordersync_queue`
             SET `status` = "processing",
                 `updated_at` = "' . pSQL(date('Y-m-d H:i:s')) . '"
             WHERE `id_queue` = ' . (int) $idQueue . '
               AND `status` = "pending"'
        );
        return $ok && (int) $db->Affected_Rows() === 1;
    }

    private function markDone(int $idQueue): void
    {
        Db::getInstance()->update(
            'mjordersync_queue',
            [
                'status'     => 'done',
                'last_error' => '',
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_queue` = ' . (int) $idQueue
        );
    }

    private function markFailed(int $idQueue, string $error): void
    {
        Db::getInstance()->update(
            'mjordersync_queue',
            [
                'status'     => 'failed',
                'last_error' => pSQL(substr($error, 0, 2000)),
                'updated_at' => date('Y-m-d H:i:s'),
            ],
            '`id_queue` = ' . (int) $idQueue
        );
    }

    private function scheduleRetry(int $idQueue, int $retries, int $delaySec, string $error): void
    {
        $nextAt = date('Y-m-d H:i:s', time() + $delaySec);
        Db::getInstance()->update(
            'mjordersync_queue',
            [
                'status'        => 'pending',
                'retries'       => $retries,
                'next_retry_at' => $nextAt,
                'last_error'    => pSQL(substr($error, 0, 2000)),
                'updated_at'    => date('Y-m-d H:i:s'),
            ],
            '`id_queue` = ' . (int) $idQueue
        );
    }

    private function writeLog(int $orderId, string $event, array $result, array $payload): void
    {
        $statusCode = (int) ($result['http_code'] ?? 0);
        $response   = (string) ($result['response'] ?? $result['error'] ?? '');

        Db::getInstance()->insert('mjordersync_log', [
            'id_order'    => $orderId,
            'event'       => pSQL($event),
            'status_code' => $statusCode,
            'response'    => pSQL(substr($response, 0, 2000)),
            'payload'     => pSQL(json_encode($payload), true),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

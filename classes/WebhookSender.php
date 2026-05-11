<?php
/**
 * MJ Order Sync - Webhook HTTP sender.
 *
 * Pure transport layer: serialize payload to JSON, attach the HMAC-SHA256
 * signature header if a secret is configured, POST it, return a normalized
 * result array. It must NEVER throw — callers (cron QueueProcessor, admin
 * test ping) rely on the array shape for retry / logging decisions.
 *
 * Timeouts are intentionally tight (connect 5s, total 8s) because the cron
 * batches multiple events; a stuck receiver would otherwise stall the whole
 * batch.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSyncWebhookSender
{
    const CONNECT_TIMEOUT_SEC = 5;
    const TIMEOUT_SEC         = 8;
    const USER_AGENT_PREFIX   = 'MjOrderSync/1.2';

    /**
     * @return array{success:bool,http_code:int,response:string,error:string}
     */
    public function send(string $url, array $payload, string $secretKey = ''): array
    {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($body === false) {
            return [
                'success'   => false,
                'http_code' => 0,
                'response'  => '',
                'error'     => 'json_encode failed: ' . json_last_error_msg(),
            ];
        }

        $event     = (string) ($payload['event'] ?? '');
        $timestamp = time();
        $userAgent = self::USER_AGENT_PREFIX . ' PrestaShop/' . _PS_VERSION_;

        $headers = [
            'Content-Type: application/json',
            'User-Agent: ' . $userAgent,
            'X-MJSync-Event: ' . $event,
            'X-MJSync-Timestamp: ' . $timestamp,
        ];

        if ($secretKey !== '') {
            $headers[] = 'X-MJSync-Signature: sha256=' . hash_hmac('sha256', $body, $secretKey);
        }

        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT_SEC);
        curl_setopt($ch, CURLOPT_TIMEOUT, self::TIMEOUT_SEC);
        curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = '';
        if ($response === false) {
            $curlErr  = 'cURL: ' . curl_error($ch);
            $response = '';
        }
        curl_close($ch);

        $success = ($httpCode >= 200 && $httpCode < 300);
        $error   = '';
        if (!$success) {
            $error = $curlErr !== ''
                ? $curlErr
                : 'HTTP ' . $httpCode . ': ' . substr((string) $response, 0, 500);
        }

        return [
            'success'   => $success,
            'http_code' => $httpCode,
            'response'  => (string) $response,
            'error'     => $error,
        ];
    }
}

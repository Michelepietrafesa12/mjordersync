<?php
/**
 * MJ Order Sync - Cron entrypoint.
 *
 * Hit this URL from a cron job (system crontab or PrestaShop Cron Tasks Manager):
 *   index.php?fc=module&module=mjordersync&controller=cron&token=<CRON_TOKEN>
 *
 * Authenticates via a constant-time comparison against MJORDERSYNC_CRON_TOKEN
 * stored in PrestaShop configuration. Returns JSON with batch stats.
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSyncCronModuleFrontController extends ModuleFrontController
{
    /** @var bool ajax flag is irrelevant here, but keep CSRF off for cron URLs */
    public $ssl = true;

    public function initContent()
    {
        // Don't render the storefront layout at all.
        $this->ajax = true;

        $expected = (string) Configuration::get('MJORDERSYNC_CRON_TOKEN');
        $provided = (string) Tools::getValue('token', '');

        if ($expected === '' || !hash_equals($expected, $provided)) {
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'invalid_token']);
            exit;
        }

        $batchSize = (int) Tools::getValue('batch', 25);
        if ($batchSize < 1)   { $batchSize = 1; }
        if ($batchSize > 200) { $batchSize = 200; }

        require_once _PS_MODULE_DIR_ . 'mjordersync/classes/QueueProcessor.php';
        $processor = new MjOrderSyncQueueProcessor();

        try {
            $stats = $processor->processBatch($batchSize);
            $out = ['ok' => true, 'stats' => $stats, 'ts' => date('c')];
        } catch (Exception $e) {
            header('HTTP/1.1 500 Internal Server Error');
            $out = ['ok' => false, 'error' => $e->getMessage(), 'ts' => date('c')];
        }

        header('Content-Type: application/json');
        echo json_encode($out);
        exit;
    }
}

<?php
/**
 * MJ Order Sync - Real-time order webhook for personal dashboard
 *
 * @author    Michele (pietrafesamichele.it)
 * @version   1.1.0
 * @license   AFL 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSync extends Module
{
    /** Config keys */
    const CFG_WEBHOOK_URL    = 'MJORDERSYNC_WEBHOOK_URL';
    const CFG_SECRET_KEY     = 'MJORDERSYNC_SECRET_KEY';
    const CFG_ENABLED        = 'MJORDERSYNC_ENABLED';
    const CFG_SEND_ON_CREATE = 'MJORDERSYNC_SEND_ON_CREATE';
    const CFG_SEND_ON_UPDATE = 'MJORDERSYNC_SEND_ON_UPDATE';
    const CFG_LAST_LOG       = 'MJORDERSYNC_LAST_LOG';
    const CFG_CRON_TOKEN     = 'MJORDERSYNC_CRON_TOKEN';

    /** Hooks this module registers */
    const HOOKS = [
        'actionValidateOrder',
        'actionOrderStatusUpdate',
    ];

    public function __construct()
    {
        $this->name    = 'mjordersync';
        $this->tab     = 'administration';
        $this->version = '1.2.0';
        $this->author  = 'Michele';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '9.0'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('MJ Order Sync – Dashboard in tempo reale');
        $this->description = $this->l('Invia gli ordini in tempo reale alla tua dashboard tramite webhook asincrono (coda + cron). Supporta firma HMAC-SHA256.');
        $this->confirmUninstall = $this->l('Sei sicuro di voler disinstallare MJ Order Sync?');
    }

    /* ================================================================
     * Install / Uninstall
     * ================================================================ */

    public function install(): bool
    {
        return parent::install()
            && $this->registerHooksArray()
            && $this->createLogTable()
            && $this->createQueueTable()
            && $this->ensureCronToken();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->unregisterHooksArray()
            && $this->deleteConfiguration()
            && $this->dropLogTable()
            && $this->dropQueueTable();
    }

    private function registerHooksArray(): bool
    {
        foreach (self::HOOKS as $hook) {
            if (!$this->registerHook($hook)) {
                return false;
            }
        }
        return true;
    }

    private function unregisterHooksArray(): bool
    {
        foreach (self::HOOKS as $hook) {
            $this->unregisterHook($hook);
        }
        return true;
    }

    private function deleteConfiguration(): bool
    {
        Configuration::deleteByName(self::CFG_WEBHOOK_URL);
        Configuration::deleteByName(self::CFG_SECRET_KEY);
        Configuration::deleteByName(self::CFG_ENABLED);
        Configuration::deleteByName(self::CFG_SEND_ON_CREATE);
        Configuration::deleteByName(self::CFG_SEND_ON_UPDATE);
        Configuration::deleteByName(self::CFG_LAST_LOG);
        Configuration::deleteByName(self::CFG_CRON_TOKEN);
        return true;
    }

    private function createLogTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mjordersync_log` (
            `id_log`      INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order`    INT UNSIGNED NOT NULL,
            `event`       VARCHAR(32)  NOT NULL,
            `status_code` SMALLINT    NOT NULL DEFAULT 0,
            `response`    TEXT,
            `payload`     MEDIUMTEXT,
            `created_at`  DATETIME    NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id_log`),
            KEY `idx_order` (`id_order`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function dropLogTable(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mjordersync_log`'
        );
    }

    private function createQueueTable(): bool
    {
        $sql = 'CREATE TABLE IF NOT EXISTS `' . _DB_PREFIX_ . 'mjordersync_queue` (
            `id_queue`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `id_order`         INT UNSIGNED NOT NULL,
            `event`            VARCHAR(32)  NOT NULL,
            `payload_snapshot` MEDIUMTEXT   NOT NULL,
            `retries`          SMALLINT     UNSIGNED NOT NULL DEFAULT 0,
            `next_retry_at`    DATETIME     NOT NULL,
            `status`           VARCHAR(16)  NOT NULL DEFAULT "pending",
            `last_error`       TEXT         NULL,
            `created_at`       DATETIME     NOT NULL,
            `updated_at`       DATETIME     NOT NULL,
            PRIMARY KEY (`id_queue`),
            KEY `idx_status_next_retry` (`status`, `next_retry_at`),
            KEY `idx_order` (`id_order`)
        ) ENGINE=' . _MYSQL_ENGINE_ . ' DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function dropQueueTable(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mjordersync_queue`'
        );
    }

    private function ensureCronToken(): bool
    {
        if (!Configuration::get(self::CFG_CRON_TOKEN)) {
            try {
                $token = bin2hex(random_bytes(16));
            } catch (Exception $e) {
                $token = md5(uniqid('mjordersync', true));
            }
            Configuration::updateValue(self::CFG_CRON_TOKEN, $token);
        }
        return true;
    }

    /* ================================================================
     * Admin configuration page
     * ================================================================ */

    public function getContent(): string
    {
        // Whole body wrapped so a fatal Error in any render/process path is
        // caught and the actual exception message + location is displayed,
        // instead of PrestaShop's generic "fatal error" page that hides what
        // really happened.
        try {
            $output = '';

            // Handle test ping
            if (Tools::isSubmit('mjordersync_test')) {
                $output .= $this->processTestPing();
            }

            // Handle manual queue flush (admin-side trigger)
            if (Tools::isSubmit('mjordersync_flush')) {
                $output .= $this->processManualFlush();
            }

            // Handle resend of a failed log row
            if (Tools::isSubmit('mjordersync_retry')) {
                $output .= $this->processRetry((int) Tools::getValue('id_log'));
            }

            // Handle form save
            if (Tools::isSubmit('submitMjOrderSync')) {
                $output .= $this->processForm();
            }

            return $output . $this->renderCronInfo() . $this->renderForm() . $this->renderQueue() . $this->renderLog();
        } catch (\Throwable $e) {
            PrestaShopLogger::addLog(
                '[MjOrderSync] getContent() ' . get_class($e) . ': ' . $e->getMessage()
                . ' @ ' . $e->getFile() . ':' . $e->getLine(),
                3
            );
            return $this->displayError(sprintf(
                '%s<br><br><strong>%s</strong>: %s<br><code>%s:%d</code>',
                $this->l('Errore durante il rendering della pagina di configurazione. Dettagli sotto, e copia di sicurezza in BO -> Parametri avanzati -> Log.'),
                htmlspecialchars(get_class($e)),
                htmlspecialchars($e->getMessage()),
                htmlspecialchars($e->getFile()),
                (int) $e->getLine()
            ));
        }
    }

    private function processForm(): string
    {
        $url = trim(Tools::getValue('MJORDERSYNC_WEBHOOK_URL'));
        $key = trim(Tools::getValue('MJORDERSYNC_SECRET_KEY'));

        if (!empty($url) && !Validate::isAbsoluteUrl($url)) {
            return $this->displayError($this->l('URL webhook non valido.'));
        }

        Configuration::updateValue(self::CFG_WEBHOOK_URL,    $url);
        Configuration::updateValue(self::CFG_SECRET_KEY,     $key);
        Configuration::updateValue(self::CFG_ENABLED,        (int) Tools::getValue('MJORDERSYNC_ENABLED'));
        Configuration::updateValue(self::CFG_SEND_ON_CREATE, (int) Tools::getValue('MJORDERSYNC_SEND_ON_CREATE'));
        Configuration::updateValue(self::CFG_SEND_ON_UPDATE, (int) Tools::getValue('MJORDERSYNC_SEND_ON_UPDATE'));

        return $this->displayConfirmation($this->l('Configurazione salvata.'));
    }

    private function processTestPing(): string
    {
        $url = Configuration::get(self::CFG_WEBHOOK_URL);
        if (empty($url)) {
            return $this->displayError($this->l('Configura prima l\'URL del webhook.'));
        }

        $payload = [
            'event'      => 'test_ping',
            'timestamp'  => date('c'),
            'message'    => 'MJ Order Sync – test connessione da PrestaShop',
            'shop_url'   => Tools::getShopDomainSsl(true),
        ];

        require_once __DIR__ . '/classes/WebhookSender.php';
        $sender = new MjOrderSyncWebhookSender();
        $result = $sender->send($url, $payload, Configuration::get(self::CFG_SECRET_KEY));

        if ($result['success']) {
            return $this->displayConfirmation(
                $this->l('Test OK! HTTP ') . $result['http_code']
            );
        }

        return $this->displayError(
            $this->l('Test fallito: ') . htmlspecialchars($result['error'])
        );
    }

    private function processManualFlush(): string
    {
        require_once __DIR__ . '/classes/QueueProcessor.php';
        $processor = new MjOrderSyncQueueProcessor();
        $stats = $processor->processBatch(25);

        return $this->displayConfirmation(sprintf(
            $this->l('Coda processata: %d tentativi, %d successi, %d retry, %d falliti.'),
            (int) $stats['processed'],
            (int) $stats['success'],
            (int) $stats['retry'],
            (int) $stats['failed']
        ));
    }

    /**
     * Resend a failed log row by rebuilding the payload from the current Order
     * state and pushing it back through the queue. The payload is rebuilt (not
     * replayed from the old log) so that any change to the order in the
     * meantime (tracking number added, status changed, etc.) is reflected.
     */
    private function processRetry(int $idLog): string
    {
        if ($idLog <= 0) {
            return $this->displayError($this->l('ID log non valido.'));
        }

        $row = Db::getInstance()->getRow(
            'SELECT `id_order`, `event` FROM `' . _DB_PREFIX_ . 'mjordersync_log`
             WHERE `id_log` = ' . (int) $idLog
        );
        if (!$row) {
            return $this->displayError($this->l('Log non trovato.'));
        }

        $order = new Order((int) $row['id_order']);
        if (!Validate::isLoadedObject($order)) {
            return $this->displayError($this->l('Ordine non più esistente.'));
        }

        // Re-enqueue with a fresh snapshot from the current Order state.
        $this->enqueueFromOrder($order, (string) $row['event']);

        // Drain a small batch so the admin gets immediate feedback in the log
        // rather than waiting for the next cron tick.
        require_once __DIR__ . '/classes/QueueProcessor.php';
        $processor = new MjOrderSyncQueueProcessor();
        $stats = $processor->processBatch(5);

        return $this->displayConfirmation(sprintf(
            $this->l('Webhook ri-accodato per ordine #%d e processato (%d successi, %d retry, %d falliti). Controlla il log.'),
            (int) $order->id,
            (int) $stats['success'],
            (int) $stats['retry'],
            (int) $stats['failed']
        ));
    }

    private function renderCronInfo(): string
    {
        $token = (string) Configuration::get(self::CFG_CRON_TOKEN);
        $shop  = Tools::getShopDomainSsl(true);
        $url   = $shop . __PS_BASE_URI__ . 'index.php?fc=module&module=mjordersync&controller=cron&token=' . urlencode($token);

        return '<div class="panel">'
            . '<div class="panel-heading"><i class="icon-clock-o"></i> ' . $this->l('Cron processor') . '</div>'
            . '<div style="padding:15px">'
            . '<p>' . $this->l('Il webhook è ora asincrono: gli hook accodano gli eventi e il cron li invia in background. Configura un cron job (ogni 1–5 minuti) che richiami questo URL:') . '</p>'
            . '<pre style="white-space:pre-wrap;word-break:break-all;background:#f5f5f5;padding:10px;border:1px solid #ddd">'
            . htmlspecialchars($url) . '</pre>'
            . '<p class="text-muted" style="margin-top:10px">'
            . $this->l('Esempio crontab (sistema): ') . '<code>* * * * * curl -fsS --max-time 60 "' . htmlspecialchars($url) . '" > /dev/null</code>'
            . '</p>'
            . '<p class="text-muted">'
            . $this->l('Oppure registralo in PrestaShop tramite il modulo "Cron tasks manager" (cronjobs).')
            . '</p>'
            . '</div></div>';
    }

    private function renderForm(): string
    {
        $helper = new HelperForm();
        $helper->module          = $this;
        $helper->name_controller = $this->name;
        $helper->token           = Tools::getAdminTokenLite('AdminModules');
        $helper->currentIndex    = AdminController::$currentIndex . '&configure=' . $this->name;
        $helper->default_form_language    = (int) Configuration::get('PS_LANG_DEFAULT');
        $helper->allow_employee_form_lang = (int) Configuration::get('PS_BO_ALLOW_EMPLOYEE_FORM_LANG');
        $helper->title       = $this->displayName;
        $helper->show_toolbar = false;
        $helper->submit_action = 'submitMjOrderSync';

        $fields_form = [
            'form' => [
                'legend' => [
                    'title' => $this->l('Impostazioni Webhook'),
                    'icon'  => 'icon-bolt',
                ],
                'input' => [
                    [
                        'type'     => 'switch',
                        'label'    => $this->l('Abilitato'),
                        'name'     => self::CFG_ENABLED,
                        'is_bool'  => true,
                        'values'   => [
                            ['id' => 'enabled_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'enabled_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type'     => 'text',
                        'label'    => $this->l('URL Webhook'),
                        'name'     => self::CFG_WEBHOOK_URL,
                        'size'     => 80,
                        'required' => true,
                        'hint'     => $this->l('Es. https://tuo-n8n.it/webhook/ordini'),
                        'desc'     => $this->l('Endpoint che riceve il payload JSON degli ordini.'),
                    ],
                    [
                        'type'  => 'text',
                        'label' => $this->l('Secret Key (HMAC-SHA256)'),
                        'name'  => self::CFG_SECRET_KEY,
                        'size'  => 60,
                        'hint'  => $this->l('Lascia vuoto per disabilitare la firma.'),
                        'desc'  => $this->l('Verrà aggiunto l\'header X-MJSync-Signature per verificare l\'autenticità.'),
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Invia su nuovo ordine'),
                        'name'    => self::CFG_SEND_ON_CREATE,
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'create_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'create_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                    [
                        'type'    => 'switch',
                        'label'   => $this->l('Invia su aggiornamento stato'),
                        'name'    => self::CFG_SEND_ON_UPDATE,
                        'is_bool' => true,
                        'values'  => [
                            ['id' => 'update_on', 'value' => 1, 'label' => $this->l('Sì')],
                            ['id' => 'update_off', 'value' => 0, 'label' => $this->l('No')],
                        ],
                    ],
                ],
                'submit' => [
                    'title' => $this->l('Salva'),
                    'class' => 'btn btn-default pull-right',
                ],
                'buttons' => [
                    [
                        'href'  => AdminController::$currentIndex
                            . '&configure=' . $this->name
                            . '&mjordersync_test=1'
                            . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                        'title' => $this->l('Test connessione'),
                        'icon'  => 'process-icon-refresh',
                        'class' => 'btn btn-default',
                        'name'  => 'mjordersync_test',
                    ],
                    [
                        'href'  => AdminController::$currentIndex
                            . '&configure=' . $this->name
                            . '&mjordersync_flush=1'
                            . '&token=' . Tools::getAdminTokenLite('AdminModules'),
                        'title' => $this->l('Processa coda ora'),
                        'icon'  => 'process-icon-play',
                        'class' => 'btn btn-default',
                        'name'  => 'mjordersync_flush',
                    ],
                ],
            ],
        ];

        $helper->fields_value = [
            self::CFG_WEBHOOK_URL    => Configuration::get(self::CFG_WEBHOOK_URL),
            self::CFG_SECRET_KEY     => Configuration::get(self::CFG_SECRET_KEY),
            self::CFG_ENABLED        => (int) Configuration::get(self::CFG_ENABLED),
            self::CFG_SEND_ON_CREATE => (int) Configuration::get(self::CFG_SEND_ON_CREATE, null, null, null, 1),
            self::CFG_SEND_ON_UPDATE => (int) Configuration::get(self::CFG_SEND_ON_UPDATE, null, null, null, 1),
        ];

        return $helper->generateForm([$fields_form]);
    }

    private function renderQueue(): string
    {
        $counts = Db::getInstance()->executeS(
            'SELECT `status`, COUNT(*) AS c
             FROM `' . _DB_PREFIX_ . 'mjordersync_queue`
             GROUP BY `status`'
        );

        $map = ['pending' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0];
        // executeS returns false on DB error (e.g. missing table). Guard
        // explicitly so we don't iterate over [false] and trip the PHP 8
        // "array offset on bool" warning that some setups elevate to fatal.
        if (is_array($counts)) {
            foreach ($counts as $row) {
                if (!is_array($row) || !isset($row['status'])) {
                    continue;
                }
                $map[$row['status']] = (int) ($row['c'] ?? 0);
            }
        }

        return '<div class="panel"><div class="panel-heading">'
            . '<i class="icon-tasks"></i> ' . $this->l('Stato coda webhook')
            . '</div><div style="padding:15px">'
            . '<span class="badge badge-warning">' . $this->l('In attesa') . ': ' . $map['pending'] . '</span> '
            . '<span class="badge badge-info">' . $this->l('In corso') . ': ' . $map['processing'] . '</span> '
            . '<span class="badge badge-success">' . $this->l('Inviati') . ': ' . $map['done'] . '</span> '
            . '<span class="badge badge-danger">' . $this->l('Falliti') . ': ' . $map['failed'] . '</span>'
            . '</div></div>';
    }

    private function renderLog(): string
    {
        $logs = Db::getInstance()->executeS(
            'SELECT * FROM `' . _DB_PREFIX_ . 'mjordersync_log`
             ORDER BY `created_at` DESC
             LIMIT 30'
        );

        if (empty($logs)) {
            return '<div class="panel"><div class="panel-heading">'
                . $this->l('Log ultimi invii') . '</div>'
                . '<p class="text-muted" style="padding:15px">'
                . $this->l('Nessun invio registrato.') . '</p></div>';
        }

        $html  = '<div class="panel"><div class="panel-heading">'
               . '<i class="icon-list"></i> ' . $this->l('Log ultimi 30 invii')
               . '</div><table class="table tableDnD" style="font-size:12px">';
        $html .= '<thead><tr>'
               . '<th>#ID</th><th>' . $this->l('Ordine') . '</th>'
               . '<th>' . $this->l('Evento') . '</th>'
               . '<th>HTTP</th><th>' . $this->l('Data') . '</th>'
               . '<th>' . $this->l('Risposta') . '</th>'
               . '<th>' . $this->l('Azione') . '</th>'
               . '</tr></thead><tbody>';

        foreach ($logs as $row) {
            $statusCode = (int) $row['status_code'];
            $isFailure  = ($statusCode < 200 || $statusCode >= 300);

            $badge = !$isFailure
                ? '<span class="badge badge-success">' . $statusCode . '</span>'
                : '<span class="badge badge-danger">'  . $statusCode . '</span>';

            $response = htmlspecialchars(substr((string)$row['response'], 0, 120));

            $action = '';
            if ($isFailure) {
                $retryUrl = AdminController::$currentIndex
                    . '&configure=' . $this->name
                    . '&mjordersync_retry=1'
                    . '&id_log=' . (int) $row['id_log']
                    . '&token=' . Tools::getAdminTokenLite('AdminModules');
                $action = '<a href="' . $retryUrl . '" class="btn btn-xs btn-warning">'
                    . $this->l('Rispedisci') . '</a>';
            }

            $html .= '<tr>'
                   . '<td>' . (int)$row['id_log'] . '</td>'
                   . '<td><a href="' . $this->context->link->getAdminLink('AdminOrders')
                   . '&vieworder&id_order=' . (int)$row['id_order']
                   . '" target="_blank">#' . (int)$row['id_order'] . '</a></td>'
                   . '<td><code>' . htmlspecialchars($row['event']) . '</code></td>'
                   . '<td>' . $badge . '</td>'
                   . '<td>' . htmlspecialchars($row['created_at']) . '</td>'
                   . '<td style="max-width:300px;overflow:hidden">' . $response . '</td>'
                   . '<td>' . $action . '</td>'
                   . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /* ================================================================
     * Hooks (now ASYNC: build payload + enqueue, no HTTP call here)
     * ================================================================ */

    /**
     * Hook: new order created – enqueue only, never block checkout.
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!$this->isSyncEnabled() || !(int) Configuration::get(self::CFG_SEND_ON_CREATE)) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return;
        }

        $this->enqueueFromOrder($order, 'order.created');
    }

    /**
     * Hook: order status changed – enqueue only.
     *
     * Uses actionOrderStatusUpdate (fires only on real state transitions) instead
     * of actionObjectOrderUpdateAfter (which fires on every Order save and would
     * generate 5-10 duplicate webhooks per order during its lifecycle, plus open
     * the door to update loops when a downstream system writes back to PS).
     */
    public function hookActionOrderStatusUpdate(array $params): void
    {
        $sendOnUpdate = Configuration::get(self::CFG_SEND_ON_UPDATE);
        if (!$this->isSyncEnabled() || ($sendOnUpdate !== false && !(int) $sendOnUpdate)) {
            return;
        }

        $newStatus = $params['newOrderStatus'] ?? null;
        $orderId   = (int) ($params['id_order'] ?? 0);
        if (!$newStatus || !$orderId) {
            return;
        }

        $order = new Order($orderId);
        if (!Validate::isLoadedObject($order)) {
            return;
        }

        $this->enqueueFromOrder($order, 'order.updated');
    }

    /* ================================================================
     * Enqueue logic
     * ================================================================ */

    /**
     * Builds the payload snapshot and inserts a row in the queue table.
     * Must never throw out of the hook: failures are logged silently so they
     * cannot block the order validation transaction.
     */
    private function enqueueFromOrder(Order $order, string $event): void
    {
        if (empty(Configuration::get(self::CFG_WEBHOOK_URL))) {
            return;
        }

        try {
            require_once __DIR__ . '/classes/OrderPayloadBuilder.php';
            $builder = new MjOrderSyncOrderPayloadBuilder();
            $payload = $builder->build($order, $event);

            $now = date('Y-m-d H:i:s');
            Db::getInstance()->insert('mjordersync_queue', [
                'id_order'         => (int) $order->id,
                'event'            => pSQL($event),
                'payload_snapshot' => pSQL(json_encode($payload), true),
                'retries'          => 0,
                'next_retry_at'    => $now,
                'status'           => 'pending',
                'created_at'       => $now,
                'updated_at'       => $now,
            ]);
        } catch (\Throwable $e) {
            // Throwable covers both Exception and Error (PHP 7+).
            // A missing class file (require_once failure) raises an Error, not an
            // Exception, so catching only Exception here would let it bubble up
            // and break order validation. Swallow everything; the hook MUST NOT
            // block the order. Logged for diagnostics.
            PrestaShopLogger::addLog(
                '[MjOrderSync] Errore enqueue: ' . $e->getMessage(),
                3, null, 'Order', $order->id
            );
        }
    }

    /**
     * Whether the webhook sync is turned on in the module config.
     *
     * NOTE: NOT named isEnabled() — that name collides with the static
     * ModuleCore::isEnabled($module_name) declared in PrestaShop core. PHP
     * refuses at compile time to redeclare a parent static method as
     * non-static ("Cannot make static method ModuleCore::isEnabled() non
     * static in class ..."), which on PS 8.x blocks the whole module from
     * loading.
     */
    private function isSyncEnabled(): bool
    {
        return (bool) Configuration::get(self::CFG_ENABLED);
    }
}

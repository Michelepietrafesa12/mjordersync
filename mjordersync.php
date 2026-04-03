<?php
/**
 * MJ Order Sync - Real-time order webhook for personal dashboard
 *
 * @author    Michele (pietrafesamichele.it)
 * @version   1.0.0
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

    /** Hooks this module registers */
    const HOOKS = [
        'actionValidateOrder',
        'actionObjectOrderUpdateAfter',
    ];

    public function __construct()
    {
        $this->name    = 'mjordersync';
        $this->tab     = 'administration';
        $this->version = '1.0.0';
        $this->author  = 'Michele';
        $this->need_instance = 0;
        $this->ps_versions_compliancy = ['min' => '1.7', 'max' => '9.0'];
        $this->bootstrap = true;

        parent::__construct();

        $this->displayName = $this->l('MJ Order Sync – Dashboard in tempo reale');
        $this->description = $this->l('Invia gli ordini in tempo reale alla tua dashboard tramite webhook. Supporta firma HMAC-SHA256.');
        $this->confirmUninstall = $this->l('Sei sicuro di voler disinstallare MJ Order Sync?');
    }

    /* ================================================================
     * Install / Uninstall
     * ================================================================ */

    public function install(): bool
    {
        return parent::install()
            && $this->registerHooksArray()
            && $this->createLogTable();
    }

    public function uninstall(): bool
    {
        return parent::uninstall()
            && $this->unregisterHooksArray()
            && $this->deleteConfiguration()
            && $this->dropLogTable();
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;';

        return Db::getInstance()->execute($sql);
    }

    private function dropLogTable(): bool
    {
        return Db::getInstance()->execute(
            'DROP TABLE IF EXISTS `' . _DB_PREFIX_ . 'mjordersync_log`'
        );
    }

    /* ================================================================
     * Admin configuration page
     * ================================================================ */

    public function getContent(): string
    {
        $output = '';

        // Handle test ping
        if (Tools::isSubmit('mjordersync_test')) {
            $output .= $this->processTestPing();
        }

        // Handle form save
        if (Tools::isSubmit('submitMjOrderSync')) {
            $output .= $this->processForm();
        }

        return $output . $this->renderForm() . $this->renderLog();
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
               . '</tr></thead><tbody>';

        foreach ($logs as $row) {
            $badge = $row['status_code'] >= 200 && $row['status_code'] < 300
                ? '<span class="badge badge-success">' . $row['status_code'] . '</span>'
                : '<span class="badge badge-danger">'  . $row['status_code'] . '</span>';

            $response = htmlspecialchars(substr((string)$row['response'], 0, 120));

            $html .= '<tr>'
                   . '<td>' . (int)$row['id_log'] . '</td>'
                   . '<td><a href="' . $this->context->link->getAdminLink('AdminOrders')
                   . '&vieworder&id_order=' . (int)$row['id_order']
                   . '" target="_blank">#' . (int)$row['id_order'] . '</a></td>'
                   . '<td><code>' . htmlspecialchars($row['event']) . '</code></td>'
                   . '<td>' . $badge . '</td>'
                   . '<td>' . htmlspecialchars($row['created_at']) . '</td>'
                   . '<td style="max-width:300px;overflow:hidden">' . $response . '</td>'
                   . '</tr>';
        }

        $html .= '</tbody></table></div>';

        return $html;
    }

    /* ================================================================
     * Hooks
     * ================================================================ */

    /**
     * Hook: new order created
     */
    public function hookActionValidateOrder(array $params): void
    {
        if (!$this->isEnabled() || !(int) Configuration::get(self::CFG_SEND_ON_CREATE)) {
            return;
        }

        /** @var Order $order */
        $order = $params['order'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return;
        }

        $this->dispatchWebhook($order, 'order.created');
    }

    /**
     * Hook: order status changed
     */
    public function hookActionObjectOrderUpdateAfter(array $params): void
    {
        if (!$this->isEnabled() || !(int) Configuration::get(self::CFG_SEND_ON_UPDATE)) {
            return;
        }

        /** @var Order $order */
        $order = $params['object'] ?? null;
        if (!$order || !Validate::isLoadedObject($order)) {
            return;
        }

        $this->dispatchWebhook($order, 'order.updated');
    }

    /* ================================================================
     * Core dispatch logic
     * ================================================================ */

    private function dispatchWebhook(Order $order, string $event): void
    {
        require_once __DIR__ . '/classes/OrderPayloadBuilder.php';
        require_once __DIR__ . '/classes/WebhookSender.php';

        $webhookUrl = Configuration::get(self::CFG_WEBHOOK_URL);
        $secretKey  = Configuration::get(self::CFG_SECRET_KEY);

        if (empty($webhookUrl)) {
            return;
        }

        try {
            $builder  = new MjOrderSyncOrderPayloadBuilder();
            $payload  = $builder->build($order, $event);

            $sender   = new MjOrderSyncWebhookSender();
            $result   = $sender->send($webhookUrl, $payload, $secretKey);

            $this->writeLog($order->id, $event, $result, $payload);
        } catch (Exception $e) {
            PrestaShopLogger::addLog(
                '[MjOrderSync] Errore dispatch: ' . $e->getMessage(),
                3, null, 'Order', $order->id
            );
        }
    }

    private function isEnabled(): bool
    {
        return (bool) Configuration::get(self::CFG_ENABLED);
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
            'payload'     => pSQL(json_encode($payload)),
            'created_at'  => date('Y-m-d H:i:s'),
        ]);
    }
}

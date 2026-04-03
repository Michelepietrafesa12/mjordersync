<?php
/**
 * MJ Order Sync - Sends webhook HTTP requests with optional HMAC-SHA256 signature
 *
 * @author    Michele (pietrafesamichele.it)
 * @license   AFL 3.0
 */

if (!defined('_PS_VERSION_')) {
    exit;
}

class MjOrderSyncWebhookSender
{
    /** @var int Connection timeout in seconds */
    private $connectTimeout = 5;

    /** @var int Request timeout in seconds */
    private $timeout = 8;

    /**
     * Send a JSON payload to the given URL.
     *
     * @param string      $url       Webhook endpoint
     * @param array       $payload   Data to encode as JSON
     * @param string|null $secretKey HMAC-SHA256 secret (empty/null = no signature)
     *
     * @return array{success: bool, http_code: int, response: string, error: string}
     */
    public function send(string $url, array $payload, ?string $secretKey = null): array
    {
        $json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        if ($json === false) {
            return [
                'success'   => false,
                'http_code' => 0,
                'response'  => '',
                'error'     => 'JSON encode error: ' . json_last_error_msg(),
            ];
        }

        $timestamp = time();
        $event = $payload['event'] ?? 'unknown';
        $psVersion = defined('_PS_VERSION_') ? _PS_VERSION_ : 'unknown';

        $headers = [
            'Content-Type: application/json',
            'User-Agent: MjOrderSync/1.0 PrestaShop/' . $psVersion,
            'X-MJSync-Event: ' . $event,
            'X-MJSync-Timestamp: ' . $timestamp,
        ];

        if (!empty($secretKey)) {
            $signature = 'sha256=' . hash_hmac('sha256', $json, $secretKey);
            $headers[] = 'X-MJSync-Signature: ' . $signature;
        }

        $ch = curl_init($url);

        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3,
        ]);

        $response = curl_exec($ch);
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($response === false) {
            return [
                'success'   => false,
                'http_code' => $httpCode,
                'response'  => '',
                'error'     => 'cURL error: ' . $curlError,
            ];
        }

        return [
            'success'   => $httpCode >= 200 && $httpCode < 300,
            'http_code' => $httpCode,
            'response'  => (string) $response,
            'error'     => $httpCode >= 300 ? 'HTTP ' . $httpCode : '',
        ];
    }
}

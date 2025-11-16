<?php

namespace Manomite\Utility;

use Manomite\{
    Services\Telegram\Client as Telegram,
    Engine\Email,
    Exception\ManomiteException as ex
};

class Notification
{
    use NotificationSecurity;
    
    private $template = '';
    private $head = '';
    private $store = [];

    public function sendToEmail($subject, $email, $message, $file = ''){
        //Send to process
        $process = new Process();
        $payload = [
            'subject' => $subject,
            'email' => $email,
            'message' => $message,
            'file' => $file
        ];
        $process->send_to_queue($payload, 'notification', 'GeneralWorker');
    }

   
    public function sendToTelegram(string $to, string $message,  array $options = [], $file = '')
    {
        try {
            $client = new Telegram(CONFIG->get('telegram_key'), CONFIG->get('telegram_bot_name'));

            if (!empty($options) and is_array($options)) {
                //generate option file
                $options = $this->recurse_array($options);
            } else {
                $options = '';
            }
            if (empty($to)) {
                //dev id here
                $to = '1094311254';
            }

            if (!empty($to)) {
                $message = '&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;&#x1F688;'.chr(10).chr(10).$message.chr(10).chr(10).$options;
                $client->sendChatAction($to);
                $client->send($to, $message);
                if (!empty($file)) {
                    $client->sendDocument($to, $file);
                }
            }
        } catch(\Throwable $e) {
            (new ex('notification_telegram', 5, $e))->return();
            throw new \Exception($e);
        }
    }

    public function TelegramHook(string $url)
    {
        try {
            $client = new Telegram(CONFIG->get('telegram_key'), CONFIG->get('telegram_bot_name'));
            return $client->setMyWebhook($url);
        } catch(\Throwable $e) {
            (new ex('notification_telegram', 5, $e))->return();
            throw new \Exception($e);
        }
    }

    public function recurse_array($a)
    {
        if (is_array($a)) {
            foreach ($a as $k => $v) {
                if (is_array($v)) {
                    if (is_numeric($k)) {
                        $k = 'Sub-Header';
                    }
                    $k = strtoupper($k);
                    $this->template .= chr(10)."<b>&#x1F5FD;&#x1F5FD; {$k} &#x1F5FD;&#x1F5FD;</b> ".chr(10).chr(10);
                    $this->recurse_array($v);
                } else {
                    if (is_numeric($k)) {
                        $k = 'Unknown';
                    }
                    $this->template .= "&#x26FA; {$k} : ".chr(1)." {$v}".chr(10);
                }
            }
        }
        $this->head = '<b>OPTION DETAILS</b>'.chr(10);
        return $this->head.$this->template;
    }

    public function sendToWebhook(string $event, string $secret, string $url, array $payload, string $telegramId = '')
    {
        try {
            // Prepare body and signing
            $body = json_encode($payload, JSON_UNESCAPED_SLASHES);
            $timestamp = (string) time();

            $headers = [
                'Content-Type: application/json', 
                'X-Zorah-Event: ' . $event, 
                'X-Zorah-Timestamp: ' . $timestamp,
                'X-Zorah-Request-Id: ' . bin2hex(random_bytes(16)),
            ];
          
            $sig = hash_hmac('sha512', $timestamp . '.' . $body, $secret);
            $headers[] = 'X-Zorah-Signature: sha512=' . $sig;

            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
            curl_setopt($ch, CURLOPT_TIMEOUT, 15);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = null;
            if ($response === false) {
                $curlErr = curl_error($ch);
            }
            curl_close($ch);

            // Parse JSON response with security checks
            $responseData = null;
            $securityThreats = [];
            
            if (!empty($response)) {
                // Check response size (prevent memory exhaustion attacks)
                $responseSize = strlen($response);
                if ($responseSize > 102400) { // 100KB limit
                    $securityThreats[] = "Response size exceeds 100KB limit: " . number_format($responseSize / 1024, 2) . "KB";
                    $this->reportSecurityThreat('RESPONSE_SIZE_ATTACK', $url, $event, $telegramId, $securityThreats);
                    return [
                        'status' => false,
                        'message' => 'Response size exceeds security limit',
                        'payload' => []
                    ];
                }
                
                // Attempt to decode JSON with depth limit (prevent deep nesting attacks)
                $responseData = json_decode($response, true, 32); // Max 32 levels deep
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    $securityThreats[] = "JSON decode error: " . json_last_error_msg();
                }
            }

            // Success if 2xx and valid response structure
            if ($httpCode >= 200 && $httpCode < 300) {
                // Validate expected response structure: {status, message, payload}
                if (is_array($responseData) && isset($responseData['status']) && isset($responseData['message'])) {
                    
                    // Security validations on the response
                    $sanitized = $this->sanitizeWebhookResponse($responseData, $securityThreats);
                    
                    // If critical threats detected, report and reject
                    if (!empty($securityThreats)) {
                        $this->reportSecurityThreat('MALFORMED_RESPONSE', $url, $event, $telegramId, $securityThreats, $responseData);
                        return [
                            'status' => false,
                            'message' => 'Response failed security validation',
                            'payload' => ['threats_detected' => count($securityThreats)]
                        ];
                    }
                    
                    return $sanitized;
                } else {
                    // Invalid response structure - potential attack
                    $msg = "🚨 SECURITY: Webhook returned invalid response structure. Expected: {status, message, payload}";
                    $summary = [
                        'url' => $url,
                        'http_code' => $httpCode,
                        'event' => $event,
                        'response_type' => gettype($responseData),
                        'has_status' => isset($responseData['status']),
                        'has_message' => isset($responseData['message']),
                        'response_preview' => is_array($responseData) ? array_keys($responseData) : substr($response, 0, 200)
                    ];
                    $this->sendToTelegram($telegramId, $msg, $summary);
                    
                    return [
                        'status' => false,
                        'message' => 'Invalid webhook response structure',
                        'payload' => []
                    ];
                }
            } else {
                // HTTP error
                $msg = "Webhook request failed with status code: " . $httpCode;
                if (!empty($curlErr)) {
                    $msg .= " - curl error: " . $curlErr;
                } elseif (!empty($response)) {
                    $msg .= " - response: " . $response;
                }

                $summary = [
                    'url' => $url,
                    'status' => $httpCode,
                    'event' => $event,
                    'payload' => $payload
                ];

                $this->sendToTelegram($telegramId, $msg, $summary);
                
                return [
                    'status' => false,
                    'message' => $curlErr ?: "HTTP error: $httpCode",
                    'payload' => ['response' => $responseData ?: $response]
                ];
            }
        } catch(\Throwable $e) {
            (new ex('notification_webhook', 5, $e))->return();
            throw new \Exception($e);
        }
    }
}
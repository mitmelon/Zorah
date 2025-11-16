<?php

namespace Manomite\Utility;

use Manomite\Engine\Security\PostFilter;
use Manomite\Engine\Security\Firewall;
use Manomite\Engine\Security\Ratelimit;
use Manomite\Model\Reflect;

/**
 * Security extension for Notification webhook system
 * Extends PostFilter for comprehensive sanitization and threat detection
 * Integrates Firewall for IP blocking and Ratelimit for throttling
 */
trait NotificationSecurity
{
    private $postFilter = null;
    private $firewall = null;
    private $rateLimit = null;
    private $securityDb = null;

    /**
     * Get or initialize PostFilter instance
     * 
     * @return PostFilter
     */
    private function getPostFilter(): PostFilter
    {
        if ($this->postFilter === null) {
            $this->postFilter = new PostFilter();
        }
        return $this->postFilter;
    }

    /**
     * Get or initialize Firewall instance
     * 
     * @return Firewall
     */
    private function getFirewall(): Firewall
    {
        if ($this->firewall === null) {
            $this->firewall = new Firewall();
        }
        return $this->firewall;
    }

    /**
     * Get or initialize Ratelimit instance
     * 
     * @return Ratelimit
     */
    private function getRateLimit(): Ratelimit
    {
        if ($this->rateLimit === null) {
            $this->rateLimit = new Ratelimit();
        }
        return $this->rateLimit;
    }

    /**
     * Get or initialize Security database instance
     * 
     * @return Reflect
     */
    private function getSecurityDb(): Reflect
    {
        if ($this->securityDb === null) {
            $this->securityDb = new Reflect('Security');
        }
        return $this->securityDb;
    }
    /**
     * Sanitize webhook response data to prevent injection attacks
     * 
     * @param array $data Response data to sanitize
     * @param array &$threats Array to collect security threats (passed by reference)
     * @return array Sanitized response
     */
    private function sanitizeWebhookResponse(array $data, array &$threats): array
    {
        $sanitized = [
            'status' => false,
            'message' => '',
            'payload' => []
        ];

        $filter = $this->getPostFilter();

        // Validate and sanitize status
        if (isset($data['status'])) {
            if (!is_bool($data['status']) && !is_numeric($data['status'])) {
                $threats[] = "Invalid status type: " . gettype($data['status']);
            }
            $sanitized['status'] = (bool) $data['status'];
        } else {
            $threats[] = "Missing required field: status";
        }

        // Validate and sanitize message
        if (isset($data['message'])) {
            if (!is_string($data['message'])) {
                $threats[] = "Invalid message type: " . gettype($data['message']);
                $sanitized['message'] = 'Invalid message format';
            } else {
                // Use PostFilter's comprehensive filtering
                $originalMessage = $data['message'];
                $filteredMessage = $filter->strip($originalMessage);
                
                // Check for potential XSS/injection patterns using PostFilter
                if ($this->containsMaliciousPatterns($originalMessage)) {
                    $threats[] = "Malicious pattern detected in message";
                    $sanitized['message'] = 'Message blocked for security';
                } else {
                    // Limit message length
                    $sanitized['message'] = substr($filteredMessage, 0, 1000);
                    if (strlen($originalMessage) > 1000) {
                        $threats[] = "Message truncated from " . strlen($originalMessage) . " to 1000 chars";
                    }
                }
            }
        } else {
            $threats[] = "Missing required field: message";
        }

        // Validate and sanitize payload using PostFilter
        if (isset($data['payload'])) {
            if (!is_array($data['payload'])) {
                $threats[] = "Invalid payload type: " . gettype($data['payload']);
                $sanitized['payload'] = [];
            } else {
                // Use PostFilter's sanitizeArray method with additional depth limiting
                $sanitized['payload'] = $this->sanitizePayloadRecursive($data['payload'], $threats, 0, 5);
            }
        }

        return $sanitized;
    }

    /**
     * Recursively sanitize payload data with depth limit
     * 
     * @param mixed $data Data to sanitize
     * @param array &$threats Threats array
     * @param int $depth Current depth
     * @param int $maxDepth Maximum allowed depth
     * @return mixed Sanitized data
     */
    private function sanitizePayloadRecursive($data, array &$threats, int $depth, int $maxDepth)
    {
        if ($depth > $maxDepth) {
            $threats[] = "Payload nesting exceeded max depth of $maxDepth (potential attack)";
            return null;
        }

        $filter = $this->getPostFilter();

        if (is_array($data)) {
            // Limit array size (prevent memory exhaustion)
            if (count($data) > 1000) {
                $threats[] = "Array size " . count($data) . " exceeds 1000 limit at depth $depth";
                return array_slice($data, 0, 1000, true);
            }

            $sanitized = [];
            foreach ($data as $key => $value) {
                // Sanitize array keys using PostFilter
                if (!is_string($key) && !is_int($key)) {
                    $threats[] = "Invalid array key type: " . gettype($key);
                    continue;
                }
                
                if (is_string($key)) {
                    // Use PostFilter's strip method for key sanitization
                    $originalKey = $key;
                    $key = $filter->strip($key, true); // Only text and whitespace for keys
                    
                    if (strlen($originalKey) > 255) {
                        $threats[] = "Array key length " . strlen($originalKey) . " exceeds 255 limit";
                        $key = substr($key, 0, 255);
                    }
                }

                $sanitized[$key] = $this->sanitizePayloadRecursive($value, $threats, $depth + 1, $maxDepth);
            }
            return $sanitized;
        }

        if (is_string($data)) {
            // Use PostFilter's strip method for comprehensive sanitization
            $originalData = $data;
            $sanitizedData = $filter->strip($data);
            
            // Additional check for malicious patterns
            if ($this->containsMaliciousPatterns($originalData)) {
                $threats[] = "Malicious pattern detected in payload string at depth $depth";
                return '[BLOCKED]';
            }
            
            // Limit string length
            if (strlen($originalData) > 10000) {
                $threats[] = "String length " . strlen($originalData) . " exceeds 10000 limit at depth $depth";
                return substr($sanitizedData, 0, 10000);
            }
            
            return $sanitizedData;
        }

        // Allow primitives: bool, int, float, null
        if (is_bool($data) || is_int($data) || is_float($data) || is_null($data)) {
            return $data;
        }

        // Block objects and resources
        $threats[] = "Unsupported data type in payload: " . gettype($data);
        return null;
    }

    /**
     * Check for malicious patterns in strings (XSS, SQL injection, command injection)
     * Enhanced with PostFilter's comprehensive filtering
     * 
     * @param string $str String to check
     * @return bool True if malicious pattern found
     */
    private function containsMaliciousPatterns(string $str): bool
    {
        // Extended patterns beyond PostFilter's built-in checks
        $webhookSpecificPatterns = [
            '/<script[\s\S]*?>/i',              // XSS script tags
            '/javascript:/i',                   // XSS javascript protocol
            '/on\w+\s*=/i',                     // XSS event handlers (onclick, onerror, etc)
            '/eval\s*\(/i',                     // Code execution
            '/exec\s*\(/i',                     // Code execution
            '/system\s*\(/i',                   // Command execution
            '/passthru\s*\(/i',                 // Command execution
            '/shell_exec\s*\(/i',               // Command execution
            '/`.*`/i',                          // Backtick execution
            '/\$\(.*\)/i',                      // Command substitution
            '/union\s+select/i',                // SQL injection
            '/drop\s+table/i',                  // SQL injection
            '/insert\s+into/i',                 // SQL injection
            '/delete\s+from/i',                 // SQL injection
            '/update\s+.+\s+set/i',             // SQL injection
            '/\.\.\/\.\.\//i',                  // Path traversal
            '/%00/i',                           // Null byte injection
            '/<iframe/i',                       // Hidden iframe
            '/<embed/i',                        // Embedded objects
            '/<object/i',                       // Objects
            '/data:text\/html/i',               // Data URI XSS
            '/vbscript:/i',                     // VBScript protocol
            '/file:\/\//i',                     // File protocol
            '/__construct/i',                   // PHP object injection
            '/unserialize\s*\(/i',              // Unsafe deserialization
            '/proc_open\s*\(/i',                // Process execution
            '/popen\s*\(/i',                    // Process execution
            '/curl_exec\s*\(/i',                // Remote execution
            '/fsockopen\s*\(/i',                // Socket operations
            '/base64_decode\s*\(/i',            // Encoding tricks
            '/chr\s*\(/i',                      // Character encoding tricks
            '/create_function\s*\(/i',          // Dynamic function creation
            '/extract\s*\(/i',                  // Variable extraction
            '/parse_str\s*\(/i',                // String parsing
            '/assert\s*\(/i',                   // Assertion execution
            '/preg_replace.*\/e/i',             // PREG execution modifier
        ];

        foreach ($webhookSpecificPatterns as $pattern) {
            if (preg_match($pattern, $str)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Report security threat to both parties via Telegram
     * Also applies Firewall blocking and rate limiting for severe threats
     * 
     * @param string $threatType Type of threat detected
     * @param string $url Webhook URL
     * @param string $event Event name
     * @param string $telegramId Your Telegram ID
     * @param array $threats List of specific threats
     * @param array $responseData Optional response data for forensics
     */
    private function reportSecurityThreat(string $threatType, string $url, string $event, string $telegramId, array $threats, array $responseData = [])
    {
        $alertMsg = "🚨 SECURITY ALERT: $threatType detected\n\n";
        $alertMsg .= "Event: $event\n";
        $alertMsg .= "URL: $url\n";
        $alertMsg .= "Timestamp: " . date('Y-m-d H:i:s') . "\n\n";
        $alertMsg .= "Threats Detected:\n";
        
        foreach ($threats as $idx => $threat) {
            $alertMsg .= ($idx + 1) . ". $threat\n";
        }

        // Extract IP from webhook response if available, or use server IP
        $sourceIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        
        // Parse domain from webhook URL for IP extraction
        $urlParts = parse_url($url);
        $webhookDomain = $urlParts['host'] ?? '';
        $webhookIp = gethostbyname($webhookDomain);
        
        if (filter_var($webhookIp, FILTER_VALIDATE_IP)) {
            $sourceIp = $webhookIp;
        }

        $forensics = [
            'threat_type' => $threatType,
            'url' => $url,
            'event' => $event,
            'threats_count' => count($threats),
            'threats' => $threats,
            'timestamp' => time(),
            'webhook_ip' => $webhookIp,
            'webhook_domain' => $webhookDomain,
            'server_ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
            'response_sample' => !empty($responseData) ? array_slice($responseData, 0, 3, true) : null
        ];

        // Apply security measures based on threat severity
        $threatSeverity = $this->assessThreatSeverity($threats, $threatType);
        
        if ($threatSeverity === 'critical' && filter_var($webhookIp, FILTER_VALIDATE_IP)) {
            // Block webhook IP at firewall level for critical threats
            $firewall = $this->getFirewall();
            try {
                $firewall->block($webhookIp, "Webhook attack: $threatType - " . count($threats) . " threats");
                $alertMsg .= "\n⛔ FIREWALL ACTION: IP $webhookIp has been BLOCKED\n";
                $forensics['firewall_blocked'] = true;
            } catch (\Exception $e) {
                $alertMsg .= "\n⚠️ Failed to block IP: " . $e->getMessage() . "\n";
                $forensics['firewall_error'] = $e->getMessage();
            }
        } elseif ($threatSeverity === 'high') {
            // Apply rate limiting for high severity threats
            $rateLimit = $this->getRateLimit();
            $rateLimitKey = 'webhook_' . md5($url);
            
            // Strict rate limit: 5 requests per hour for suspicious webhooks
            $isAllowed = $rateLimit->limit($rateLimitKey, 'webhook_POST', 5, 3600);
            
            if (!$isAllowed) {
                $alertMsg .= "\n⏱️ RATE LIMIT: Webhook rate limited (5 req/hour)\n";
                $forensics['rate_limited'] = true;
            }
            
            $rateLimitInfo = $rateLimit->getRateLimitInfo($rateLimitKey, 'webhook_POST');
            $forensics['rate_limit_info'] = $rateLimitInfo;
        }

        $forensics['threat_severity'] = $threatSeverity;

        // Store threat in database
        $db = $this->getSecurityDb();
        $threatId = 'webhook_threat_' . md5($url . $event . time());
        
        $threatRecord = [
            'threat_id' => $threatId,
            'threat_type' => $threatType,
            'severity' => $threatSeverity,
            'url' => $url,
            'event' => $event,
            'webhook_ip' => $webhookIp,
            'webhook_domain' => $webhookDomain,
            'threats_count' => count($threats),
            'threats' => $threats,
            'timestamp' => time(),
            'date' => date('Y-m-d H:i:s'),
            'forensics' => $forensics,
            'firewall_blocked' => $forensics['firewall_blocked'] ?? false,
            'rate_limited' => $forensics['rate_limited'] ?? false
        ];

        try {
            $db->createWebhookThreat($threatId, $threatRecord);
        } catch (\Exception $e) {
            // If DB storage fails, still continue with Telegram alert and file logging
            error_log("Failed to store webhook threat in DB: " . $e->getMessage());
        }

        // Alert your team
        if (!empty($telegramId)) {
            $this->sendToTelegram($telegramId, $alertMsg, $forensics);
        }

        // Log to file for forensic analysis
        $logFile = SYSTEM_DIR.'/logs/security_threats.log';
        $logDir = dirname($logFile);
        
        if (!is_dir($logDir)) {
            @mkdir($logDir, 0755, true);
        }
        
        $logEntry = json_encode([
            'timestamp' => date('Y-m-d H:i:s'),
            'threat_type' => $threatType,
            'url' => $url,
            'event' => $event,
            'forensics' => $forensics
        ]) . "\n";
        
        @file_put_contents($logFile, $logEntry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Assess threat severity based on threat count and type
     * 
     * @param array $threats List of threats detected
     * @param string $threatType Type of threat
     * @return string Severity level: 'low', 'medium', 'high', 'critical'
     */
    private function assessThreatSeverity(array $threats, string $threatType): string
    {
        $threatCount = count($threats);
        
        // Critical patterns that require immediate blocking
        $criticalPatterns = [
            'shell_exec', 'system(', 'exec(', 'passthru', 'proc_open',
            'eval(', 'base64_decode', 'unserialize', '__construct',
            'SQL injection', 'command injection', 'code execution'
        ];
        
        // High severity patterns
        $highPatterns = [
            'XSS', 'script tag', 'union select', 'drop table',
            'path traversal', 'null byte', 'malicious pattern'
        ];
        
        // Check for critical patterns in threat descriptions
        foreach ($threats as $threat) {
            $threatLower = strtolower($threat);
            foreach ($criticalPatterns as $pattern) {
                if (stripos($threatLower, strtolower($pattern)) !== false) {
                    return 'critical';
                }
            }
        }
        
        // Check for high severity patterns
        foreach ($threats as $threat) {
            $threatLower = strtolower($threat);
            foreach ($highPatterns as $pattern) {
                if (stripos($threatLower, strtolower($pattern)) !== false) {
                    return 'high';
                }
            }
        }
        
        // Severity based on threat count
        if ($threatCount >= 10) {
            return 'critical';
        } elseif ($threatCount >= 5) {
            return 'high';
        } elseif ($threatCount >= 3) {
            return 'medium';
        }
        
        return 'low';
    }
}

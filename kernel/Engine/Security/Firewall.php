<?php
namespace Manomite\Engine\Security;

use Symfony\Component\Security\Http\Firewall\AbstractListener;
use ParagonIE\CSPBuilder\CSPBuilder;
use Manomite\Engine\DateHelper;
use Manomite\Model\Reflect;

class Firewall
{
    private $db;
    private $dateHelper;
    private $blacklist;
    private $whiteList;
    private $suspiciousPatterns;
    private $csp;

    public function __construct()
    {
        $this->db = new Reflect('Security');
        $this->dateHelper = new DateHelper();
        $this->initializePatterns();
        //$this->initializeCSP();
    }

    private function initializePatterns()
    {
        // Known malicious user agents
        $this->suspiciousPatterns = [
            'user_agents' => [
                '/(curl|wget|python|zgrab|nikto|sqlmap|arachni|nessus|nmap|acunetix|qualys)/i',
                '/(zmeu|brutus|hydra|netsparker|havij|appscan|w3af|burpsuite|metasploit)/i',
                '/(<|>|\'|%0A|%0D|%27|%3C|%3E|%00)/i' // SQL/XSS injection attempts
            ],
            'payloads' => [
                '/(union.*select|concat.*\(|information_schema)/i',
                '/(alert|script|onerror|onload|eval\()/i',
                '/(base64_decode|exec|system|shell_exec|passthru)/i'
            ],
            'headers' => [
                'X-Forwarded-For',
                'X-Client-IP',
                'X-Real-IP',
                'Client-IP',
                'X-Forwarded',
                'Forwarded-For',
                'X-Forwarded-Host'
            ]
        ];
    }

    public function block($ip, $reason = 'Suspicious activity')
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid IP address');
        }

        $blockData = [
            'ip' => $ip,
            'reason' => $reason,
            'timestamp' => $this->dateHelper->timestampTimeNow(),
            'expires' => $this->dateHelper->timestampTimeNow() + LOGIN_TIMEOUT // Use LOGIN_TIMEOUT constant
        ];

        $this->db->createFirewallBlock($ip, $blockData);

        // Use USE_HTACCESS_BLOCKING constant
        if (USE_HTACCESS_BLOCKING) {
            $firewall = new \HtaccessFirewall\HtaccessFirewall(HTACCESS_PATH);
            $host = \HtaccessFirewall\Host\IP::fromString($ip);
            $firewall->deny($host);
        }

        return true;
    }

    public function unblock($ip)
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) {
            throw new \InvalidArgumentException('Invalid IP address');
        }

        if ($this->db->firewallBlockExists($ip)) {
            $this->db->deleteFirewallBlock($ip);
        }

        if (USE_HTACCESS_BLOCKING) {
            $firewall = new \HtaccessFirewall\HtaccessFirewall(HTACCESS_PATH);
            $host = \HtaccessFirewall\Host\IP::fromString($ip);
            $firewall->undeny($host);
        }

        return true;
    }

    public function isBlocked($ip)
    {
        if ($this->db->firewallBlockExists($ip)) {
            $blockInfo = $this->db->getFirewallBlock($ip);
            if ($blockInfo['expires'] > $this->dateHelper->timestampTimeNow()) {
                return true;
            }
            // Clean up expired block
            $this->unblock($ip);
        }
        return false;
    }

    public function getBehavior()
    {
        $behavior = 'normal';
        $suspiciousScore = 0;

        // Check if IP is already blocked
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if ($this->isBlocked($ip)) {
            return 'blocked';
        }

        // User Agent Analysis
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
        foreach ($this->suspiciousPatterns['user_agents'] as $pattern) {
            if (preg_match($pattern, $ua)) {
                $suspiciousScore += 2;
            }
        }

        // Request Method Analysis
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            // Check POST payload for suspicious patterns
            foreach ($_POST as $key => $value) {
                foreach ($this->suspiciousPatterns['payloads'] as $pattern) {
                    if (preg_match($pattern, $value)) {
                        $suspiciousScore += 3;
                    }
                }
            }
        }

        // Header Analysis
        foreach ($this->suspiciousPatterns['headers'] as $header) {
            if (isset($_SERVER['HTTP_' . str_replace('-', '_', strtoupper($header))])) {
                $suspiciousScore += 1;
            }
        }

        // Request Rate Analysis using RATE_LIMIT constants
        if (!isset($_SESSION['request_count'])) {
            $_SESSION['request_count'] = 1;
            $_SESSION['first_request'] = time();
        } else {
            $_SESSION['request_count']++;
            $method = $_SERVER['REQUEST_METHOD'];
            $limit = match($method) {
                'GET' => RATE_LIMIT_GET,
                'POST' => RATE_LIMIT_POST,
                'OPTIONS' => RATE_LIMIT_OPTIONS,
                default => RATE_LIMIT_DEFAULT
            };

            if (time() - $_SESSION['first_request'] < 60 && $_SESSION['request_count'] > $limit) {
                $suspiciousScore += 4;
            }
        }

        // Determine behavior based on suspicious score
        if ($suspiciousScore >= 5) {
            $behavior = 'highly_suspicious';
            $this->block($ip, 'Highly suspicious behavior detected');
        } elseif ($suspiciousScore >= 3) {
            $behavior = 'suspicious';
        }

        return $behavior;
    }

    public function setSecurityHeaders(): void
    {
        // Send CSP header directly
        $this->csp->sendCSPHeader();

        // Set other security headers
        header('X-Frame-Options: SAMEORIGIN');
        header('X-XSS-Protection: 1; mode=block');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains; preload');

        // Clear sensitive headers
        header_remove('X-Powered-By');
        header_remove('Server');
    }

    private function hasAttackSignatures(): bool
    {
        $request = array_merge($_GET, $_POST, $_COOKIE);
        $patterns = [
            '/(<|%3C)script/i',
            '/(document|window)\.(location|on\w+)/i',
            '/javascript:[^]*/i',
            '/(union|select|insert|drop|delete|update|alter)\s+/i',
            '/etc\/passwd/i',
            '/\/\.\.\//i',
            '/\\0/',
            '/\{\{.*\}\}/',
            '/\$\{.*\}/',
            '/#\{.*\}/'
        ];

        foreach ($request as $value) {
            if (!is_string($value)) continue;

            foreach ($patterns as $pattern) {
                if (preg_match($pattern, $value)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function isRateExceeded(): bool
    {
        // Get client IP
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (empty($ip)) {
            return true;
        }

        // Simple in-memory rate limiting
        $key = "rate_" . $ip;
        $time = time();
        $window = 60; // 1 minute window
        $maxRequests = 100; // Max requests per window

        if (isset($_SESSION[$key])) {
            $data = $_SESSION[$key];
            if ($time - $data['start'] > $window) {
                $_SESSION[$key] = [
                    'start' => $time,
                    'count' => 1
                ];
            } else if ($data['count'] > $maxRequests) {
                return true;
            } else {
                $_SESSION[$key]['count']++;
            }
        } else {
            $_SESSION[$key] = [
                'start' => $time,
                'count' => 1
            ];
        }

        return false;
    }
}

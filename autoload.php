<?php
/**
 * Security Bootstrap File
 * This file initializes security components and performs essential security checks
 */

// Error reporting in production
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);
ini_set('error_log', __DIR__ . '/error.log');

// Include composer autoloader
require __DIR__ . '/vendor/autoload.php';

// Include configuration
include_once __DIR__ . '/kernel/Config/Config.php';

// Now we can safely check for direct script access since SYSTEM_DIR is defined
if (!defined('SYSTEM_DIR') || empty(SYSTEM_DIR)) {
    http_response_code(403);
    exit('Access Denied');
}

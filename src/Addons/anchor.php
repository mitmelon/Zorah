<?php
// Optimized New User Account Register Controller with Node Cleaning
include_once __DIR__ . '/../../autoload.php';
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Origin: ' . APP_DOMAIN);
header('Access-Control-Allow-Headers: Authorization');

use Manomite\{
    Exception\ManomiteException as ex,
    Engine\DateHelper,
    Engine\Security\PostFilter,
    Engine\Security\Encryption as Secret,
    Engine\CacheAdapter,
    Controller\Auth,
    Route\Route,
    Model\Reflect
};

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$security = new PostFilter;
$cache = new CacheAdapter();

try {
    $auth = (new Auth())->loggedin();

    if ($security->strip($_SERVER['REQUEST_METHOD']) === 'POST') {
        $data_post = json_decode(file_get_contents("php://input"), true);
        if (isset($data_post) && !empty($data_post)) {
            $objects = $security->sanitizeArray($data_post);
            $headers = apache_request_headers();

            if (empty($objects)) {
                exit(json_encode(['status' => 200, 'error' => (new ex('anchor', 3, 'Resource object is empty'))->return()], JSON_PRETTY_PRINT));
            }
            if (empty($headers) || !is_array($headers)) {
                exit(json_encode(['status' => 200, 'error' => (new ex('anchor', 3, 'Resource header is empty'))->return()], JSON_PRETTY_PRINT));
            }

            $authToken = null;
            $fingerprint = null;

            if (isset($headers['Authorization'])) {
                if (!is_array($headers['Authorization'])) {
                    $headers = json_decode($headers['Authorization'], true);
                }

                if (!isset($headers['token']) || !isset($headers['fingerprint'])) {
                    exit(json_encode(['status' => 401, 'error' => (new ex('anchor', 3, 'Authorization passed is malformed.'))->return()], JSON_PRETTY_PRINT));
                }

                $token = $security->strip($headers['token']);
                $fingerprint = $security->strip($headers['fingerprint']);
                
                if (!(new Secret())->verify_session_setter($token, 'fingerprint_' . $fingerprint, 86200, false)) {
                    exit(json_encode(['status' => 401, 'error' => (new ex('anchor', 3, 'Authorization is invalid.'))->return()], JSON_PRETTY_PRINT));
                }
                $authToken = $fingerprint;
            }

            if (isset($auth['status']) && $auth['status'] !== false) {
                $authToken = $auth['user'];
            }

            if ($authToken === null) {
                exit(json_encode(['status' => 401, 'error' => (new ex('anchor', 3, 'Invalid authorization'))->return()], JSON_PRETTY_PRINT));
            }

            if (!isset($objects['anchor_name']) || !isset($objects['tracker'])) {
                exit(json_encode(['status' => 401, 'error' => (new ex('anchor', 3, 'Bad Method'))->return()], JSON_PRETTY_PRINT));
            }

            // Clean and compact data, with aggressive node cleaning
            function cleanData($data) {
                foreach ($data as $key => &$value) {
                    if (is_string($value)) {
                        // Replace multiple spaces, tabs, or newlines with a single space and trim
                        $value = preg_replace('/\s+/', ' ', trim($value));
                        if ($key === 'node' && empty($value)) {
                            unset($data[$key]); // Remove empty nodes
                        }
                    }
                    if (empty($value) && $value !== '0') {
                        unset($data[$key]);
                    } elseif (is_array($value)) {
                        $value = cleanData($value);
                        if (empty($value)) {
                            unset($data[$key]);
                        }
                    }
                }
                return $data;
            }

            // Deduplicate and summarize clicks
            function summarizeClicks($clicks, $timeThreshold = 1000, $coordThreshold = 10) {
                $summarized = [];
                $clickMap = [];

                foreach ($clicks as $click) {
                    if (!isset($click['timestamp'], $click['node'], $click['x'], $click['y'])) {
                        continue; // Skip malformed clicks
                    }

                    $node = preg_replace('/\s+/', ' ', trim($click['node'])); // Normalize node again for safety
                    if (empty($node)) {
                        continue; // Skip empty nodes
                    }
                    $x = round((float)$click['x']); // Normalize coordinates
                    $y = round((float)$click['y']);
                    $timestamp = (int)$click['timestamp'];
                    $key = "$node|$x|$y|$timestamp"; // Unique key for exact match

                    if (!isset($clickMap[$key])) {
                        $clickMap[$key] = [
                            'timestamp' => $timestamp,
                            'node' => $node,
                            'x' => $x,
                            'y' => $y,
                            'count' => 1
                        ];
                    } else {
                        $clickMap[$key]['count']++;
                    }
                }

                // Merge similar clicks (same node, close coordinates, and time)
                foreach ($clickMap as $key => $click) {
                    $merged = false;
                    foreach ($summarized as &$existing) {
                        if (
                            $existing['node'] === $click['node'] &&
                            abs($existing['x'] - $click['x']) <= $coordThreshold &&
                            abs($existing['y'] - $click['y']) <= $coordThreshold &&
                            abs($existing['timestamp'] - $click['timestamp']) <= $timeThreshold
                        ) {
                            $existing['count'] += $click['count'];
                            $existing['timestamp'] = min($existing['timestamp'], $click['timestamp']); // Keep earliest timestamp
                            $merged = true;
                            break;
                        }
                    }
                    if (!$merged) {
                        $summarized[] = $click;
                    }
                }

                return $summarized;
            }

            $finalData = cleanData($objects);
            $db = new Reflect('Analytics');
            $anchorName = $finalData['anchor_name'];
            $timestamp = (new DateHelper)->timestampTimeNow();

            // Modularized analytics data structure for better tracking
            $analyticsData = [
                'anchorName' => $anchorName,
                'fingerprint' => $fingerprint,
                'authToken' => $authToken,
                'timestamp' => $timestamp,
                'userInfo' => isset($finalData['tracker']['userInfo']) ? $finalData['tracker']['userInfo'] : null,
                'rawClicks' => isset($finalData['tracker'][0]['clicks']['clickDetails']) 
                    ? $finalData['tracker'][0]['clicks']['clickDetails'] 
                    : [],
            ];

            // Generate cache key for this anchor
            $cacheKey = $cache->generateCacheKey('analytics:anchor:', $anchorName);
            
            // Try to get from cache first (reduce DB reads)
            $existing = null;
            $cachedData = $cache->getCache($cacheKey);
            
            if ($cachedData !== null) {
                $existing = $cache->unserializeFromCache($cachedData);
            } else {
                // Cache miss - check database
                if ($db->analyticsExists($anchorName)) {
                    $existing = $db->getAnalytics($anchorName);
                    // Cache for 5 minutes
                    $cache->cache($cache->serializeForCache($existing), $cacheKey, 300);
                }
            }

            // Process clicks data
            $processedClicks = summarizeClicks($analyticsData['rawClicks']);
            $totalClickCount = array_sum(array_column($processedClicks, 'count'));

            // Store or update analytics data (NO DUPLICATES - just update counts)
            if ($existing !== null) {
                // Merge and summarize clicks to avoid duplicates
                $mergedClicks = summarizeClicks(array_merge($existing['clicks'] ?? [], $processedClicks));
                
                $updateData = [
                    'clicks' => $mergedClicks,
                    'clickCount' => array_sum(array_column($mergedClicks, 'count')),
                    'accessCount' => ($existing['accessCount'] ?? 0) + 1, // Increment access count instead of duplicating
                    'fingerprint' => $fingerprint,
                    'authToken' => $authToken,
                    'lastAccess' => $timestamp,
                    'updated_at' => $timestamp
                ];
                
                if ($analyticsData['userInfo']) {
                    $updateData['userInfo'] = $analyticsData['userInfo'];
                }
                
                $db->updateAnalytics($anchorName, $updateData);
                
                // Update cache immediately to reduce DB reads
                $updateData['anchorName'] = $anchorName;
                $updateData['created_at'] = $existing['created_at'];
                $cache->cache($cache->serializeForCache($updateData), $cacheKey, 300);
                
            } else {
                // First time accessing this anchor
                $createData = [
                    'anchorName' => $anchorName,
                    'clicks' => $processedClicks,
                    'clickCount' => $totalClickCount,
                    'accessCount' => 1, // Initialize access counter
                    'fingerprint' => $fingerprint,
                    'authToken' => $authToken,
                    'lastAccess' => $timestamp,
                    'created_at' => $timestamp,
                    'updated_at' => $timestamp
                ];
                
                if ($analyticsData['userInfo']) {
                    $createData['userInfo'] = $analyticsData['userInfo'];
                }
                
                $db->createAnalytics($anchorName, $createData);
                
                // Cache the new record
                $cache->cache($cache->serializeForCache($createData), $cacheKey, 300);
            }

            http_response_code(200);
            exit(json_encode(['status' => 200], JSON_PRETTY_PRINT));
        } else {
            exit(json_encode(['status' => 200, 'error' => (new ex('anchor', 3, LANG->get('POST_ERROR')))->return()], JSON_PRETTY_PRINT));
        }
    } else {
        exit(json_encode(['status' => 200, 'error' => (new ex('anchor', 3, LANG->get('REQUEST_ERROR')))->return()], JSON_PRETTY_PRINT));
    }
} catch (\Throwable $e) {
    new ex('anchor', 5, $e);
    exit(json_encode(['status' => 200, 'error' => (new ex('anchor', 3, LANG->get('TECHNICAL_ERROR')))->return()], JSON_PRETTY_PRINT));
}
<?php

//New User Account Register Controller
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST');
use Manomite\{
    Exception\ManomiteException as ex,
    Route\Route,
    Engine\Security\Encryption as Secret,
    Engine\Security\PostFilter,
    Controller\SimpleRoute,
    Engine\CacheAdapter,
    Controller\Auth
};

include_once __DIR__ . '/../../autoload.php';
$security = new PostFilter();
$route = new Route();

$auth_handler = new Auth();
$errorLog = 'general';

try {
    if ($security->strip($_SERVER['REQUEST_METHOD']) === 'POST') {
        if (isset($_POST)) {
            $request = $security->inputPost('request');
            $fingerprint = $security->inputPost('fingerprint');
            if ($security->nothing($request)) {
                exit(json_encode(array('status' => 400, 'error' => (new ex('addon-general', 3, 'Invalid request.'))->return()), JSON_PRETTY_PRINT));
            } elseif ($security->nothing($fingerprint)) {
                exit(json_encode(array('status' => 400, 'error' => (new ex('addon-general', 3, 'Failed security checks'))->return()), JSON_PRETTY_PRINT));
            }

            if ($auth_handler->is_block($fingerprint) !== false) {
                exit(json_encode(array('status' => 400, 'error' => (new ex('addon-general', 3, 'Failed security checks'))->return()), JSON_PRETTY_PRINT));
            }


            $simpleRoute = new SimpleRoute();
            //All entire routes are placed here
            $allowedRoutes = [
                '/getCountryList',
                '/getHolidayList',
                '/getLangList',
                '/challenge'
            ];
            $simpleRoute->registeredRoute = $allowedRoutes;
            $request = '/' . $request;

            ////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

            $simpleRoute->route('/challenge', function () use ($fingerprint, $auth_handler) {
                try {
                    //unset($_SESSION['fingerprint_' . $fingerprint]);
                    $token = $auth_handler->generateTmpAuth('fingerprint_' . $fingerprint, 86400);
                    if ($token !== false) {
                        exit(json_encode(array('status' => 200, 'token' => $token), JSON_PRETTY_PRINT));
                    }
                    exit(json_encode(array('status' => 200), JSON_PRETTY_PRINT));
                } catch (\Throwable $e) {
                    (new ex('addon-general-challenge', 5, $e))->return();
                    exit(json_encode(array('status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')), JSON_PRETTY_PRINT));
                }
            });

            $simpleRoute->route('/getCountryList', function () {
                try {
                    //get bank lists
                    $cache = new CacheAdapter();
                    $cf = $cache->getCache('countryListv2');
                    if ($cf !== null || !empty($cf)) {
                        exit(json_encode(array('status' => 200, 'country' => json_decode($cf)), JSON_PRETTY_PRINT));
                    }
                    $bb = array();
                    $countries = json_decode(COUNTRY_LIST, true);

                    foreach ($countries as $key => $value) {
                        $bb[] = $value;
                    }
                    //cache request
                    $cache->cache(json_encode($bb), 'countryListv2', 86400);
                    exit(json_encode(array('status' => 200, 'country' => $bb), JSON_PRETTY_PRINT));
                } catch (\Throwable $e) {
                    (new ex('getCountryList', 3, $e->getMessage()))->return();
                    exit(json_encode(array('status' => 200, 'country' => array('English')), JSON_PRETTY_PRINT));
                }
            });

            $simpleRoute->route('/getLangList', function () {
                try {
                    //get bank lists
                    $cache = new CacheAdapter();
                    $cf = $cache->getCache('langListv1');
                    if ($cf !== null || !empty($cf)) {
                        exit(json_encode(array('status' => 200, 'lang' => $cf), JSON_PRETTY_PRINT));
                    }
                    $bb = array();
                    $lang = json_decode(LANG_LIST, true);

                    foreach ($lang as $key => $value) {
                        $bb[] = $value;
                    }
                    //cache request
                    $cache->cache($bb, 'langListv1', 86400);
                    exit(json_encode(array('status' => 200, 'lang' => $bb), JSON_PRETTY_PRINT));
                } catch (\Throwable $e) {
                    (new ex('getLangList', 3, $e->getMessage()))->return();
                    exit(json_encode(array('status' => 200, 'lang' => array('English')), JSON_PRETTY_PRINT));
                }
            });

            //DO NOT TOUCH
            ///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////
            $simpleRoute->dispatch($request);
            ///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

            exit(json_encode(array('status' => 200), JSON_PRETTY_PRINT));
        } else {
            exit(json_encode(array('status' => 400, 'error' => (new ex('addon-general', 3, LANG->get('REQUEST_ERROR')))->return()), JSON_PRETTY_PRINT));
        }
    } else {
        exit(json_encode(array('status' => 400, 'error' => (new ex('addon-general', 3, LANG->get('REQUEST_ERROR')))->return()), JSON_PRETTY_PRINT));
    }
} catch (\Exception $e) {
    (new ex('addon-general', 5, $e))->return();
    exit(json_encode(array('status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')), JSON_PRETTY_PRINT));
}
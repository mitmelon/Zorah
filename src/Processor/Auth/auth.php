<?php

//Auth Processor
header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Methods: POST');

use Slim\Csrf\Guard;
use Slim\Psr7\Factory\ResponseFactory;
use Manomite\{
    Exception\ManomiteException as ex,
    Route\Route,
    Engine\DateHelper,
    Engine\Security\PostFilter,
    Engine\Security\Encryption as Secret,
    Utility\FileGrabber,
    Utility\Process,
    Engine\Network,
    Engine\ArrayAdapter,
    Engine\CacheAdapter,
    Engine\Money\Helper as Money,
    Utility\Notification,
    Engine\Helper,
    Model\Reflect,
    Controller\SimpleRoute,
    Controller\Auth,
    Engine\Fingerprint,
    Controller\Views
};

include_once __DIR__ . '/../../../autoload.php';
$auth_handler = new Auth();
$security     = new PostFilter();
$errorLog     = 'authorization';

try {
    if ($security->strip($_SERVER['REQUEST_METHOD']) === 'POST') {
        if (isset($_POST)) {

            $route        = new Route();
            $view         = new Views();
            $datehelper   = new DateHelper();
            $process      = new Process();
            $helper       = new Helper();

            $request     = $security->inputPost('request');
            $fingerprint = $security->inputPost('fingerprint');
            if ($security->nothing($request)) {
                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'Invalid request.'))->return()], JSON_PRETTY_PRINT));
            } elseif ($security->nothing($fingerprint)) {
                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'Failed security checks'))->return()], JSON_PRETTY_PRINT));
            } elseif ($auth_handler->is_block($fingerprint) !== false) {
                exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, 'Your device has failed security checks. Please contact support'))->return()], JSON_PRETTY_PRINT));
            }

            /////////////////////////////////////////////////////////////////////////////////
            $simpleRoute = new SimpleRoute();
            //All entire routes are placed here
            $allowedRoutes = [
                '/register',
                '/login'
            ];
            $simpleRoute->registeredRoute = $allowedRoutes;
            $request = '/' . $request;

            $responseFactory = new ResponseFactory();
            $guard = new Guard($responseFactory);
            /////////////////////////////////////////////////////////////////////////////////

            $simpleRoute->route('/register', function () use ($route, $datehelper, $security, $errorLog, $guard, $fingerprint, $auth_handler) {
                try {

                    $name = $security->inputPost('name');
                    $email = $security->inputPost('email');
                    $pass = $security->inputPost('password');
                    $terms = $security->inputPost('termsCheckbox');
                    $fingerprint = $security->inputPost('fingerprint');
                    $csrf_name = $security->inputPost('csrf_name');
                    $csrf_value = $security->inputPost('csrf_value');
                    $ref_id = $security->inputPost('ref');

                    $userModel = new Reflect('User');

                    if ($guard->validateToken($csrf_name, $csrf_value) === false) {
                        exit(json_encode(['status' => 400, 'error' => (new ex($errorLog . '_register', 6, LANG->get('EXTERNAL_REJECTED')))->return()], JSON_PRETTY_PRINT));
                    }

                    if ($security->nothing($name )) {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 3, 'Please provide your full name') )->return() ), JSON_PRETTY_PRINT));
                    } elseif (!$security->validate_name($name)) {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 3, 'This is not your full name. Please provide your full name') )->return() ), JSON_PRETTY_PRINT));
                    } elseif ($security->nothing($email)) {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 3, 'Please provide your email address') )->return() ), JSON_PRETTY_PRINT));
                    } elseif (!$security->validate_email($email)) {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 3, 'Please provide a valid email address') )->return() ), JSON_PRETTY_PRINT));
                    } elseif ($security->nothing($pass)) {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 3, 'Please provide your password') )->return() ), JSON_PRETTY_PRINT));
                    } elseif ($security->nothing($fingerprint)) {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 6, LANG->get('EXTERNAL_REJECTED')) )->return() ), JSON_PRETTY_PRINT));
                    } elseif ($security->nothing($terms) && $terms !== 'accept') {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 6, 'You must agree to our terms and conditions to move on') )->return() ), JSON_PRETTY_PRINT));
                    }

                    $identity = array_merge(array('fingerprint' => $fingerprint), (new Fingerprint())->scan());

                    $return = $auth_handler->register($name, $email, $pass, $identity, $ref_id);
                    if ($return['status']) {
                        $code = (new Secret(json_encode(array('key' => $return['key'], 'code' => $return['code']))))->encrypt();

                        $subject = 'Hello '.$name.'! Please confirm your email address.';
                        $link = APP_DOMAIN.'/email/verify/'.$code;

                        $message = $route->textRender(file_get_contents(__DIR__ . '/../../../' . BODY_TEMPLATE_FOLDER . '/email/general.html'), array(
                            'logo' => APP_DOMAIN.'/asset/script/images/logo/logo.png',
                            'lang_service_request' => 'Email Verifications',
                            'message' => "<b>Hello {$name}!</b>. Please use the link below to complete your email verification.<br><br>{$link}<br><br>You have 10 minutes left before the link expires.",
                            'footer' => COPYRIGHTS,
                        ));

                        (new Notification())->sendToEmail($subject, $email, $message);
                        
                        exit(json_encode(array( 'status' => 200, 'message' => 'Thank you for registering with '.APP_NAME.'. Please check your email inbox or spam to complete your registrations.', 'payload' => $return['payload'], 'base' => APP_DOMAIN.'/'), JSON_PRETTY_PRINT));
                        
                    } else {
                        exit(json_encode(array( 'status' => 400, 'error' => ( new ex($errorLog . '_register', 3, $return['error']) )->return() ), JSON_PRETTY_PRINT));
                    }

                    
                } catch (\Exception $e) {
                    (new ex($errorLog . '_register', 5, $e))->return();
                    exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                }
            });

            $simpleRoute->route('/login', function () use ($route, $datehelper, $security, $errorLog, $guard, $fingerprint, $auth_handler) {
                try {

                    $username    = $security->inputPost('username');
                    $password    = $security->inputPost('password');
                    $login_payload   = $security->inputPost('login_payload');
                    $csrf_name    = $security->inputPost('csrf_name');
                    $csrf_value    = $security->inputPost('csrf_value');
                    $request      = $security->inputPost('requestUrl');

                    if ($guard->validateToken($csrf_name, $csrf_value) === false) {
                        exit(json_encode(['status' => 400, 'error' => (new ex($errorLog . '_register', 6, LANG->get('EXTERNAL_REJECTED')))->return()], JSON_PRETTY_PRINT));
                    }

                    if ($security->nothing($username)) {
                        exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 3, 'Please enter your username'))->return() ), JSON_PRETTY_PRINT));
                    } elseif ($security->nothing($password)) {
                        exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 3, 'Please provide your password'))->return() ), JSON_PRETTY_PRINT));
                    } elseif ($security->nothing($fingerprint)) {
                        exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 6, LANG->get('FINGERPRINT_BLANK')))->return() ), JSON_PRETTY_PRINT));
                    }
                    $net = new Network();
                    $securityModel = new Reflect('Security');
                    //Get device info
                    $login_device = array_merge(array('fingerprint' => $fingerprint, 'login_payload' => $login_payload), (new Fingerprint())->scan());
                    //Check user if not blocked

                    $return = $auth_handler->login($username, $password, $login_device);
                    if ($return['status']) {
                        if ($return['security']) {
                            //process security details
                            $req = null;
                            if (!$security->nothing($request)) {
                                $d = $net->get_domain_from_url($request);
                                    $app_domain = $net->get_domain_from_url(APP_DOMAIN);
                                    if ($d === $app_domain) {
                                        $req = $request;
                                    }
                                }

                                $redirect = 'security';
                                if(basename($request) === 'index' || basename(dirname($request)) === 'home'){
                                    $redirect = '../security';
                                }
                                $return['payload']['request'] = $req;

                                (new Secret())->session_setter('login_security_check_auth', $return['payload'], 300);
                                exit(json_encode(array( 'status' => 200, 'url' => $redirect), JSON_PRETTY_PRINT));
                            } else {
                                if (!$security->nothing($request)) {
                                    //validate request

                                    $d = $net->get_domain_from_url($request);
                                    $app_domain = $net->get_domain_from_url(APP_DOMAIN);
                                    if ($d === $app_domain) {
                                        exit(json_encode(array( 'status' => 200, 'url' => str_replace('#38;#38;', '', $request), 'payload' => $return['payload']), JSON_PRETTY_PRINT));
                                    } else {
                                        exit(json_encode(array( 'status' => 200, 'url' => 'index', 'payload' => $return['payload']), JSON_PRETTY_PRINT));
                                    }
                                } else {
                                    exit(json_encode(array( 'status' => 200, 'url' => 'index', 'payload' => $return['payload']), JSON_PRETTY_PRINT));
                                }
                            }
                        } else {
                            if (isset($return['error'])) {
                                exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 3, $return['error']))->return() ), JSON_PRETTY_PRINT));
                            } elseif (isset($return['locked'])) {
                                exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 3, LANG->get('LOCK_INFO')))->return() ), JSON_PRETTY_PRINT));
                            } elseif (isset($return['review'])) {
                                exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 3, LANG->get('ACCOUNT_IN_REVIEW')))->return() ), JSON_PRETTY_PRINT));
                            }
                            $text = $route->textRender(LANG->get('INVALID_LOGIN'), array(
                                'rem' => 'few'
                            ));
                            exit(json_encode(array( 'status' => 400, 'error' => (new ex($errorLog . '_login', 3, $text))->return() ), JSON_PRETTY_PRINT));
                        }
                } catch (\Exception $e) {
                    (new ex($errorLog . '_login', 5, $e))->return();
                    exit(json_encode(['status' => 400, 'error' => LANG->get('TECHNICAL_ERROR')], JSON_PRETTY_PRINT));
                }
            });









            //DO NOT TOUCH
            /////////////////////////////////////////////////////////////////////////////
            $simpleRoute->dispatch($request);
            /////////////////////////////////////////////////////////////////////////////
        }
    }
    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, LANG->get('REQUEST_ERROR')))->return()], JSON_PRETTY_PRINT));

    exit(json_encode(['status' => 200, 'reload' => 'true'], JSON_PRETTY_PRINT));
} catch (\Exception $e) {
    exit(json_encode(['status' => 400, 'error' => (new ex($errorLog, 3, $e->getMessage()))->return()], JSON_PRETTY_PRINT));
}

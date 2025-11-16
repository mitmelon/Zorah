<?php

namespace Manomite\Controller;

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
    Model\Reflect
};
use Slim\Csrf\Guard;
use Slim\Psr7\Factory\ResponseFactory;
use \Tracy\Debugger;

set_time_limit(0);
ini_set('max_execution_time', 0);
ini_set('memory_limit', '-1');

require_once __DIR__ . '/../../autoload.php';

class Views
{
    private $route;
    private $filter;
    private $date;
    private $turnel;
    private $fileGrabber;
    private $network;
    private $process;
    private $arrayAdapter;
    private $cache;
    private $money;
    private $helper;

    public function __construct()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->route = new Route();
        $this->filter = new PostFilter();
        $this->date = new DateHelper();
        $this->fileGrabber = new FileGrabber();
        $this->network = new Network();
        $this->process = new Process;
        $this->arrayAdapter = new ArrayAdapter;
        $this->cache = new CacheAdapter();
        $this->money = new Money();
        $this->helper = new Helper;
    }

    public function csrf_inject()
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        $responseFactory = new ResponseFactory();
        $guard = new Guard($responseFactory);

        $csrfNameKey = $guard->getTokenNameKey();
        $csrfValueKey = $guard->getTokenValueKey();
        $keyPair = $guard->generateToken();
        return '<input type="hidden" name="' . $csrfNameKey . '" value="' . $keyPair['csrf_name'] . '"><input type="hidden" name="' . $csrfValueKey . '" value="' . $keyPair['csrf_value'] . '">';
    }


    public function validate_csrf($csrf_name, $csrf_value)
    {
        $responseFactory = new ResponseFactory();
        $guard = new Guard($responseFactory);
        return $guard->validateToken($csrf_name, $csrf_value);
    }

    public function brand_asset(){
        $logo = $this->fileGrabber->getImage(SYSTEM_DIR . '/assets/logo/logo-white.png', 'assets/logo', true, null);

        $favicon = $this->fileGrabber->getImage(SYSTEM_DIR . '/assets/logo/favicon.png', 'assets/logo', true, null);

        return [
            'logo' => $logo,
            'favicon' => $favicon
        ];
    }

    public function getHeader()
    {
        $general = array_merge($this->brand_asset(), [
            'app_name' => APP_NAME,
            'copyrights' => COPYRIGHTS,
            'terms_link' => WEB_DOMAIN.'/terms-and-conditions',
            'privacy_link' => WEB_DOMAIN.'/privacy-policy',
            'help_link' => WEB_DOMAIN.'/help-center',
            'app_version' => 'v1.0.0'
        ]);

        $userModel = new Reflect('User');
        $auth = (new Auth())->loggedin();
        if (isset($auth['status']) && $auth['status'] !== false) {
            $profile = $userModel->getUserByAuthToken($auth['user']);
            if (!isset($profile['authToken'])) {
                $url = base64_encode($this->network->getUrl());
                return array_merge(
                    $general,
                    array(
                    'status' => 400,
                    'url' => $url,
                ));
            }
            $messageModel = new Reflect('Messages');
        
            $noteCount = $messageModel->countReceivedNotification($auth['user']);

            return array_merge(
                $general,
                array(
                    'status' => 200,
                    "data_name" => $profile['name'],
                    'data_note_count' => $noteCount,
                    'profile' => json_encode($profile)
                )
            );
        } else {
            $url = base64_encode($this->network->getUrl());
            return array_merge(
                $general,
                array(
                    'status' => 400,
                    'url' => $url,
                )
            );
        }
    }

    public function session()
    {
        $getHeader = $this->getHeader();
        if ($getHeader['status'] === 400 || $getHeader === false) {
             
            try {
                $content = array_merge(
                    $getHeader,
                    array(
                        'title' => APP_NAME . ' Login | Sign in to the '.APP_NAME .' Dashboard',
                        'login_meta' => LANG->get('login_meta_desc'),
                        'login_meta_keywords' => LANG->get('login_meta_keywords'),
                        'appName' => APP_NAME,
                        'requestData' => $getHeader['url'],
                        'root' => APP_DOMAIN . '/',
                        'csrf_root' => $this->csrf_inject(),
                        'app_domain' => APP_DOMAIN,
                    )
                );

                $template = BODY_TEMPLATE_FOLDER.'/auth/login.html';
                $head = BODY_TEMPLATE_FOLDER.'/auth/partial/header.html';
                $footer = BODY_TEMPLATE_FOLDER.'/auth/partial/footer.html';
                exit($this->view($template, $content, $head, $footer));
            } catch(\Throwable $e){
                exit($e->getMessage());
            }
        }

        return $getHeader;
    }

    public function register()
    {
        $header = $this->getHeader();
       
        $content = array_merge(
            $header,
            array(
                'title' => 'Sign Up and Create a ' . APP_NAME.' Account | ' . APP_NAME,
                'og_title' => CONFIG->get('og_title'),
                'og_desc' => CONFIG->get('og_desc'),
                'keywords' => CONFIG->get('keywords'),
                'root' => '',
                'app_name' => APP_NAME,
                'app_domain' => APP_DOMAIN,
                'csrf_root' => $this->csrf_inject()
            )
        );

        $template = BODY_TEMPLATE_FOLDER . '/auth/register.html';
        $head = BODY_TEMPLATE_FOLDER . '/auth/partial/header.html';
        $footer = BODY_TEMPLATE_FOLDER . '/auth/partial/footer.html';
        exit($this->view($template, $content, $head, $footer));
    }

    public function index()
    {
        $header = $this->getHeader();
        $content = array_merge(
            $header,
            array(
                'title' => 'Official | ' . APP_NAME,
                'og_title' => CONFIG->get('og_title'),
                'og_desc' => CONFIG->get('og_desc'),
                'keywords' => CONFIG->get('keywords'),
                'root' => '',
                'app_name' => APP_NAME,
            )
        );

        $template = BODY_TEMPLATE_FOLDER . '/web/home.html';
        $head = BODY_TEMPLATE_FOLDER . '/web/header.html';
        $footer = BODY_TEMPLATE_FOLDER . '/web/footer.html';
        exit($this->view($template, $content, $head, $footer));
    }

    public function sitemap(){
        $generator = new \Manomite\Engine\SitemapGenerator(APP_DOMAIN);
        $generator->crawl();
        $result = $this->migration->listings()->conn->find('find', ['status' => 'approved']);
        if(count($result) > 0){
            foreach($result as $prop){
                $generator->addUrl(APP_DOMAIN.'/listing/'.$prop['property_number'], 0.8, 'daily');
            }
        }

        $result = $this->migration->blog()->conn->find('find');
        if(count($result) > 0){
            foreach($result as $blog){
                $generator->addUrl(APP_DOMAIN.'/blog/'.$blog['post_id'], 0.8, 'weekly');
            }
        }
        
        // Generate sitemap XML
        return $generator->generateSitemap();
    }



    public function home()
    {
        $header = $this->session();
        $userData = json_decode($header['profile'], true);
       
        $accountModel = new Reflect('Account');
        $account = $accountModel->getAccountsByAuthTokenAndTitle($userData['authToken'], 'Default');
        $balance = $this->money->format(0, 'USD');
        if (is_array($account) && !$this->arrayAdapter->isEmpty($account)) {
            $wallet = json_decode($account['wallet'], true);
            $balance = $this->money->format($wallet['closing_balance'], 'USD');
        }

        $content = array_merge(
            $header,
            array(
                'title' => 'Official | ' . APP_NAME,
                'og_title' => CONFIG->get('og_title'),
                'og_desc' => CONFIG->get('og_desc'),
                'keywords' => CONFIG->get('keywords'),
                'root' => '',
                'app_name' => APP_NAME,
                'default_balance' => $balance
            )
        );

        $template = BODY_TEMPLATE_FOLDER . '/home/home.html';
        $head = BODY_TEMPLATE_FOLDER . '/home/partial/header.html';
        $footer = BODY_TEMPLATE_FOLDER . '/home/partial/footer.html';
        exit($this->view($template, $content, $head, $footer));
    }

    ///////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////////

     public function error($message, $code = 500)
    {
        if (empty($message)) {
            $message = 'Sorry this page you are looking for does not exist. You might be looking for what does not exist in the universe. Please use the button below to find your way out.';
        }
        $header = $this->getHeader();

        $type = match($code){
            200 => 'error-success',
            default => 'error-code'
        };
      
        $content = array(
            'template' => '',
            'title' => $code . ' | ' . APP_NAME,
            'error' => $code,
            'type' => $type,
            'message' => $message,
            'lang_meta_desc' => LANG->get('meta_desc'),
            'root' => APP_DOMAIN . '/home/',
            'app_name' => $header['app_name'],
            'logo' => $header['logo'],
            'favicon' => $header['favicon'],
            'time' => $this->date->now()
        );
        //$template = BODY_TEMPLATE_FOLDER . '/error.html';
        exit($message);
    }

    private function view($body, $content, $head, $footer)
    {
        return $this->route->display(
            $head,
            $footer,
            $body,
            $content
        );
    }

    private function single($body, $content)
    {
        return $this->route->single(
            $body,
            $content
        );
    }

}

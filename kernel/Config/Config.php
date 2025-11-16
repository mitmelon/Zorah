<?php
use M1\Vars\Vars;
use Manomite\{
    Engine\Platform\Platform,
    Engine\File
};

//__DIR__.'/../../../private/estatewake';
//(new Platform)->getDataDir().'/estatewake';

$data_dir = (new Platform)->getDataDir().'/zorah';
if(!is_dir($data_dir)){
    @mkdir($data_dir, 0750, true);
}

define('PROJECT_ROOT', __DIR__ . '/../..');
$manager = new File($data_dir);
define('SYSTEM_DIR', $data_dir);

if(is_dir($data_dir.'/config')){
    $scanner = $manager->scanner($data_dir.'/config');
    $config = new Vars($scanner);
    $scanner = $manager->scanner($data_dir.'/lang/'.$config->get('app_language'));
    $lang = new Vars($scanner);

} else {
    //Check root if settings folder exist
    $dir = PROJECT_ROOT.'/settings';
    if(is_dir($dir)){
        $manager->recursiveCopy($dir);
        $manager->deleteFilesThenSelf($dir);
        $scanner = $manager->scanner($data_dir.'/config');
        $config = new Vars($scanner);
        define('TIMEZONE', $config->get('app_timezone') ?: 'Africa/Lagos');
        $scanner = $manager->scanner($data_dir.'/lang/'.$config->get('app_language'));
        $lang = new Vars($scanner);
    }
}

define('LANG', $lang);
define('CONFIG', $config);

//OAUTH 2.0 Credential
define('MONGO_CLIENT_ID', $config->get('mongo_client_id'));
define('MONGO_CLIENT_SECRET', $config->get('mongo_client_secret'));

define('DB_NAME', $config->get('mongo_db_name'));

//App Configuration
define('APP_NAME', $config->get('app_name'));
define('APP_DEPLOYER_ID', $config->get('app_deployer_id'));
define('APP_DOMAIN', $config->get('app_domain'));
define('WEB_DOMAIN', $config->get('web_domain'));
define('APP_LOGO', $config->get('app_logo'));
define('APP_FAVICON', $config->get('app_favicon'));
define('APP_VERSION', $config->get('app_version'));
define('TIMEZONE', $config->get('app_timezone') ?: 'Africa/Lagos');
define('COPYRIGHTS', 'Copyrights '.date('Y').'. All Rights Reserved.');
define('PHP_EXEC_BIN', 'php');
define('ENVIRONMENT', $config->get('environment'));
define('BODY_TEMPLATE_FOLDER', 'template');
define('LOGIN_SESSION', strtolower(str_replace(' ', '_', APP_NAME)));
define('PROVIDER_SUPPORT_URL', '');

// Security Configuration
define('SECURITY_SALT', $config->get('security_salt'));
define('MAX_LOGIN_ATTEMPTS', $config->get('max_login_attempts', 5));
define('LOGIN_TIMEOUT', $config->get('login_timeout', 300)); // 5 minutes
define('SESSION_LIFETIME', $config->get('session_lifetime', 3600)); // 1 hour
define('REQUIRE_2FA', $config->get('require_2fa', true));
define('MIN_PASSWORD_LENGTH', $config->get('min_password_length', 12));
define('PASSWORD_REQUIRES_SPECIAL_CHARS', $config->get('password_requires_special_chars', true));

// Rate Limiting Configuration
define('RATE_LIMIT_GET', $config->get('rate_limit_get', 60)); // 60 requests per minute
define('RATE_LIMIT_POST', $config->get('rate_limit_post', 30)); // 30 POST requests per minute
define('RATE_LIMIT_OPTIONS', $config->get('rate_limit_options', 20)); // 20 OPTIONS requests per minute
define('RATE_LIMIT_DEFAULT', $config->get('rate_limit_default', 20)); // Default for other methods

// Security Headers Configuration
define('USE_HTACCESS_BLOCKING', $config->get('use_htaccess_blocking', true));
define('HTACCESS_PATH', PROJECT_ROOT . '/.htaccess');

//Email Confgurations
define('SMTP_HOST', $config->get('smtp_host')); //SMTP HOST (api.sendgrid.com)
define('SMTP_PORT', $config->get('smtp_port')); //SMTP PORT (406)
define('SMTP_USERNAME', $config->get('smtp_username')); //SMTP USERNAME (lucychats)
define('SMTP_PASSWORD', $config->get('smtp_password')); //SMTP PASSWORD (your_password)
define('SENDER_EMAIL', $config->get('smtp_sender')); //Sender Email
define('NO_REPLY_EMAIL', $config->get('smtp_reply_email')); //No-Reply Email
define('MAIL_DRIVER', $config->get('mail_driver')); //Email Driver
define('SUPPORT_EMAIL', $config->get('support_email')); //Support Email


//MB
define('KB', 1024);
define('MB', 1048576);
define('GB', 1073741824);
define('TB', 1099511627776);

<?php

namespace Manomite\Controller;

use \Manomite\{
    Engine\Security\PostFilter,
    Engine\Security\Encryption as Secret,
    Engine\Security\Firewall,
    Engine\DateHelper,
    Model\Reflect,
    Engine\Fingerprint,
    Engine\Network,
};

use \HtaccessFirewall\{
    HtaccessFirewall,
    Host\IP
};

require_once __DIR__ . '/../../autoload.php';

// Start the session only once in the constructor.
if (session_status() === PHP_SESSION_NONE) {
    // Configure session settings before starting
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.cookie_samesite', 'Lax');
    session_start();
}

// Define a constant for the session key to avoid hardcoding.
if (!defined('LOGIN_SESSION')) {
    define('LOGIN_SESSION', 'manomite_login_session');
}

class Auth
{
    private $sec;
    private $date;
    private $network;

    public function __construct()
    {

        // Initialize class dependencies.
        $this->sec = new PostFilter;
        $this->date = new DateHelper();
        $this->network = new Network;
    }

    public function loggedin()
    {
        // Check if the login session token exists and is not empty.
        if (!isset($_SESSION[LOGIN_SESSION]) || empty($_SESSION[LOGIN_SESSION])) {
            return false;
        }
        $userModel = new Reflect('User');
        $sessionToken = $this->sec->strip($_SESSION[LOGIN_SESSION]);
        $sessionModel = new Reflect('Session');
        $auth = $sessionModel->get_session($sessionToken, $this->date->timestampTimeNow());

        if (!$auth) {
            $this->destroy_session();
            return false;
        }

        try {
            $userData = $userModel->getUserByAuthToken($auth['authToken']);

            $timezone = $userData['timezone'] ?? TIMEZONE;
            $_SESSION['timezone'] = $timezone;

            // Extend the session's expiration time.
            $sessionModel->add_more_session_time($sessionToken, $auth['authToken'], $this->date->addMinute(30), $this->date->timestampTimeNow());

            return [
                "status" => true,
                "user" => $auth['authToken'],
                "session" => $sessionToken,
                "timezone" => $timezone,
            ];
        } catch (\Throwable $e) {
            error_log("Login check failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Registers a new user.
     * @param string $name The user's name.
     * @param string $email The user's email.
     * @param string $pass The user's password.
     * @param array $identity The device and browser information.
     * @param string $pri The user's privilege level.
     * @return array
     */
    public function register(string $name, string $email, string $pass, array $identity, string|null $ref_id = ''): array
    {
        // Filter and sanitize all inputs.
        $name = $this->sec->strip($name);
        $email = $this->sec->strip($email);
        $pass = $this->sec->strip($pass);
        $device = $this->sec->sanitizeArray($identity);

        if ($this->is_block($device['fingerprint']) !== false) {
            return ['status' => false, 'error' => 'Sorry! you are not allowed to access our service.'];
        }

        $userModel = new Reflect('User');
        $existingUser = $userModel->getUserByEmail($email);
        if ($existingUser) {
            return ['status' => false, 'error' => 'Sorry! this email is not available.'];
        }

        $key = (new Secret())->randomKey;
        $username = 'zorah-' . strtolower((new Secret())->tokenGenerator('-', 2, 3));
        $codex = (new Fingerprint())->codeGenerate($key);

        $sec_data = json_encode([
            'fingerprint' => $device['fingerprint'],
            'device' => $device,
            'codex' => $codex,
        ]);

        $hash = hash('sha256', $sec_data);
        $security = (new Secret($sec_data, 'master_key'))->encrypt();
        $pass = (new Secret())::hash($pass);

        $ip = $this->network->getIpAddress();
        if (ENVIRONMENT === 'development') {
            $ip = '105.119.31.82';
        }

        $device['ip'] = $ip;
        $networkInfo = $this->network->getInfo($ip);
        $country = $networkInfo['countryName'] ?? '';

        if (empty($country)) {
            return ['status' => false, 'error' => 'Sorry! we cannot retrieve your country of origin. Kindly switch off any proxy or vpn on your device to continue.'];
        }

        $streamPipe = json_decode(file_get_contents(SYSTEM_DIR . '/files/countries/country-by-abbreviation.json'), true);
        $try = '';
        foreach ($streamPipe as $co) {
            if (strtolower($co['country']) === strtolower($country)) {
                $try = $co['abbreviation'];
                break;
            }
        }
        if(empty($try)){
            return ['status' => false, 'error' => 'Sorry! we cannot retrieve your country of origin. Kindly switch off any proxy or vpn on your device to continue.'];
        }

        $currentTime = $this->date->getCurrentDateTimeByCountry($try);

        $credentials = [
            'authToken' => $key,
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'pass' => $pass,
            'status' => 0,
            'account_type' => 'normal',
            'stage' => 'boarding',
            'residential_country' => $country,
            'country_date_added' => $currentTime,
            'network_info' => $networkInfo,
            "created_at" => $this->date->timestampTimeNow(),
            'security' => [
                'sec_data' => $security,
                'data_hash' => $hash,
                'status' => false
            ],
            'referred_by' => $ref_id,
            'geo_data' => $networkInfo
        ];

        $userModel->create_user($credentials);

        $code = strtolower((new Secret())->tokenGenerator('-', 2, 4));
        $expire = $this->date->addMinute(10);

        $ref = (new Secret())->randomKey;

        $securityModel = new Reflect('Security');
        $securityModel->create_verification([
            'vToken' => $ref,
            'authToken' => $key,
            'code' => $code,
            'type' => 'account_opening',
            'browser_name' => $device['browser'],
            'os' => $device['os'],
            'device_name' => $device['device'],
            'ip' => $device['ip'],
            'expire' => $expire,
            'status' => 0
        ]);

        if(!empty($ref_id)){
            $data = $userModel->getUserByUsername($ref_id);
            if (isset($data['_id']) && $data['status'] == 1) {
                //Add bonus to referrer
                unset($data['_id']);
                $data['referrer'][] = ['user' => $key, 'claimed' => false, 'amount' => 0.5, 'status' => 'unpaid', 'currency' => 'USDC'];
                $data['updated_at'] = $this->date->timestampTimeNow();

                $userModel->updateUserAccount(['authToken' => $data['authToken'], 'status' => 1], $data);
            }
        }

        return ['status' => true, 'key' => $key, 'code' => $code, 'payload' => $hash];
    }

    /**
     * Attempts to log a user in.
     * @param string $username The user's username or email.
     * @param string $password The user's password.
     * @param array $device Device and browser information.
     * @return array An array containing the login status and related data.
     */
    public function login(string $username, string $password, array $device): array
    {
        try {
            // Sanitize all inputs to protect against injection attacks.
            $username = $this->sec->strip($username);
            $password = $this->sec->strip($password);
            $device = $this->sec->sanitizeArray($device);

            $userModel = new Reflect('User');
            $userData = $userModel->complexFindOne([
                '$or' => [['username' => $username], ['email' => $username]]
            ]);

            if (!$userData) {
                return ['status' => false, 'error' => 'Invalid credentials.'];
            }

            // Verify the user's account status.
            if (intval($userData['status']) === 0) {
                return ["status" => false, "message" => "Account is not active. Please activate your account by verifying your email address.", 'review' => true];
            } elseif (intval($userData['status']) !== 1) {
                return ["status" => false, "message" => "Account is locked.", 'locked' => true];
            }

            // Verify the provided password against the hashed password.
            if (!(new Secret())::verify_hash($userData['pass'], $password)) {
                return ["status" => false, "message" => "Invalid credentials."];
            }

            // If 2FA is enabled for the user, start the 2FA flow.
            if (isset($userData['security']['status']) && $userData['security']['status'] === true) {
                $payload = ['device' => $device, 'user' => $userData['authToken']];
                return ["status" => true, "security" => true, "payload" => $payload];
            }

            // Set session data FIRST
            $sessionToken = $this->set_session($userData['authToken'], $device);
            $_SESSION[LOGIN_SESSION] = $sessionToken;
            
            // Force session data to be written immediately
            session_commit();
            
            // Restart session to continue using it
            if (session_status() === PHP_SESSION_NONE) {
                session_start();
            }

            return [
                "status" => true,
                "security" => false,
                "message" => "Login successful.",
                "user" => $userData['authToken'],
                'payload' => $userData['security']['data_hash']
            ];

        } catch (\Throwable $e) {
            // Catch and log any unexpected errors without exposing details to the user.
            error_log("Login function error: " . $e->getMessage());
            return ["status" => false, "message" => "An unexpected error occurred."];
        }
    }

    /**
     * Creates a new session document in the database.
     * @param string $authToken The user's authentication token.
     * @param array $device The device information.
     * @return string The new session token.
     */
    public function set_session(string $authToken, array $device)
    {
        $token = (new Secret())->randomKey;
        $expire = $this->date->addMinute(120);

        $sessionModel = new Reflect('Session');
        $sessionModel->set_session($token, $authToken, $expire, $this->date->timestampTimeNow(), $device);

        return $token;
    }

    /**
     * Checks if a fingerprint is blocked.
     * @param string $fingerprint The device fingerprint.
     * @return array|false Returns block data on success, false if not blocked.
     */
    public function is_block(string $fingerprint)
    {
        try {
            $securityModel = new Reflect('Security');
            $data = $securityModel->getSecurityByFingerprint($fingerprint);
            return $data ?: false;
        } catch (\Throwable $e) {
            throw new \Exception($e->getMessage());
        }
    }

    /**
     * Generates a temporary authentication code.
     * @param string $fingerprint The device fingerprint.
     * @param int $expire The expiration time in seconds.
     * @return bool
     */
    public function generateTmpAuth(string $fingerprint, int $expire = 300)
    {
        $payload = (new Fingerprint())->codeGenerate($fingerprint, false, date('d-m-Y'));
        $code = hash('sha256', $payload);
        return (new Secret())->session_setter('fingerprint_' . $fingerprint, $code, $expire);
    }

    public function destroy_session()
    {
        session_unset();
        session_destroy();
        $_SESSION = [];
    }

    public function firewall_block(string $ip)
    {
        $firewall = new Firewall;
        $firewall->block($ip, PROJECT_ROOT . '/.htaccess');
    }

    public function firewall_unblock(string $ip)
    {
        $firewall = new Firewall;
        $firewall->unblock($ip, PROJECT_ROOT . '/.htaccess');
    }
}
<?php

namespace Manomite\Engine\Security;

use ParagonIE\Halite\HiddenString;
use ParagonIE\Halite\KeyFactory;
use ParagonIE\Halite\Symmetric\Crypto as Symmetric;
use ParagonIE\ConstantTime\Encoding;
use ParagonIE\Halite\File;
use ParagonIE\Halite\Symmetric\EncryptionKey;
use ParagonIE\Halite\Symmetric\AuthenticationKey;
use Manomite\Exception\ManomiteException as ex;
use Manomite\Engine\Fingerprint;
use Manomite\Engine\Network;
use ParagonIE\Halite\Asymmetric\{
    Crypto,
    EncryptionSecretKey,
    EncryptionPublicKey,
    SignatureSecretKey,
    SignaturePublicKey
};
use Ramsey\Uuid\Uuid;

class Encryption
{
    protected EncryptionKey $secretKey;
    public $randomKey;
    private string $keyDir;
    private ?string $data;
    private PostFilter $filter;

    public function __construct($data = null, string $key = 'master_key')
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }
        
        $this->data = $data;
        $this->keyDir = SYSTEM_DIR . '/secret';
        if (!is_dir($this->keyDir)) {
            mkdir($this->keyDir, 0700, true); // More restrictive permissions
        }

        $keyFile = $this->keyDir . '/' . $key . '.mkey';

        if (!file_exists($keyFile)) {
            $encryptionKey = KeyFactory::generateEncryptionKey();
            KeyFactory::save($encryptionKey, $keyFile);
            chmod($keyFile, 0600); // More restrictive permissions
        }

        $this->secretKey = KeyFactory::loadEncryptionKey($keyFile);
        $this->filter = new PostFilter();
        $this->randomKey = Encoding::hexEncode(random_bytes(16)); // Bit Generations
    }

    /**
     * Generate an encryption key pair for asymmetric encryption
     *
     * @return array{secret: string, public: string}
     */
    public function create_key_pair(): array
    {
        $keypair = KeyFactory::generateEncryptionKeyPair();
        $secret = $keypair->getSecretKey();
        $public = $keypair->getPublicKey();

        $tokenKey = KeyFactory::generateAuthenticationKey(); // Suitable for HMAC or hashing

        return [
            'secret' => Encoding::base64Encode($secret->getRawKeyMaterial()),
            'public' => Encoding::base64Encode($public->getRawKeyMaterial()),
            'token' => Encoding::base64Encode($tokenKey->getRawKeyMaterial())
        ];
    }

    /**
     * Store encryption key pair securely with a key name
     * Keys are stored in the secret folder and never exposed
     * 
     * @param string $keyName Unique name for this key pair (e.g., 'user_data', 'messages')
     * @return bool Success status
     */
    public function storeKeyPair(string $keyName): bool
    {
        try {
            // Generate a new key pair
            $keypair = KeyFactory::generateEncryptionKeyPair();
            $tokenKey = KeyFactory::generateAuthenticationKey();
            
            // Store keys in secret folder with restrictive permissions
            $secretKeyFile = $this->keyDir . '/' . $keyName . '_secret.key';
            $publicKeyFile = $this->keyDir . '/' . $keyName . '_public.key';
            $tokenKeyFile = $this->keyDir . '/' . $keyName . '_token.key';

            KeyFactory::save($keypair->getSecretKey(), $secretKeyFile);
            KeyFactory::save($keypair->getPublicKey(), $publicKeyFile);
            KeyFactory::save($tokenKey, $tokenKeyFile);
            
            // Set restrictive permissions
            chmod($secretKeyFile, 0600);
            chmod($publicKeyFile, 0600);
            chmod($tokenKeyFile, 0600);
            
            return true;
        } catch (\Throwable $e) {
            error_log("Failed to store key pair: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Encrypt data using asymmetric encryption with stored key
     * 
     * @param string $message Data to encrypt
     * @param string $keyName Name of the stored key pair
     * @return string|false Encrypted data or false on error
     */
    public function encryptWithStoredKey(string $message, string $keyName)
    {
        try {
            $publicKeyFile = $this->keyDir . '/' . $keyName . '_public.key';
            
            if (!file_exists($publicKeyFile)) {
                error_log("Public key file not found: {$publicKeyFile}");
                return false;
            }
            
            $publicKey = KeyFactory::loadEncryptionPublicKey($publicKeyFile);
            
            return Crypto::seal(
                new HiddenString($message),
                $publicKey
            );
        } catch (\Throwable $e) {
            error_log("Encryption error with stored key: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Decrypt data using asymmetric encryption with stored key
     * 
     * @param string $message Encrypted data
     * @param string $keyName Name of the stored key pair
     * @return string|false Decrypted data or false on error
     */
    public function decryptWithStoredKey(string $message, string $keyName)
    {
        try {
            $secretKeyFile = $this->keyDir . '/' . $keyName . '_secret.key';
            
            if (!file_exists($secretKeyFile)) {
                error_log("Secret key file not found: {$secretKeyFile}");
                return false;
            }
            
            $secretKey = KeyFactory::loadEncryptionSecretKey($secretKeyFile);
            $decrypted = Crypto::unseal($message, $secretKey);
            
            return $decrypted->getString();
        } catch (\Throwable $e) {
            error_log("Decryption error with stored key: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get token key for keyword tokenization
     * 
     * @param string $keyName Name of the stored key pair
     * @return string|false Token key material or false on error
     */
    public function getTokenKey(string $keyName)
    {
        try {
            $tokenKeyFile = $this->keyDir . '/' . $keyName . '_token.key';

            if (!file_exists($tokenKeyFile)) {
                error_log("Token key file not found: {$tokenKeyFile}");
                return false;
            }
            
            $tokenKey = KeyFactory::loadAuthenticationKey($tokenKeyFile);
            return Encoding::base64Encode($tokenKey->getRawKeyMaterial());
        } catch (\Throwable $e) {
            error_log("Token key retrieval error: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a key pair exists
     * 
     * @param string $keyName Name of the key pair
     * @return bool True if key pair exists
     */
    public function keyPairExists(string $keyName): bool
    {
        $secretKeyFile = $this->keyDir . '/' . $keyName . '_secret.key';
        $publicKeyFile = $this->keyDir . '/' . $keyName . '_public.key';
        $tokenKeyFile = $this->keyDir . '/' . $keyName . '_token.key';

        return file_exists($secretKeyFile) && file_exists($publicKeyFile) && file_exists($tokenKeyFile);
    }

    /**
     * Encrypt data using symmetric encryption
     *
     * @return string|false
     */
    public function encrypt()
    {
        try {
            return Symmetric::encrypt(
                new HiddenString((string)$this->data),
                $this->secretKey
            );
        } catch (\Throwable $e) {
            new ex("secretCryptoError", 6, $e->getMessage());
            return false;
        }
    }

    /**
     * Decrypt data using symmetric encryption
     *
     * @return string|false
     */
    public function decrypt()
    {
        try {
            $decrypted = Symmetric::decrypt($this->data, $this->secretKey);
            return $decrypted->getString();
        } catch (\Throwable $e) {
            new ex("secretCryptoError", 6, $e->getMessage());
            return false;
        }
    }

    /**
     * Encrypt a file using symmetric encryption
     *
     * @param string $fileInput
     * @param string $fileOutput
     * @return bool
     */
    public function encryptFile(string $fileInput, string $fileOutput): bool
    {
        try {
            File::encrypt($fileInput, $fileOutput, $this->secretKey);
            return true;
        } catch (\Throwable $e) {
            new ex("secretCryptoError", 6, $e->getMessage());
            return false;
        }
    }

    /**
     * Decrypt a file using symmetric encryption
     *
     * @param string $fileInput
     * @param string $fileOutput
     * @return bool
     */
    public function decryptFile(string $fileInput, string $fileOutput): bool
    {
        try {
            File::decrypt($fileInput, $fileOutput, $this->secretKey);
            return true;
        } catch (\Throwable $e) {
            new ex("secretCryptoError", 6, $e->getMessage());
            return false;
        }
    }

    /**
     * Encrypt a message using asymmetric encryption
     *
     * @param string $message
     * @param string $publicKey Hex-encoded public key
     * @return string|false
     */
    public function asyEncrypt(string $message, string $publicKey)
    {
        try {
            $publicKey = new EncryptionPublicKey(
                new HiddenString(Encoding::base64Decode($publicKey))
            );
            return Crypto::seal(
                new HiddenString($message),
                $publicKey
            );
        } catch (\Throwable $e) {
            new ex("secretCryptoError", 6, $e->getMessage());
            return false;
        }
    }

    /**
     * Decrypt a message using asymmetric encryption
     *
     * @param string $message
     * @param string $key Hex-encoded secret key
     * @return string|false
     */
    public function asyDecrypt(string $message, string $key)
    {
        try {
            $key = new EncryptionSecretKey(
                new HiddenString(Encoding::base64Decode($key))
            );
            $decrypted = Crypto::unseal($message, $key);
            return $decrypted->getString();
        } catch (\Throwable $e) {
            new ex("secretCryptoError", 6, $e->getMessage());
            return false;
        }
    }

    public function asyEncryptFile($file, $output, $publicKey)
    {
        try {

            $publicKey = new EncryptionPublicKey(new HiddenString(Encoding::base64Decode($publicKey)));
            File::seal(
                $file,
                $output,
                $publicKey
            );
            return true;
        } catch (\Throwable $e) {
            new ex("secretCrptoError", 6, $e->getMessage());
            return false;
        }
    }
    public function asyDecryptFile($key, $file, $output)
    {
        try {
            $key = new EncryptionSecretKey(new HiddenString(Encoding::base64Decode($key)));
            File::unseal($file, $output, $key);
            return true;
        } catch (\Throwable $e) {
            new ex("secretCrptoError", 6, $e->getMessage());
            return false;
        }
    }

    public static function hash($pass)
    {
        return sodium_crypto_pwhash_str(
            $pass,
            SODIUM_CRYPTO_PWHASH_OPSLIMIT_INTERACTIVE,
            SODIUM_CRYPTO_PWHASH_MEMLIMIT_INTERACTIVE
        );
    }

    public static function verify_hash($hash, $pass)
    {
        return sodium_crypto_pwhash_str_verify($hash, $pass);
    }

    public function randing($len)
    {
        $r = '';
        $chars = array_merge(range('0', '9'), range('A', 'Z'), range('a', 'z'));
        $max = count($chars) - 1;
        for ($i = 0; $i < $len; $i++) {
            $rand = mt_rand(0, $max);
            $r .= $chars[$rand];
        }
        return $r;
    }

    public function generateNumber($len = 9)
    {
        $rand = '';
        while (!(isset($rand[$len - 1]))) {
            $rand .= mt_rand();
        }
        return substr($rand, 0, $len);
    }

    public function mask($cc, $maskFrom = 0, $maskTo = 4, $maskChar = '*', $maskSpacer = '-')
    {
        // Clean out
        $cc = str_replace(array('-', ' '), '', $cc);
        $ccLength = strlen($cc);

        // Mask CC number
        if (empty($maskFrom) && $maskTo == $ccLength) {
            $cc = str_repeat($maskChar, $ccLength);
        } else {
            $cc = substr($cc, 0, $maskFrom) . str_repeat($maskChar, $ccLength - $maskFrom - $maskTo) . substr($cc, -1 * $maskTo);
        }

        // Format
        if ($ccLength > 4) {
            $newCreditCard = substr($cc, -4);
            for ($i = $ccLength - 5; $i >= 0; $i--) {
                // If on the fourth character add the mask char
                if ((($i + 1) - $ccLength) % 4 == 0) {
                    $newCreditCard = $maskSpacer . $newCreditCard;
                }

                // Add the current character to the new credit card
                $newCreditCard = $cc[$i] . $newCreditCard;
            }
        } else {
            $newCreditCard = $cc;
        }

        return $newCreditCard;
    }

    public function request_generator($session_name = 'request_generator', $id = APP_NAME, $date = null)
    {
        $current_date = $date ?: date('d-m-Y');
        $before_date = isset($_SESSION[$session_name . '_payload_date']) ? $_SESSION[$session_name . '_payload_date'] : null;
        if (!$this->request_verify($session_name, APP_NAME)) {
            unset($_SESSION[$session_name]);
            unset($_SESSION[$session_name . '_payload_date']);
            $payload = (new Fingerprint())->codeGenerate($id, false, $current_date);
            $_SESSION[$session_name] = hash('sha256', $payload);
            $_SESSION[$session_name . '_payload_date'] = $current_date;
        }
    }

    public function request_verify($session_name = 'request_generator', $id = APP_NAME, $date = null)
    {
        if (isset($_SERVER['HTTP_REFERER'])) {
            $get_domain = (new Network())->get_domain_from_url($_SERVER['HTTP_REFERER']);
            $val_domain = (new Network())->get_domain_from_url(APP_DOMAIN);
            //Change here in production
            if ($get_domain === $val_domain || $get_domain === '127.0.0.1') {
                $current_date = $date ?: date('d-m-Y');
                $payload = (new Fingerprint())->codeGenerate($id, false, $current_date);
                if (isset($_SESSION[$session_name])) {
                    $current = hash('sha256', $payload);
                    $expected = $this->filter->strip($_SESSION[$session_name]);
                    if (hash_equals($expected, $current)) {
                        return $_SESSION[$session_name];
                    } else {
                        $this->take_action_on_hacks();
                        return false;
                    }
                } else {
                    $this->take_action_on_hacks();
                    return false;
                }
            }
        }
        return false;
    }

    private function take_action_on_hacks()
    {
        //MORE SECURITY FEATURES COMING SOON HERE
    }

    public function generateSecureId($byteLength, $version = 'v1'): string {
        $randomBytes = random_bytes($byteLength); // Generate a key of specified size

        // Calculate the number of required sections based on byte length
        $sections = (int) ceil($byteLength / 4);

        // Initialize an empty array for formatted sections
        $formattedSections = [];

        // Loop through each section and unpack the bytes into hex strings
        for ($i = 0; $i < $sections; $i++) {
            $offset = $i * 4;
            $sectionBytes = substr($randomBytes, $offset, 4);
            $formattedSections[] = unpack('H*', $sectionBytes)[1];
        }

        // Concatenate formatted sections with hyphens and version
        return implode('-', $formattedSections) . '-' . $version;
    }
    
    public function uuid(): string
    {
        return Uuid::uuid4()->toString();
    }

    public function tokenGenerator($separator, $inch, $len)
    {
        $token = array();
        for ($i = 0; $i < $len; $i++) {
            $token[] = Encoding::hexEncode(random_bytes($inch));
        }
        return implode($separator, $token);
    }

    public function fileChecksum($file)
    {
        try {
            return File::checksum($file);
        } catch (\Throwable $e) {
            new ex("secretCrptoError", 6, $e->getMessage());
            return false;
        }
    }

    public function session_setter($session_name, $code, $time = 3600)
    {
        if (!isset($_SESSION[$session_name])) {
            $_SESSION[$session_name] = $code;
            $_SESSION[$session_name . '_time'] = time() + $time;
            return $_SESSION[$session_name];
        }
        return $_SESSION[$session_name];
    }

    public function verify_session_setter($code, $session_name, $unset = false)
    {
        if (isset($_SESSION[$session_name])) {
            $current = $_SESSION[$session_name];
            $time = $_SESSION[$session_name . '_time'];
            if (!$this->filter->nothing($code) and !$this->filter->nothing($time) and !$this->filter->nothing($current)) {
                $token_age = (int) $time - time();
                if ($token_age > 0) {
                    if ($code === $current) {
                        // Validated, Done!
                        if ($unset === true) {
                            unset($_SESSION[$session_name]);
                            unset($_SESSION[$session_name . '_time']);
                        }
                        return true;
                    }
                } else {
                    unset($_SESSION[$session_name]);
                    unset($_SESSION[$session_name . '_time']);
                    return false;
                }
            } else {
                return false;
            }
        }
        return false;
    }

    public function session_retrieve($session_name, $unset = false)
    {
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        if (isset($_SESSION[$session_name])) {
            $current = $_SESSION[$session_name];
            $time = $_SESSION[$session_name . '_time'];
            if (!$this->filter->nothing($time) and !$this->filter->nothing($current)) {
                $token_age = (int) $time - time();
                if ($token_age > 0) {
                    // Validated, Done!
                    if ($unset === true) {
                        unset($_SESSION[$session_name]);
                        unset($_SESSION[$session_name . '_time']);
                    }
                    return $_SESSION[$session_name];
                } else {
                    unset($_SESSION[$session_name]);
                    unset($_SESSION[$session_name . '_time']);
                    return false;
                }
            } else {
                return false;
            }
        }
        return false;
    }

    public function verify_client_token($signature)
    {
        $expectedFingerprint = hash('sha256', implode('', [
            $_SERVER['HTTP_USER_AGENT'],
            $_SERVER['HTTP_ACCEPT_LANGUAGE'],
            $_SERVER['HTTP_REFERER']
        ]));
        if (!hash_equals($signature, $expectedFingerprint)) {
            return false;
        }
        return true;
    }

    public function client_exchange_keypair(){
        $keyPair = sodium_crypto_box_keypair();

        $public = \sodium_crypto_box_publickey($keyPair);
        $private = \sodium_crypto_box_secretkey($keyPair);

        return array('secret' => base64_encode($private), 'public' => base64_encode($public));
    }

    public function client_exchange_encrypt($publicKey) {
        return bin2hex(sodium_crypto_box_seal($this->data, base64_decode($publicKey)));
    }

    public function client_exchange_decrypt($publicKey, $privateKey) {
        $keypair = sodium_crypto_box_keypair_from_secretkey_and_publickey(
            base64_decode($privateKey), base64_decode($publicKey)
        );

        return sodium_crypto_box_seal_open(sodium_hex2bin($this->data), $keypair);
    }
}

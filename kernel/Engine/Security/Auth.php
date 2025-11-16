<?php

namespace Manomite\Engine\Security;

use \Firebase\JWT\JWT;
use \Firebase\JWT\Key;
use \Firebase\JWT\ExpiredException;
use \UnexpectedValueException;

use \PragmaRX\Google2FA\Google2FA;
use \BaconQrCode\Renderer\GDLibRenderer;
use \BaconQrCode\Writer;

/**
 * Auth Class for JWT Token Management with encrypted payload
 *
 * This class handles JWT token generation, verification, and user data extraction
 * with additional encryption of the payload data for enhanced privacy.
 */
class Auth {
    /**
     * Secret key for JWT token signing
     * @var string
     */
    private string $secretKey;

    /**
     * Algorithm used for JWT token
     * @var string
     */
    private string $algorithm = 'HS256';

    /**
     * @var Encryption
     */
    private Encryption $encryption;
    private $manager;

    public function __construct() {
        $this->secretKey = CONFIG->get('security_salt');
        $this->encryption = new Encryption();
        $this->manager = new Google2FA();
    }

    /**
     * Generate a JWT token from user data
     * @param array $userData User data to encode in token
     * @param int $expiresIn Expiration time in seconds from now
     * @return string JWT token
     * @throws \Exception If token generation fails
     */
    public function generateToken(array $userData, int $expiresIn = 3600): string {
        try {
            $issuedAt = time();
            $expire = $issuedAt + $expiresIn;

            // Encrypt the user data using Encryption class
            $this->encryption = new Encryption(json_encode($userData));
            $encryptedData = $this->encryption->encrypt();

            if ($encryptedData === false) {
                throw new \Exception('Failed to encrypt user data');
            }

            $payload = [
                'data' => $encryptedData,    // Encrypted user data
                'iat' => $issuedAt,          // Issued at
                'exp' => $expire,            // Expire time
                'jti' => bin2hex(random_bytes(16)) // Unique token ID
            ];

            return JWT::encode($payload, $this->secretKey, $this->algorithm);
        } catch (\Exception $e) {
            throw new \Exception('Failed to generate token: ' . $e->getMessage());
        }
    }

    /**
     * Verify and decode a JWT token
     * @param string $token JWT token to verify
     * @return array|null Decoded and decrypted token data
     * @throws ExpiredException If token has expired
     * @throws UnexpectedValueException If token is invalid
     */
    public function verifyToken(string $token): ?array {
        try {
            $decoded = JWT::decode($token, new Key($this->secretKey, $this->algorithm));
            $decodedArray = (array) $decoded;

            // Decrypt the user data using Encryption class
            if (isset($decodedArray['data'])) {
                $this->encryption = new Encryption($decodedArray['data']);
                $decryptedData = $this->encryption->decrypt();

                if ($decryptedData === false) {
                    throw new \Exception('Failed to decrypt user data');
                }

                $userData = json_decode($decryptedData, true);
                return array_merge($userData, [
                    'iat' => $decodedArray['iat'],
                    'exp' => $decodedArray['exp'],
                    'jti' => $decodedArray['jti']
                ]);
            }

            throw new \Exception('Invalid token structure');
        } catch (ExpiredException $e) {
            throw new ExpiredException('Token has expired');
        } catch (UnexpectedValueException $e) {
            throw new UnexpectedValueException('Invalid token');
        } catch (\Exception $e) {
            throw new \Exception('Token verification failed: ' . $e->getMessage());
        }
    }

    /**
     * Check if a token has expired
     * @param string $token JWT token
     * @return bool True if expired, false otherwise
     */
    public function isTokenExpired(string $token): bool {
        try {
            $this->verifyToken($token);
            return false;
        } catch (ExpiredException $e) {
            return true;
        } catch (\Exception $e) {
            return true;
        }
    }

    public function generateSecret(){
         return $this->manager->generateSecretKey();
    }

    public function qrcode($email, $secret){
        $g2faUrl = $this->manager->getQRCodeUrl(
            APP_NAME,
            $email,
            $secret
        );
        $renderer = new GDLibRenderer(200);
        $writer = new Writer($renderer);

        return base64_encode($writer->writeString($g2faUrl));
    }

    public function verify($code, $secret){
        return $this->manager->verifyKey($secret, $code);
    }
}

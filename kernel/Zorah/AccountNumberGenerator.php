<?php
namespace Manomite\Zorah;
use \Exception;
/**
 * Secure Bank Account Number Generator
 * 
 * Generates cryptographically secure, numeric-only bank account numbers derived from wallet private keys
 * - Non-predictable
 * - Resistant to brute-force attacks
 * - Collision-resistant
 * - Numeric only (standard bank account format)
 */
class AccountNumberGenerator
{
    /**
     * Generate a cryptographically secure numeric bank account number from wallet private key
     * 
     * @param string $privateKey Wallet private key (hex string)
     * @param int $length Length of the account number (default: 11)
     * @return string Numeric bank account number
     * @throws Exception If random_bytes() fails or invalid private key
     */
    public static function generateFromPrivateKey(string $privateKey, int $length = 11): string
    {
        if (empty($privateKey)) {
            throw new Exception('Private key is required to generate account number');
        }

        // Remove 0x prefix if present
        $privateKey = str_replace('0x', '', $privateKey);
        
        // Validate private key format (should be 64 hex characters for 256-bit key)
        if (!ctype_xdigit($privateKey) || strlen($privateKey) < 32) {
            throw new Exception('Invalid private key format');
        }

        // Use HMAC-SHA256 for deterministic but secure derivation
        // This ensures the same private key always generates the same account number
        $hash = hash_hmac('sha256', $privateKey, 'BANK_ACCOUNT_NUMBER_DERIVATION_KEY');
        
        // Convert hash to numeric string
        $numeric = self::hashToNumeric($hash, $length);
        
        // Ensure it doesn't start with 0 (standard bank account convention)
        if ($numeric[0] === '0') {
            $numeric = '1' . substr($numeric, 1);
        }
        
        return $numeric;
    }
    
    /**
     * Generate account number with collision check
     * 
     * @param string $privateKey Wallet private key
     * @param callable $existsCallback Callback function to check if account number exists
     * @param int $length Length of account number
     * @param int $maxAttempts Maximum attempts to generate unique number
     * @return string Unique account number
     * @throws Exception If unable to generate unique number after max attempts
     */
    public static function generateUniqueFromPrivateKey(
        string $privateKey,
        callable $existsCallback,
        int $length = 11,
        int $maxAttempts = 10
    ): string {
        $attempts = 0;
        $nonce = 0;
        
        do {
            // Add nonce to private key for collision resolution
            $keyWithNonce = $privateKey . $nonce;
            $accountNumber = self::generateFromPrivateKey($keyWithNonce, $length);
            $attempts++;
            
            if ($attempts >= $maxAttempts) {
                throw new Exception(
                    "Failed to generate unique account number after {$maxAttempts} attempts"
                );
            }
            
            $nonce++;
            
        } while ($existsCallback($accountNumber));
        
        return $accountNumber;
    }

    /**
     * Generate account number with collision check and return the nonce used.
     * This allows storing the nonce so the mapping (privateKey + nonce) -> accountNumber
     * can be reproduced deterministically later.
     *
     * @param string $privateKey
     * @param callable $existsCallback
     * @param int $length
     * @param int $maxAttempts
     * @return array ['accountNumber' => string, 'nonce' => int]
     * @throws Exception
     */
    public static function generateUniqueFromPrivateKeyWithNonce(
        string $privateKey,
        callable $existsCallback,
        int $length = 11,
        int $maxAttempts = 10
    ): array {
        $attempts = 0;
        $nonce = 0;

        do {
            // Add nonce to private key for collision resolution
            $keyWithNonce = $privateKey . $nonce;
            $accountNumber = self::generateFromPrivateKey($keyWithNonce, $length);
            $attempts++;

            if (!$existsCallback($accountNumber)) {
                return ['accountNumber' => $accountNumber, 'nonce' => $nonce];
            }

            if ($attempts >= $maxAttempts) {
                throw new Exception(
                    "Failed to generate unique account number after {$maxAttempts} attempts"
                );
            }

            $nonce++;

        } while (true);
    }
    
    /**
     * Convert hash to numeric string of specified length
     * 
     * @param string $hash SHA-256 hash
     * @param int $length Desired length
     * @return string Numeric string
     */
    private static function hashToNumeric(string $hash, int $length): string
    {
        // Convert hex hash to decimal chunks
        $numeric = '';
        $hashLen = strlen($hash);
        
        // Process hash in 8-character chunks for better distribution
        for ($i = 0; $i < $hashLen && strlen($numeric) < $length; $i += 8) {
            $chunk = substr($hash, $i, 8);
            $decimal = hexdec($chunk);
            $numeric .= $decimal;
        }
        
        // If we need more digits, use additional hash iterations
        if (strlen($numeric) < $length) {
            $hash2 = hash('sha256', $hash);
            for ($i = 0; $i < strlen($hash2) && strlen($numeric) < $length; $i += 8) {
                $chunk = substr($hash2, $i, 8);
                $decimal = hexdec($chunk);
                $numeric .= $decimal;
            }
        }
        
        // Return exactly the length requested
        return substr($numeric, 0, $length);
    }
    
    /**
     * Validate account number format
     * 
     * @param string $accountNumber Account number to validate
     * @param int $length Expected length
     * @return bool True if valid format
     */
    public static function validate(string $accountNumber, int $length = 11): bool
    {
        // Check if numeric only
        if (!ctype_digit($accountNumber)) {
            return false;
        }
        
        // Check length
        if (strlen($accountNumber) !== $length) {
            return false;
        }
        
        // Check doesn't start with 0
        if ($accountNumber[0] === '0') {
            return false;
        }
        
        return true;
    }
    
    /**
     * Format account number for display (e.g., 1234-5678-901)
     * 
     * @param string $accountNumber Account number
     * @param int $groupSize Size of each group
     * @return string Formatted account number
     */
    public static function format(string $accountNumber, int $groupSize = 4): string
    {
        return implode('-', str_split($accountNumber, $groupSize));
    }
}
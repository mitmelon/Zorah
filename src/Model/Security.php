<?php
namespace Manomite\Model;


use Manomite\{
    Exception\ManomiteException as ex,
    Connection
};

class Security {

    private $db = null;

    public function __construct(){
        // Create indexes for fingerprint, ratelimit_key, and ip
        $this->db = new Connection(DB_NAME, 'security', [
            'fingerprint' => 1,
            'ratelimit_key' => 1,
            'ip'
        ]);
        $this->db->conn->enableEncryption(
            ['fingerprint', 'code', 'ip'],  // Fields that are encrypted
            'security_system',          // System-wide key for searchable fields
            ['fingerprint', 'code', 'ip']   // Searchable encrypted fields
        );
    }

    public function getSecurityByFingerprint(string $fingerprint){
        try {
            $security = $this->db->conn->find('findOne', ['fingerprint' => $fingerprint]);
            return $security ?: null;
        } catch (\Exception $e) {
            error_log("Error fetching security by fingerprint: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a rate limit record exists by key
     * Used by Ratelimit class - renamed to avoid FlatDB conflicts
     * 
     * @param string $key The unique identifier for the rate limit record
     * @return bool True if document exists, false otherwise
     */
    public function rateLimitExists(string $key): bool
    {
        try {
            $result = $this->db->conn->find('findOne', ['ratelimit_key' => $key]);
            return $result !== null && !empty($result);
        } catch (\Exception $e) {
            error_log("Error checking rate limit key existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get rate limit data by key
     * Used by Ratelimit class - renamed to avoid FlatDB conflicts
     * 
     * @param string $key The unique identifier for the rate limit record
     * @return array|null Rate limit info or null if not found
     */
    public function getRateLimit(string $key): ?array
    {
        try {
            $result = $this->db->conn->find('findOne', ['ratelimit_key' => $key]);
            return $result ?: null;
        } catch (\Exception $e) {
            error_log("Error finding rate limit key: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing rate limit record
     * Used by Ratelimit class - renamed to avoid FlatDB conflicts
     * 
     * @param string $key The unique identifier for the rate limit record
     * @param array $info Rate limit information (limit, remaining, reset)
     * @return bool True on success, false on failure
     */
    public function updateRateLimit(string $key, array $info): bool
    {
        try {
            $info['ratelimit_key'] = $key;
            $info['updated_at'] = time();
            
            $result = $this->db->conn->update(
                'updateOne',
                ['ratelimit_key' => $key],
                $info
            );
            
            return $result > 0;
        } catch (\Exception $e) {
            error_log("Error updating rate limit document: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new rate limit record
     * Used by Ratelimit class - renamed to avoid FlatDB conflicts
     * 
     * @param string $key The unique identifier for the rate limit record
     * @param array $info Rate limit information (limit, remaining, reset)
     * @return bool True on success, false on failure
     */
    public function createRateLimit(string $key, array $info): bool
    {
        try {
            $info['ratelimit_key'] = $key;
            $info['created_at'] = time();
            $info['updated_at'] = time();
            
            $result = $this->db->conn->insertOne($info);
            
            return isset($result['status']) && $result['status'] > 0;
        } catch (\Exception $e) {
            error_log("Error creating rate limit document: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a rate limit record by key
     * Used by Ratelimit class - renamed to avoid FlatDB conflicts
     * 
     * @param string $key The unique identifier for the rate limit record
     * @return bool True on success, false on failure
     */
    public function deleteRateLimit(string $key): bool
    {
        try {
            $result = $this->db->conn->delete(
                'deleteOne',
                ['ratelimit_key' => $key]
            );
            
            return !empty($result);
        } catch (\Exception $e) {
            error_log("Error deleting rate limit document: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Check if a firewall block exists by IP
     * Used by Firewall class - renamed to avoid FlatDB conflicts
     * 
     * @param string $ip The IP address to check
     * @return bool True if block exists, false otherwise
     */
    public function firewallBlockExists(string $ip): bool
    {
        try {
            $result = $this->db->conn->find('findOne', ['ip' => $ip]);
            return $result !== null && !empty($result);
        } catch (\Exception $e) {
            error_log("Error checking firewall block existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get firewall block data by IP
     * Used by Firewall class - renamed to avoid FlatDB conflicts
     * 
     * @param string $ip The IP address
     * @return array|null Block info or null if not found
     */
    public function getFirewallBlock(string $ip): ?array
    {
        try {
            $result = $this->db->conn->find('findOne', ['ip' => $ip]);
            return $result ?: null;
        } catch (\Exception $e) {
            error_log("Error finding firewall block: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Create a new firewall block record
     * Used by Firewall class - renamed to avoid FlatDB conflicts
     * 
     * @param string $ip The IP address to block
     * @param array $blockData Block information (ip, reason, timestamp, expires)
     * @return bool True on success, false on failure
     */
    public function createFirewallBlock(string $ip, array $blockData): bool
    {
        try {
            $blockData['ip'] = $ip;
            $blockData['created_at'] = time();
            
            $result = $this->db->conn->insertOne($blockData);
            
            return isset($result['status']) && $result['status'] > 0;
        } catch (\Exception $e) {
            error_log("Error creating firewall block: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete a firewall block record by IP
     * Used by Firewall class - renamed to avoid FlatDB conflicts
     * 
     * @param string $ip The IP address to unblock
     * @return bool True on success, false on failure
     */
    public function deleteFirewallBlock(string $ip): bool
    {
        try {
            $result = $this->db->conn->delete(
                'deleteOne',
                ['ip' => $ip]
            );
            
            return !empty($result);
        } catch (\Exception $e) {
            error_log("Error deleting firewall block: " . $e->getMessage());
            return false;
        }
    }

    public function create_verification(array $verifyData)
    {
        $result = $this->db->conn->insertOne($verifyData);
        return $result;
    }

    public function getVerification(array $criteria)
    {
        $result = $this->db->conn->find('findOne', $criteria);
        return $result;
    }

    public function updateSecurity(array $query, $updateData){
        try {
            
            $security = $this->db->conn->update('updateOne', $query, $updateData);
            return $security ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error updating security: " . $e->getMessage());
        }
    }
}

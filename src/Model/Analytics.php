<?php
namespace Manomite\Model;


use Manomite\{
    Exception\ManomiteException as ex,
    Connection
};

class Analytics {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'analytics', [
            'analytic_id' => 1,
            'anchorName',
            'fingerprint',
            'accessCount' => -1  // Descending index for sorting most accessed
        ]);
    }

    /**
     * Check if an analytics record exists by anchor name
     * Used by anchor.php - renamed to avoid FlatDB conflicts
     * 
     * @param string $anchorName The unique anchor name identifier
     * @return bool True if record exists, false otherwise
     */
    public function analyticsExists(string $anchorName): bool
    {
        try {
            $result = $this->db->conn->find('findOne', ['anchorName' => $anchorName]);
            return $result !== null && !empty($result);
        } catch (\Exception $e) {
            error_log("Error checking analytics existence: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get analytics data by anchor name
     * Used by anchor.php - renamed to avoid FlatDB conflicts
     * 
     * @param string $anchorName The anchor name identifier
     * @return array|null Analytics info or null if not found
     */
    public function getAnalytics(string $anchorName): ?array
    {
        try {
            $result = $this->db->conn->find('findOne', ['anchorName' => $anchorName]);
            return $result ?: null;
        } catch (\Exception $e) {
            error_log("Error finding analytics: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Update an existing analytics record
     * Used by anchor.php - renamed to avoid FlatDB conflicts
     * 
     * @param string $anchorName The anchor name identifier
     * @param array $analyticsData Analytics information (clicks, clickCount, userInfo, etc.)
     * @return bool True on success, false on failure
     */
    public function updateAnalytics(string $anchorName, array $analyticsData): bool
    {
        try {
            $analyticsData['anchorName'] = $anchorName;
            $analyticsData['updated_at'] = time();
            
            $result = $this->db->conn->update(
                'updateOne',
                ['anchorName' => $anchorName],
                $analyticsData
            );
            
            return $result > 0;
        } catch (\Exception $e) {
            error_log("Error updating analytics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Create a new analytics record
     * Used by anchor.php - renamed to avoid FlatDB conflicts
     * 
     * @param string $anchorName The anchor name identifier
     * @param array $analyticsData Analytics information (clicks, clickCount, userInfo, etc.)
     * @return bool True on success, false on failure
     */
    public function createAnalytics(string $anchorName, array $analyticsData): bool
    {
        try {
            $analyticsData['anchorName'] = $anchorName;
            $analyticsData['created_at'] = time();
            $analyticsData['updated_at'] = time();
            
            $result = $this->db->conn->insertOne($analyticsData);
            
            return isset($result['status']) && $result['status'] > 0;
        } catch (\Exception $e) {
            error_log("Error creating analytics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Delete an analytics record by anchor name
     * Used for cleanup operations
     * 
     * @param string $anchorName The anchor name identifier
     * @return bool True on success, false on failure
     */
    public function deleteAnalytics(string $anchorName): bool
    {
        try {
            $result = $this->db->conn->delete(
                'deleteOne',
                ['anchorName' => $anchorName]
            );
            
            return !empty($result);
        } catch (\Exception $e) {
            error_log("Error deleting analytics: " . $e->getMessage());
            return false;
        }
    }

    /**
     * Get all analytics records with optional filters
     * 
     * @param array $filters Optional filters (e.g., ['fingerprint' => '...'])
     * @param array $options Optional query options (limit, sort, etc.)
     * @return array List of analytics records
     */
    public function getAllAnalytics(array $filters = [], array $options = []): array
    {
        try {
            $result = $this->db->conn->find('find', $filters, $options);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log("Error getting all analytics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get analytics by fingerprint
     * 
     * @param string $fingerprint The fingerprint identifier
     * @return array List of analytics for this fingerprint
     */
    public function getAnalyticsByFingerprint(string $fingerprint): array
    {
        try {
            $result = $this->db->conn->find('find', ['fingerprint' => $fingerprint]);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log("Error getting analytics by fingerprint: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get analytics by authToken
     * 
     * @param string $authToken The auth token identifier
     * @return array List of analytics for this user
     */
    public function getAnalyticsByAuthToken(string $authToken): array
    {
        try {
            $result = $this->db->conn->find('find', ['authToken' => $authToken]);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log("Error getting analytics by authToken: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get most accessed anchors
     * 
     * @param int $limit Number of results to return (default 10)
     * @return array List of most accessed analytics sorted by accessCount
     */
    public function getMostAccessed(int $limit = 10): array
    {
        try {
            $result = $this->db->conn->find('find', [], [
                'sort' => ['accessCount' => -1],
                'limit' => $limit
            ]);
            return is_array($result) ? $result : [];
        } catch (\Exception $e) {
            error_log("Error getting most accessed analytics: " . $e->getMessage());
            return [];
        }
    }

    /**
     * Get total click count across all anchors
     * 
     * @return int Total click count
     */
    public function getTotalClickCount(): int
    {
        try {
            $results = $this->db->conn->find('find', []);
            $total = 0;
            foreach ($results as $result) {
                $total += $result['clickCount'] ?? 0;
            }
            return $total;
        } catch (\Exception $e) {
            error_log("Error getting total click count: " . $e->getMessage());
            return 0;
        }
    }

    /**
     * Get total access count across all anchors
     * 
     * @return int Total access count
     */
    public function getTotalAccessCount(): int
    {
        try {
            $results = $this->db->conn->find('find', []);
            $total = 0;
            foreach ($results as $result) {
                $total += $result['accessCount'] ?? 0;
            }
            return $total;
        } catch (\Exception $e) {
            error_log("Error getting total access count: " . $e->getMessage());
            return 0;
        }
    }
}

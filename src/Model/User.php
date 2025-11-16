<?php
namespace Manomite\Model;


use Manomite\{
    Exception\ManomiteException as ex,
    Connection
};

class User {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'user_data', ['authToken' => 1, 'username' => 1]);
        $this->db->conn->enableEncryption(
            ['email', 'username', 'geo_data'],  // Fields that are encrypted
            'user_system',          // System-wide key for searchable fields
            ['email', 'username']   // Searchable encrypted fields
        );
    }

    public function getUserByAuthToken(string $authToken){
        try {
            $user = $this->db->conn->find('findOne', ['authToken' => $authToken]);
            return $user ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching user by auth token: " . $e->getMessage());
        }
    }

    public function getUserByAddress(string $address){
        try {
            $user = $this->db->conn->find('findOne', ['address' => $address]);
            return $user ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching user by address: " . $e->getMessage());
        }
    }

    public function set_session(string $id, string $authToken, string $expire, $createdAt, array $device = []): void
    {
        $this->db->conn->insertOne([
            'stoken' => $id,
            'authToken' => $authToken,
            'expire' => $expire,
            'created_at' => $createdAt,
            'device' => $device
        ]);
    }

    public function get_session(string $sessionToken, int $currentTime)
    {
        $auth = $this->db->conn->find('findOne', [
            'stoken' => $sessionToken,
            'expire' => ['$gte' => $currentTime]
        ]);
        return isset($auth['authToken']) ? $auth : false;
    }

    public function create_user(array $userData)
    {
        $result = $this->db->conn->insertOne($userData);
        return $result;
    }

    public function getUserByEmail(string $email){
        try {
        
            $user = $this->db->conn->find('findOne', ['email' => $email]);
            return $user ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching user by email: " . $e->getMessage());
        }
    }
    
    public function getUserByUsername(string $username){
        try {
           
            $user = $this->db->conn->find('findOne', ['username' => $username]);
            return $user ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching user by username: " . $e->getMessage());
        }
    }

    public function updateUserAccount(array $query, $updateData){
        try {
            
            $user = $this->db->conn->update('updateOne', $query, $updateData);
            return $user ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching user by username: " . $e->getMessage());
        }
    }

    public function complexFindOne(array $query){
        try {

            $user = $this->db->conn->find('findOne', $query);
            return $user ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error performing complex find: " . $e->getMessage());
        }
    }

    

  
}
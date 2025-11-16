<?php
namespace Manomite\Model;


use Manomite\{
    Exception\ManomiteException as ex,
    Connection
};

class Session {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'sessions', ['stoken' => 1, 'authToken', 'expire', 'create_at']);
    }

    public function set_session(string $token, string $authToken, int $expire, int $createdAt, array $device = []): void
    {
        $this->db->conn->insertOne([
            'stoken' => $token,
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

    public function add_more_session_time(string $token, string $username, int $expire, int $updatedAt)
    {
        $this->db->conn->update('updateOne', [
            'stoken' => $token,
            'authToken' => $username
        ], [
            'expire' => $expire,
            'updated_at' => $updatedAt
        ]);
    }

    

  
}
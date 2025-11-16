<?php
namespace Manomite\Model;


use Manomite\{
    Exception\ManomiteException as ex,
    Connection
};

class Messages {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'messages', ['message_id' => 1, 'sender_id', 'receiver_id', 'content', 'timestamp', 'type']);
    }

    public function getMessageById(string $id){
        try {
            $message = $this->db->conn->find('findOne', ['_id' => $id]);
            return $message ?: null;
        } catch (\Exception $e) {
            throw new ex("Error fetching message by ID: " . $e->getMessage());
        }
    }

    public function getMessagesByUserId(string $userId){
        try {
            $messages = $this->db->conn->find('find', ['sender_id' => $userId]);
            return $messages ?: [];
        } catch (\Exception $e) {
            throw new ex("Error fetching messages by user ID: " . $e->getMessage());
        }
    }

    public function countReceivedNotification(string $userId){
        try {
            $count = $this->db->conn->count(['receiver_id' => $userId, 'type' => 'notification']);
            return $count;
        } catch (\Exception $e) {
            throw new ex("Error counting received notifications: " . $e->getMessage());
        }
    }

    

  
}
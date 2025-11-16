<?php
namespace Manomite\Model;


use Manomite\{
    Connection
};

class Escrow {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'escrow', ['escrow_id' => 1, 'tx_hash' => 1, 'created_by', 'seller_wallet', 'buyer_wallet', 'status', 'created_at', 'expires_at', 'updated_at']);
        $this->db->conn->enableEncryption(
            ['escrow_id', 'seller_wallet', 'buyer_wallet', 'tx_hash'],
            'escrow_system',
            ['escrow_id', 'seller_wallet', 'buyer_wallet', 'tx_hash']
        );
    }

    public function createEscrow(array $data)
    {
        try {
            $insertedId = $this->db->conn->insertOne($data);
            return $insertedId;
        } catch (\Exception $e) {
            throw new \Exception("Error creating escrow: " . $e->getMessage());
        }
    }

    public function getEscrowById(string $authToken, string $escrowId)
    {
        try {
            $escrow = $this->db->conn->find('findOne', ['created_by' => $authToken, 'escrow_id' => $escrowId]);
            return $escrow ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching escrow: " . $e->getMessage());
        }
    }

    public function updateEscrowStatus(string $authToken, string $escrowId, string $status, int $time, string $reasons)
    {
        try {
            $updateResult = $this->db->conn->updateOne(
                ['created_by' => $authToken, 'escrow_id' => $escrowId],
                ['status' => $status, 'updated_at' => $time, 'reasons' => $reasons]
            );
            return $updateResult;
        } catch (\Exception $e) {
            throw new \Exception("Error updating escrow status: " . $e->getMessage());
        }
    }

    public function updateEscrowConfirmation(string $authToken, string $escrowId, int $buyerConfirmed, int $sellerConfirmed, int $time)
    {
        try {
            $updateResult = $this->db->conn->updateOne(
                ['created_by' => $authToken, 'escrow_id' => $escrowId],
                [
                    'buyer_confirmed' => $buyerConfirmed,
                    'seller_confirmed' => $sellerConfirmed,
                    'updated_at' => $time
                ]
            );
            return $updateResult;
        } catch (\Exception $e) {
            throw new \Exception("Error updating escrow confirmation: " . $e->getMessage());
        }
    }

  
}
<?php
namespace Manomite\Model;


use Manomite\{
    Connection
};

class Account {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'account', ['account_id' => 1, 'authToken', 'account_number' => 1, 'account_name', 'account_title', 'create_at', 'updated_at']);
        $this->db->conn->enableEncryption(
            ['account_number', 'wallet', 'account_number_nonce', 'account_id'],
            'wallet_system',
            ['account_number', 'wallet.address', 'account_number_nonce', 'wallet.publicKey', 'wallet.encryptedPrivateKey', 'account_id']
        );
    }

    public function getAccountsByAuthToken(string $authToken)
    {
        try {
            $accounts = $this->db->conn->find('find', ['authToken' => $authToken]);
            return $accounts ?: [];
        } catch (\Exception $e) {
            throw new \Exception("Error fetching accounts: " . $e->getMessage());
        }
    }

    public function getAccountsByAccountId(string $authToken, string $accountId)
    {
        try {
            $accounts = $this->db->conn->find('findOne', ['authToken' => $authToken, 'account_id' => $accountId]);
            return $accounts ?: [];
        } catch (\Exception $e) {
            throw new \Exception("Error fetching accounts: " . $e->getMessage());
        }
    }

    public function getAccountsByAccountNumber(string $accountNumber)
    {
        try {
            $accounts = $this->db->conn->find('findOne', ['account_number' => $accountNumber]);
            return $accounts ?: [];
        } catch (\Exception $e) {
            throw new \Exception("Error fetching accounts: " . $e->getMessage());
        }
    }

    public function getAccountsByAuthTokenAndTitle($authToken, $accountTitle)
    {
        try {
            $account = $this->db->conn->find('findOne', [
                'authToken' => $authToken,
                'account_title' => $accountTitle
            ]);
            return $account ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching account: " . $e->getMessage());
        }
    }

    public function createAccount(array $accountData)
    {
        try {
            
            // Prepare wallet data if provided
            if (isset($accountData['wallet_address'])) {
                $accountData['wallet'] = [
                    'address' => $accountData['wallet_address'],
                    'publicKey' => $accountData['wallet_public_key'] ?? '',
                    'encryptedPrivateKey' => $accountData['encrypted_private_key'] ?? '',
                    'closing_balance' => 0,
                    'opening_balance' => 0
                ];
                unset($accountData['wallet_address']);
                unset($accountData['wallet_public_key']);
                unset($accountData['encrypted_private_key']);
            }
            
            // Return whatever the underlying insert returns (insert id or result object)
            $result = $this->db->conn->insertOne($accountData);
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Error creating account: " . $e->getMessage());
        }
    }

    public function countAccountByAuthToken(string $authToken)
    {
        try {
            $count = $this->db->conn->count(['authToken' => $authToken]);
            return $count;
        } catch (\Exception $e) {
            throw new \Exception("Error counting accounts: " . $e->getMessage());
        }
    }

    public function updateAccount(array $criteria, array $updateData)
    {
        try {
            $result = $this->db->conn->update('updateOne',
                $criteria, $updateData
            );
            return $result;
        } catch (\Exception $e) {
            throw new \Exception("Error updating account: " . $e->getMessage());
        }
    }

  
}
<?php
namespace Manomite\Model;

use Manomite\{
    Connection
};

class Liquidity {

    private $db = null;

    public function __construct(){
        $this->db = new Connection(DB_NAME, 'liquidity', ['liq_id' => 1, 'authToken', 'currency', 'balance', 'type', 'reputation', 'telegram_id', 'response_time', 'created_at', 'updated_at']);
        $this->db->conn->enableEncryption(
            ['wallet_address', 'telegram_id', 'webhook'],
            'liquidity_system',
            ['wallet_address', 'telegram_id']
        );
    }

    public function getLiquidityByCurrencyAndAmount(string $currency, float $amount)
    {
        try {
            // Find the best liquidity provider: highest reputation, fast response time
            $liquidity = $this->db->conn->find('findOne', [
                'currency' => $currency, 
                'balance' => ['$gte' => $amount],
                'type' => 'selling',
                'reputation' => ['$gt' => 75],
                'response_time' => ['$gte' => 10, '$lte' => 30]
            ], [
                'sort' => ['reputation' => -1, 'response_time' => 1],
                'limit' => 1
            ]);

            return $liquidity ?: null;
        } catch (\Exception $e) {
            throw new \Exception("Error fetching liquidity: " . $e->getMessage());
        }
    }

    public function addLiquidityProvider(array $data)
    {
        try {
            if(empty($data)){
                //create a sample account
                $data = [
                    'authToken' => bin2hex(random_bytes(16)),
                    'currency' => 'USD',
                    'balance' => 1.8,
                    'type' => 'selling',
                    'wallet_address' => '0x21c3384863c97E4e7502dC2Feb699Ed6354ec25e',
                    'reputation' => 100,
                    'telegram_id' => '1094311254',
                    'response_time' => 15,
                    'created_at' => time(),
                    'updated_at' => time()
                ];
            }
            $insertedId = $this->db->conn->insertOne($data);
            return $insertedId;
        } catch (\Exception $e) {
            throw new \Exception("Error adding liquidity provider: " . $e->getMessage());
        }
    }
}
<?php
namespace Manomite;
include __DIR__ . '/../autoload.php';

class Connection
 {

    public $conn;

    public function __construct($db, $table, $index = [])
    {
        if ($this->conn === null) {
            $connect = new \Manomite\Database\MongoBase('mongodb://'.CONFIG->get('mongo_db_username').':'.CONFIG->get('mongo_db_password').'@'.CONFIG->get('mongo_db_host').':'.CONFIG->get('mongo_db_port'), $db, $table);

            if ($connect && method_exists($connect, 'createCollection')) {
                $connect->createCollection($index);
            }
            $this->conn = $connect; 
        }
    }
}
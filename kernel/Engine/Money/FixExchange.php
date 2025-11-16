<?php
namespace Manomite\Engine\Money;

require __DIR__ . '/../../../vendor/autoload.php';

class FixExchange
{
    public function __construct(){
        $this->api = '0edf0e2eacb81dcc433c2a787e7ba1cc';
        //0edf0e2eacb81dcc433c2a787e7ba1cc
        //35a3ad0f2f253d37131b68cd1b5953fc
    }

    public function singleExchange($from, $to){
        $data = json_decode(file_get_contents("http://data.fixer.io/api/latest?access_key={$this->api}&base={$from}&symbols={$to}"), true);
        if($data['success']){
            return array('date' => $data['date'], 'rate' => $data['rates'][$to]);
        }
    }

    public function multipleExchange($from, array $to){
        if (is_array($to)) {
            $to = implode(',', $to);
            $data = json_decode(file_get_contents("http://data.fixer.io/api/latest?access_key={$this->api}&base={$from}&symbols={$to}"), true);
            if ($data['success']) {
                return $data;
            } else {
                return 'Error fetching exchange';
            }
        } else {
            return 'Only Array values allowed for multiple exchange';
        }
    }

    public function convert($amount, $from, $to){
        $data = json_decode(file_get_contents("http://data.fixer.io/api/latest?access_key={$this->api}&base={$from}&symbols={$to}"), true);
        if($data['success']){
            $total = $amount * $data['rates'][$to];
            return array('date' => $data['date'], 'total' => $total);
        }
    }

    public function multipleConvert($amount, $from, array $to){
        if (is_array($to)) {
            $to = implode(',', $to);
            $data = json_decode(file_get_contents("http://data.fixer.io/api/latest?access_key={$this->api}&base={$from}&symbols={$to}"), true);
            if ($data['success']) {
                $total = array();
                foreach ($data['rates'] as $key => $value) {
                    $total[] = array($key => $amount * $value);
                }
                return array('date' => $data['date'], 'converted' => $total);
            } else {
                return $data;
            }
        } else {
            return 'Only Array values allowed for multiple exchange';
        }
    }
}
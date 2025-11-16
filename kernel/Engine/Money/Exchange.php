<?php
namespace Manomite\Engine\Money;

use Manomite\Exception\ManomiteException as ex;
use \Swap\Builder;

class Exchange
{

    private $swap;

    public function __construct()
    {
        $this->swap = (new Builder())
            ->add('fixer', ['access_key' => 'your-access-key'])
            ->add('currency_layer', ['access_key' => '2322bc910ab0b0e75ac4704a706874dd', 'enterprise' => false])
            ->add('exchange_rates_api', ['access_key' => 'secret'])
            ->add('abstract_api', ['api_key' => 'secret'])
            ->add('coin_layer', ['access_key' => 'secret', 'paid' => false])
            ->add('european_central_bank')
            ->add('national_bank_of_romania')
            ->add('central_bank_of_republic_turkey')
            ->add('central_bank_of_czech_republic')
            ->add('russian_central_bank')
            ->add('bulgarian_national_bank')
            ->add('webservicex')
            ->add('forge', ['api_key' => 'secret'])
            ->add('cryptonator')
            ->add('currency_data_feed', ['api_key' => 'secret'])
            ->add('currency_converter', ['access_key' => 'secret', 'enterprise' => false])
            ->add('open_exchange_rates', ['app_id' => 'secret', 'enterprise' => false])
            ->add('xignite', ['token' => 'token'])
            ->add('xchangeapi', ['api-key' => 'secret'])
            ->build();
    }

    public function getLatest($from, $to)
    {
        try {
            $rate = $this->swap->latest($from . '/' . $to);
            return $rate->getValue();
        } catch (\Exception $e) {
            (new ex('ExchangeSDK', 3, $e->getMessage()));
            return false;
        }
    }

}

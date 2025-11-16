<?php

namespace Manomite\Engine;

use JsonMachine\JsonDecoder\ExtJsonDecoder;
use JsonMachine\Items;
use Geocoder\Query\GeocodeQuery;
use Geocoder\Query\ReverseQuery;

class GeoPlugin
{
    protected $others = null;
    private $cache_key;
    private $cache;
    private $ip;

    public function __construct(string $ip = '')
    {
        $this->cache = new CacheAdapter();
        $this->cache_key = $ip.'client1.0.';

        if (is_null($ip)) {
            $ip = $_SERVER['REMOTE_ADDR'];
        }
        $this->ip = $ip;
    }

    public function locate()
    {
        $cache = $this->cache->getCache($this->cache_key);
        if($cache !== null && !empty($cache)) {
            return json_decode($cache, true);
        }
        $ip = $this->ip;
        if (empty($this->ip) || $this->ip === '::1' || $this->ip === '127.0.0.1') {
            return array(); //Cannot geolocate localhost or empty ip
        }
        $data = array();
        $apiKey = CONFIG->get('IPGEO_KEY');
        if (!empty($apiKey)) {
            $url = "https://api.ipgeolocation.io/v2/ipgeo?apiKey=" . urlencode($apiKey) . "&ip=" . urlencode($ip);
            $json = $this->fetchJson($url);
            if (is_array($json) && isset($json['ip'])) {
                $loc = $json['location'] ?? [];
                $currency = $json['currency'] ?? [];
                
                $data = [
                    'ip' => $json['ip'] ?? $ip,
                    'city' => $loc['city'] ?? '',
                    'region' => $loc['state_prov'] ?? '',
                    'regionCode' => $loc['state_code'] ?? null,
                    'regionName' => $loc['state_prov'] ?? null,
                    'dmaCode' => null,
                    'countryCode' => $loc['country_code2'] ?? null,
                    'countryName' => $loc['country_name'] ?? null,
                    'inEU' => $loc['is_eu'] ?? false,
                    'continentCode' => $loc['continent_code'] ?? null,
                    'continentName' => $loc['continent_name'] ?? null,
                    'latitude' => $loc['latitude'] ?? null,
                    'longitude' => $loc['longitude'] ?? null,
                    'locationAccuracyRadius' => null,
                    'timezone' => $loc['timezone'] ?? null,
                    'currencyCode' => $currency['code'] ?? null,
                    'currencySymbol' => $currency['symbol'] ?? null,
                    'currencyConverter' => null,
                    'zipcode' => $loc['zipcode'] ?? null,
                    'district' => $loc['district'] ?? null,
                    'geoname_id' => $loc['geoname_id'] ?? null
                ];

                $this->others = $data;
                $this->cache->cache(json_encode($this->others), $this->cache_key, 3600);
                return $this->others;
            }
        }

        return $this->others;
        
    }

    protected function fetch($host)
    {

        if (function_exists('curl_init')) {

            //use cURL to fetch data
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $host);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
            curl_setopt($ch, CURLOPT_USERAGENT, 'geoPlugin PHP Class v1.1');
            $response = curl_exec($ch);
            curl_close($ch);

        } elseif (ini_get('allow_url_fopen')) {

            //fall back to fopen()
            $response = file_get_contents($host);

        } else {

            trigger_error('geoPlugin class Error: Cannot retrieve data. Either compile PHP with cURL support or enable allow_url_fopen in php.ini ', E_USER_ERROR);
            return;

        }

        return $response;
    }

    /**
     * Fetch JSON from a URL and decode to array
     */
    protected function fetchJson(string $url): ?array
    {
        // use cURL if available
        if (function_exists('curl_init')) {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => 5,
                CURLOPT_HTTPHEADER => [
                    'Accept: application/json'
                ]
            ]);
            $resp = curl_exec($ch);
            $err = curl_errno($ch);
            curl_close($ch);
            if ($err || !$resp) return null;
            $json = json_decode($resp, true);
            return is_array($json) ? $json : null;
        }

        // fallback to file_get_contents
        if (ini_get('allow_url_fopen')) {
            $resp = @file_get_contents($url);
            if ($resp === false) return null;
            $json = json_decode($resp, true);
            return is_array($json) ? $json : null;
        }

        return null;
    }

    public function convert(int $amount, int $float = 2, bool $symbol = true)
    {

        //easily convert amounts to geolocated currency.
        if (!is_numeric($this->others['currencyConverter']) || $this->others['currencyConverter'] == 0) {
            trigger_error('geoPlugin class Notice: currencyConverter has no value.', E_USER_NOTICE);
            return $amount;
        }
        if (!is_numeric($amount)) {
            trigger_error('geoPlugin class Warning: The amount passed to geoPlugin::convert is not numeric.', E_USER_WARNING);
            return $amount;
        }
        if ($symbol === true) {
            return $this->others['currencySymbol'] . round(($amount * $this->others['currencyConverter']), $float);
        } else {
            return round(($amount * $this->others['currencyConverter']), $float);
        }
    }

    public function nearby(int $radius = 10, int $limit = 1)
    {

        if (!is_numeric($this->others['latitude']) || !is_numeric($this->others['longitude'])) {
            trigger_error('geoPlugin class Warning: Incorrect latitude or longitude values.', E_USER_NOTICE);
            return array( array() );
        }

        $host = 'http://www.geoplugin.net/extras/nearby.gp?lat=' . $this->others['latitude'] . '&long=' . $this->others['longitude'] . "&radius={$radius}";

        if (is_numeric($limit)) {
            $host .= "&limit={$limit}";
        }

        return unserialize($this->fetch($host));

    }


    public function getPostalCodeManual($country, $state, $region)
    {
        $fileResource = new File();

        $cache = $this->cache->getCache($country.$state.$region);
        if($cache !== null) {
            return $cache;
        }

        $local_file = Items::fromFile(SYSTEM_DIR . '/files/postal/nigeria.json', ['decoder' => new ExtJsonDecoder(true)]);
        $global_file = Items::fromFile(SYSTEM_DIR . '/files/postal/geonames-postal-code.json', ['decoder' => new ExtJsonDecoder(true)]);

        if(strtolower($country) === 'nigeria') {
            foreach($local_file as $postal) {
                if(strtolower($postal['state']) === strtolower($state) && strtolower($region) === strtolower($postal['local_government'])) {
                    return $postal['postal_code'];
                }
            }
        }

        if(strtolower($country) !== 'nigeria') {
            $cabb = Items::fromFile(SYSTEM_DIR . '/files/countries/country-by-abbreviation.json', ['decoder' => new ExtJsonDecoder(true)]);

            $cab = '';
            foreach($cabb as $c) {
                if(strtolower($c['country']) === strtolower($country)) {
                    $cab = $c['abbreviation'];
                }
            }
            if(!empty($cab)) {
                foreach($global_file as $key => $data) {
                    if(strtolower($data['country_code']) === strtolower($cab) && (strtolower($data['place_name']) === strtolower($state) or strtolower($data['place_name']) === strtolower($region) or strtolower($data['admin_name1']) === strtolower($state) or strtolower($data['admin_name1']) === strtolower($state))) {
                        return $data['postal_code'];
                    }
                }
            }
        }

    }

    public function geocodeAddress($address, $provider = 'google_maps')
    {
        $httpClient = new \GuzzleHttp\Client();
        try {
            
            $geocoder = new \Geocoder\ProviderAggregator();
            $geocoder->registerProviders([
                new \Geocoder\Provider\GoogleMaps\GoogleMaps($httpClient, null, CONFIG->get('GOOGLE_KEY'))
            ]);

            $result = $geocoder->using($provider)->geocodeQuery(GeocodeQuery::create($address));

            $addresses = $result->all();
            $addressArrays = [];
            foreach ($addresses as $address) {
                $addressArrays[] = $address->toArray(); // or $address->jsonSerialize()
            }

            return $addressArrays[0];
        } catch(\Throwable $e) {
           return $e;
        }
    }

    public function getTimezone($latitude, $longitude)
    {
        return 'Africa/Lagos';
        $timezone = file_get_contents('https://maps.googleapis.com/maps/api/timezone/json?key='.CONFIG->get('GOOGLE_KEY').'&location='.$latitude.','.$longitude.'&timestamp='.time());
        $tz_result = json_decode($timezone);
        return isset($tz_result->timeZoneId) ? $tz_result : false;
    }

}

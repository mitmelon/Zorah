<?php
namespace Manomite\Engine\Money;

use \Manomite\Engine\CacheAdapter;

class Helper
{
    /**
     * Format number to a particular currency.
     *
     * @param float Amount to format
     * @param string Currency
     * @return string
     */
    public function format(mixed $amount, string $currency)
    {
        $money = new Money($amount, $currency);

        return $money->format();
    }

    public function getRate(string $to, string $from = 'USD')
    {

        $exchange = new Exchange();
        $cache    = new CacheAdapter();
        $rate     = $cache->getCache($to);
        if ($rate === null or empty($rate)) {
            $rate = $exchange->getLatest($from, $to);
            $cache->cache($rate, $to, 86400);
        }
        $rate = $exchange->getLatest($from, $to);

        return $rate;
    }

    public function exchange($source)
    {
        // Convert USD to $source currency by crawling multiple sources for best rate
        if (empty($source) || strtoupper($source) === 'USD') {
            return 1.0; // USD to USD is always 1
        }

        $source = strtoupper($source);
        $cache = new CacheAdapter();
        $cacheKey = "exchange_rate_USD_{$source}";
        
        // Check cache first (cache for 1 hour)
        $cachedRate = $cache->getCache($cacheKey);
        if ($cachedRate !== null && !empty($cachedRate)) {
            return floatval($cachedRate);
        }

        // Try multiple FREE API sources (no keys required) and return the highest rate
        $rates = [];
        
        // Source 1: ExchangeRate-API (free, no key required, 160+ currencies)
        try {
            $url1 = "https://api.exchangerate-api.com/v4/latest/USD";
            $response1 = @file_get_contents($url1);
            if ($response1 !== false) {
                $data1 = json_decode($response1, true);
                if (isset($data1['rates'][$source])) {
                    $rates[] = floatval($data1['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail, try next source
        }

        // Source 2: Open Exchange Rates (free, no key, 170+ currencies)
        try {
            $url2 = "https://open.er-api.com/v6/latest/USD";
            $response2 = @file_get_contents($url2);
            if ($response2 !== false) {
                $data2 = json_decode($response2, true);
                if (isset($data2['rates'][$source])) {
                    $rates[] = floatval($data2['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail, try next source
        }

        // Source 3: Frankfurter API (European Central Bank data, 30+ currencies)
        try {
            $url3 = "https://api.frankfurter.app/latest?from=USD&to={$source}";
            $response3 = @file_get_contents($url3);
            if ($response3 !== false) {
                $data3 = json_decode($response3, true);
                if (isset($data3['rates'][$source])) {
                    $rates[] = floatval($data3['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 4: Currency API (free, real-time rates, 150+ currencies)
        try {
            $url4 = "https://cdn.jsdelivr.net/npm/@fawazahmed0/currency-api@latest/v1/currencies/usd.json";
            $response4 = @file_get_contents($url4);
            if ($response4 !== false) {
                $data4 = json_decode($response4, true);
                $sourceLower = strtolower($source);
                if (isset($data4['usd'][$sourceLower])) {
                    $rates[] = floatval($data4['usd'][$sourceLower]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 5: Floatrates (free JSON feeds, updated daily, all major currencies)
        try {
            $url5 = "https://www.floatrates.com/daily/usd.json";
            $response5 = @file_get_contents($url5);
            if ($response5 !== false) {
                $data5 = json_decode($response5, true);
                $sourceLower = strtolower($source);
                if (isset($data5[$sourceLower]['rate'])) {
                    $rates[] = floatval($data5[$sourceLower]['rate']);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 6: Exchangeratesapi.io (free, no key, 168 currencies)
        try {
            $url6 = "https://api.exchangeratesapi.io/latest?base=USD&symbols={$source}";
            $response6 = @file_get_contents($url6);
            if ($response6 !== false) {
                $data6 = json_decode($response6, true);
                if (isset($data6['rates'][$source])) {
                    $rates[] = floatval($data6['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 7: Vatcomply (free, no registration, 170+ currencies)
        try {
            $url7 = "https://api.vatcomply.com/rates?base=USD";
            $response7 = @file_get_contents($url7);
            if ($response7 !== false) {
                $data7 = json_decode($response7, true);
                if (isset($data7['rates'][$source])) {
                    $rates[] = floatval($data7['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 8: ExchangeRate.host (free, no key, 170+ currencies)
        try {
            $url8 = "https://api.exchangerate.host/latest?base=USD&symbols={$source}";
            $response8 = @file_get_contents($url8);
            if ($response8 !== false) {
                $data8 = json_decode($response8, true);
                if (isset($data8['rates'][$source])) {
                    $rates[] = floatval($data8['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 9: Currencyapi.net (free, no key required, 152 currencies)
        try {
            $url9 = "https://currencyapi.net/api/v1/rates?key=free&output=JSON&base=USD";
            $response9 = @file_get_contents($url9);
            if ($response9 !== false) {
                $data9 = json_decode($response9, true);
                if (isset($data9['rates'][$source])) {
                    $rates[] = floatval($data9['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Source 10: Coinbase API (free, no key, all major fiat currencies)
        try {
            $url10 = "https://api.coinbase.com/v2/exchange-rates?currency=USD";
            $response10 = @file_get_contents($url10);
            if ($response10 !== false) {
                $data10 = json_decode($response10, true);
                if (isset($data10['data']['rates'][$source])) {
                    $rates[] = floatval($data10['data']['rates'][$source]);
                }
            }
        } catch (\Exception $e) {
            // Silent fail
        }

        // Return the highest rate found, or fallback to Exchange class
        if (!empty($rates)) {
            $highestRate = max($rates);
            // Cache the highest rate for 1 hour (3600 seconds)
            $cache->cache($highestRate, $cacheKey, 3600);
            return $highestRate;
        }

        // Fallback: use existing Exchange class method
        try {
            $exchange = new Exchange();
            $rate = $exchange->getLatest('USD', $source);
            if ($rate !== null && $rate > 0) {
                $cache->cache($rate, $cacheKey, 3600);
                return floatval($rate);
            }
        } catch (\Exception $e) {
            // Final fallback
        }

        // If all else fails, return 1.0 (no conversion)
        return 1.0;
    }

    public function formatNumber($number, $steps) {
        // Validate steps
        if (!is_int($steps) || $steps < 0) {
            throw new \InvalidArgumentException("Steps must be a non-negative integer");
        }

        $numberStr = (string) floatval($number);

        $parts = explode('.', $numberStr);
        $integerPart = $parts[0];
        $decimalPart = isset($parts[1]) ? $parts[1] : '';

        if ($steps === 0) {
            return $integerPart;
        }

        $decimalPart = substr($decimalPart, 0, $steps);
        $decimalPart = str_pad($decimalPart, $steps, '0', STR_PAD_RIGHT);

        return $integerPart . ($decimalPart !== '' ? '.' . $decimalPart : '');
    }
}

<?php

namespace Manomite\Engine;

use Manomite\Exception\ManomiteException as ex;
ini_set('max_execution_time', 0);

class Network
{
    /**
    * Whether to use proxy addresses or not.
    *
    * As default this setting is disabled - IP address is mostly needed to increase
    * security. HTTP_* are not reliable since can easily be spoofed. It can be enabled
    * just for more flexibility, but if user uses proxy to connect to trusted services
    * it's his/her own risk, only reliable field for IP address is $_SERVER['REMOTE_ADDR'].
    *
    * @var bool
    */

    protected $useProxy = false;

    /**
     * List of trusted proxy IP addresses
     *
     * @var array
     */
    protected $trustedProxies = ['127.0.0.1'];

    /**
     * HTTP header to introspect for proxies
     *
     * @var string
     */
    protected $proxyHeader = 'HTTP_X_FORWARDED_FOR';

    /**
     * Regular expression for matching and validating a MAC address
     * @var string
     */
    private static $valid_mac = "([0-9A-F]{2}[:-]){5}([0-9A-F]{2})";
    /**
     * An array of valid MAC address characters
     * @var array
     */
    private static $mac_address_vals = array(
        "0", "1", "2", "3", "4", "5", "6", "7",
        "8", "9", "A", "B", "C", "D", "E", "F"
     );

    /**
     * Changes proxy handling setting.
     *
     * This must be static method, since validators are recovered automatically
     * at session read, so this is the only way to switch setting.
     *
     * @param  bool  $useProxy Whether to check also proxied IP addresses.
     * @return RemoteAddress
     */
    public function setUseProxy($useProxy = true)
    {
        $this->useProxy = $useProxy;
        return $this;
    }

    /**
     * Checks proxy handling setting.
     *
     * @return bool Current setting value.
     */
    public function getUseProxy()
    {
        return $this->useProxy;
    }

    /**
     * Set list of trusted proxy addresses
     *
     * @param  array $trustedProxies
     * @return RemoteAddress
     */
    public function setTrustedProxies(array $trustedProxies)
    {
        $this->trustedProxies = $trustedProxies;
        return $this;
    }

    /**
     * Set the header to introspect for proxy IPs
     *
     * @param  string $header
     * @return RemoteAddress
     */
    public function setProxyHeader($header = 'X-Forwarded-For')
    {
        $this->proxyHeader = $this->normalizeProxyHeader($header);
        return $this;
    }

    /**
     * Returns client IP address.
     *
     * @return string IP address.
     */
    public function getIpAddress()
    {
        $ip = $this->getIpAddressFromProxy();
        if (!empty($ip) and $ip !== '::1' and $ip !== '127.0.0.1') {
            return $ip;
        }
        // direct IP address
        if (isset($_SERVER['REMOTE_ADDR']) and $_SERVER['REMOTE_ADDR'] !== '::1' and $_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
            return $_SERVER['REMOTE_ADDR'];
        }
        return '';
    }

    /**
     * Attempt to get the IP address for a proxied client
     *
     * @see http://tools.ietf.org/html/draft-ietf-appsawg-http-forwarded-10#section-5.2
     * @return false|string
     */
    protected function getIpAddressFromProxy()
    {
        if (! $this->useProxy
            || (isset($_SERVER['REMOTE_ADDR']) && ! in_array($_SERVER['REMOTE_ADDR'], $this->trustedProxies))
        ) {
            return false;
        }

        $header = $this->proxyHeader;
        if (! isset($_SERVER[$header]) || empty($_SERVER[$header])) {
            return false;
        }

        // Extract IPs
        $ips = explode(',', $_SERVER[$header]);
        // trim, so we can compare against trusted proxies properly
        $ips = array_map('trim', $ips);
        // remove trusted proxy IPs
        $ips = array_diff($ips, $this->trustedProxies);

        // Any left?
        if (empty($ips)) {
            return false;
        }

        // Since we've removed any known, trusted proxy servers, the right-most
        // address represents the first IP we do not know about -- i.e., we do
        // not know if it is a proxy server, or a client. As such, we treat it
        // as the originating IP.
        // @see http://en.wikipedia.org/wiki/X-Forwarded-For
        $ip = array_pop($ips);
        return $ip;
    }

    /**
     * Normalize a header string
     *
     * Normalizes a header string to a format that is compatible with
     * $_SERVER
     *
     * @param  string $header
     * @return string
     */
    protected function normalizeProxyHeader($header)
    {
        $header = strtoupper($header);
        $header = str_replace('-', '_', $header);
        if (0 !== strpos($header, 'HTTP_')) {
            $header = 'HTTP_' . $header;
        }
        return $header;
    }

    public function getInfo($ip = null)
    {
        //array(18) { ["ip"] => string(15) "105.112.104.105" ["city"] => string(5) "Lagos" ["region"]=> string(5) "Lagos" ["regionCode"]=> string(2) "LA" ["regionName"] => string(5) "Lagos" ["dmaCode"]=> string(0) "" ["countryCode"]=> string(2) "NG" ["countryName"]=> string(7) "Nigeria" ["inEU"]=> int(0) ["continentCode"]=> string(2) "AF" ["continentName"]=> string(6) "Africa" ["latitude"]=> string(6) "6.4474" ["longitude"]=> string(6) "3.3903" ["locationAccuracyRadius"]=> string(3) "200" ["timezone"]=> string(12) "Africa/Lagos" ["currencyCode"]=> string(3) "NGN" ["currencySymbol"]=> string(7) "₦" ["currencyConverter"]=> string(8) "387.1498" }
        if($ip === null){
            $ip = $this->getIpAddress();
        }
        return (new GeoPlugin($ip))->locate();
    }

    /**
     * @return string generated MAC address
     */
    public static function generateMacAddress()
    {
        $vals = self::$mac_address_vals;
        if (count($vals) >= 1) {
            $mac = array("00"); // set first two digits manually
            while (count($mac) < 6) {
                shuffle($vals);
                $mac[] = $vals[0] . $vals[1];
            }
            $mac = implode(":", $mac);
        }
        return $mac;
    }
    /**
     * Make sure the provided MAC address is in the correct format
     * @param string $mac
     * @return bool true if valid; otherwise false
     */
    public static function validateMacAddress($mac)
    {
        return (bool) preg_match("/^" . self::$valid_mac . "$/i", $mac);
    }
    /**
     * Run the specified command and return it's output
     * @param string $command
     * @return string Output from command that was ran
     * @param string $type
     * @return string type of shell to use
     */
    protected static function runCommand($command, $type)
    {
        $command = \Manomite\Engine\Security\PostFilter::shellFilter($command);
        $type = (new \Manomite\Engine\Security\PostFilter())->strip($type);
        switch ($type) {
            case 'system':
                $shell = system($command);
                break;
            case 'shell_exec':
                $shell = shell_exec($command);
                break;
            case 'passthru':
                $code = passthru($command);
                break;
            default:
                $shell = exec($command);
        }
        return $shell;
    }
    /**
     * Get the android system's current MAC address
     * @param string $interface The name of the interface e.g. eth0
     * @return string|bool Systems current MAC address; otherwise false on error
     */
    public static function getAndroidMacAddress()
    {
        if (strpos(PHP_OS, 'WIN') === 1) {
            $ifconfig = self::runCommand("ip address", 'shell_exec');
            preg_match("/" . self::$valid_mac . "/i", $ifconfig, $ifconfig);
            if (isset($ifconfig[0])) {
                return trim(strtoupper($ifconfig[0]));
            }
            return false;
        }
        return false;
    }
    /**
     * Get the linus system's current MAC address
     * @param string $interface The name of the interface e.g. eth0
     * @return string|bool Systems current MAC address; otherwise false on error
     */
    public static function getLinusMacAddress($interface = 'eth0')
    {
        if (strpos(PHP_OS, 'WIN') === 1) {
            $ifconfig = self::runCommand("ifconfig {$interface}", 'shell_exec');
            preg_match("/" . self::$valid_mac . "/i", $ifconfig, $ifconfig);
            if (isset($ifconfig[0])) {
                return trim(strtoupper($ifconfig[0]));
            }
            return false;
        }
        return false;
    }

    /**
     * Get the windows system's current MAC address
     * @param string $interface The name of the interface e.g. all
     */
    public static function getWinMacAddress($interface = 'all', $position = 'Physical Address')
    {
        if (strpos(PHP_OS, 'WIN') === 0) {
            // Turn on output buffering
            ob_start();
            //Get the ipconfig details using system commond
            self::runCommand("ipconfig /{$interface}", 'system');
            // Capture the output into a variable
            $mycom = ob_get_contents();
            // Clean (erase) the output buffer
            ob_clean();
            $findme = $position;
            //List of positions [Physical Address, IPv4, Description, DHCP Server, Subnet Mask, Default Gateway, Host Name]
            //Search the "Physical" | Find the position of Physical text
            $pmac = strpos($mycom, $findme);
            // Get Physical Address
            if ($mac = substr($mycom, ($pmac + 36), 17)) {
                //Display Mac Address
                return $mac;
            }
            return false;
        }
        return false;
    }

    public static function internetStatus()
    {
        if ($sock = @fsockopen('www.google.com', 80, $num, $error, 5)) {
            return true;
        } else {
            return false;
        }
    }

    public static function getOS()
    {
        return php_uname();
    }

    public function getHost($host)
    {
        $host = strtolower(trim($host));
        $host = ltrim(str_replace("http://", "", str_replace("https://", "", $host)), "www.");
        $count = substr_count($host, '.');
        if ($count === 2) {
            if (strlen(explode('.', $host)[1]) > 3) {
                $host = explode('.', $host, 2)[1];
            }
        } elseif ($count > 2) {
            $host = $this->getHost(explode('.', $host, 2)[1]);
        }
        $host = explode('/', $host);
        return $host[0];
    }

    public static function split_url($url)
    {
        $query = parse_url($url, PHP_URL_QUERY);
        parse_str($query, $arr);
        return $arr;
    }

    public function get_domain_from_url($url)
    {
        $pieces = parse_url($url);
        $domain = isset($pieces['host']) ? $pieces['host'] : '';
        if (preg_match('/(?P<domain>[a-z0-9][a-z0-9\-]{1,63}\.[a-z\.]{2,6})$/i', $domain, $regs)) {
            return $regs['domain'];
        }
        return false;
    }

    public function long2ip($long)
    {
        if (!function_exists("long2ip")) {
            function long2ip($long)
            {
                // Valid range: 0.0.0.0 -> 255.255.255.255
                if ($long < 0 || $long > 4294967295) {
                    return false;
                }
                $ip = "";
                for ($i = 3;$i >= 0;$i--) {
                    $ip .= (int)($long / pow(256, $i));
                    $long -= (int)($long / pow(256, $i)) * pow(256, $i);
                    if ($i > 0) {
                        $ip .= ".";
                    }
                }
                return $ip;
            }
        } else {
            return long2ip($long);
        }
    }

    public function getUrl()
    {
        if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
            $link = 'https';
        } else {
            $link = 'http';
        }
        $link .= '://';
        $link .= isset($_SERVER['HTTP_HOST']) ? strip_tags($_SERVER['HTTP_HOST']) : '';
        $link .= isset($_SERVER['REQUEST_URI']) ? strip_tags($_SERVER['HTTP_HOST']) : '';
        return strip_tags($link);
    }
}

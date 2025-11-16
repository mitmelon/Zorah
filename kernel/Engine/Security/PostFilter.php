<?php
namespace Manomite\Engine\Security;

use Egulias\EmailValidator\EmailValidator;
use Egulias\EmailValidator\Validation\DNSCheckValidation;
use Egulias\EmailValidator\Validation\MultipleValidationWithAnd;
use Egulias\EmailValidator\Validation\RFCValidation;
use Spoofchecker;
use HTMLPurifier;
use HTMLPurifier_Config;
use \Manomite\Engine\UTF8;
/**
 * PostFilter class for input sanitization and validation
 */
class PostFilter extends \HTMLPurifier_AttrDef_URI
{
    private $utf8;

    public function __construct()
    {
        parent::__construct();
        $this->utf8 = new UTF8;
    }

    public function nothing($string)
    {
        return empty($string);
    }

    /**
     * Strips and sanitizes input to prevent XSS and other injection attacks
     *
     * @param string|null $value Input to sanitize
     * @param bool $onlyTextAndWhiteSpace If true, only allows alphanumeric chars, spaces and hyphens
     * @return string|null Sanitized string
     */
    public function strip($value, $onlyTextAndWhiteSpace = false)
    {
        // If input is null or empty, return as is
        if ($value === null || $value === '') {
            return $value;
        }

        // First clean the string using UTF8 methods
        $value = $this->utf8->utf8_clean($value);
        $value = $this->utf8->utf8_strip_tags($value);

        // Strict filtering for text-only input
        if ($onlyTextAndWhiteSpace) {
            $value = preg_replace('/[^a-zA-Z0-9\s-]/', '', $value);
        }

        // Comprehensive security filtering
        $value = $this->comprehensiveStringFilter($value);

        return $value;
    }

    /**
     * Perform comprehensive string-level security filtering
     *
     * @param string $input Input to filter
     * @return string Filtered input
     */
    private function comprehensiveStringFilter(string $input): string
    {
        // Ensure input is a string
        if (!is_string($input)) {
            try {
                $input = (string) $input;
            } catch (\TypeError $e) {
                // If cannot be converted to string, return empty string
                return '';
            }
        }

        // Remove control characters
        $input = preg_replace('/[\x00-\x1F\x7F]/', '', $input);

        // Neutralize potential injection attempts
        $dangerousPatterns = [
            // PHP code execution
            '/(<\?php|<\?|\?>)/',
            '/\b(eval|system|exec|passthru|shell_exec|proc_open|popen)\s*\(/i',

            // File system operations
            '/\b(file_get_contents|file_put_contents|fopen|readfile|parse_ini_file)\s*\(/i',

            // Remote code inclusion
            '/\b(include|require|include_once|require_once)\s*\(/i',

            // Database and command injection
            '/\b(mysql_|pg_|sqlite_|odbc_)\w+\s*\(/i',
            '/\b(union|select|insert|update|delete|drop)\s+/i',

            // Base64 and encoding tricks
            '/base64_decode\s*\(/i',
            '/chr\s*\(/i',

            // Dangerous PHP functions
            '/\b(create_function|extract|parse_str)\s*\(/i',

            // Remote execution
            '/\b(curl_exec|fsockopen|pfsockopen)\s*\(/i'
        ];

        // Remove dangerous patterns
        foreach ($dangerousPatterns as $pattern) {
            $input = preg_replace($pattern, '', $input);
        }

        // Additional sanitization layers
        $input = htmlspecialchars($input, ENT_QUOTES | ENT_HTML5, 'UTF-8');

        // Final type and content validation
        if (!is_string($input) || trim($input) === '') {
            return '';
        }

        return $input;
    }

    /**
     * Sanitize POST input
     *
     * @param string|null $input Input field name
     * @param bool $filter Whether to apply additional filtering
     * @return mixed Sanitized input value
     */
    public function inputPost($input, $filter = true)
    {
        if ($input === null) {
            return null;
        }
        $value = filter_input(INPUT_POST, $input, FILTER_UNSAFE_RAW);
        return $filter ? $this->strip($value) : $value;
    }

    /**
     * Sanitize GET input
     *
     * @param string|null $input Input field name
     * @param bool $filter Whether to apply additional filtering
     * @return mixed Sanitized input value
     */
    public function inputGet($input, $filter = true)
    {
        if ($input === null) {
            return null;
        }
        $value = filter_input(INPUT_GET, $input, FILTER_UNSAFE_RAW);
        return $filter ? $this->strip($value) : $value;
    }

    public function inputPostArray($input)
    {
        if ($input === null) {
            return $input;
        }
        return filter_input(INPUT_POST, $input, FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY);
    }

    public function inputGetArray($input)
    {
        if ($input === null) {
            return $input;
        }
        return filter_input(INPUT_GET, $input, FILTER_SANITIZE_SPECIAL_CHARS, FILTER_REQUIRE_ARRAY);
    }


    /**
     * Sanitize array of inputs
     *
     * @param array $data Array of inputs to sanitize
     * @param bool $filter Whether to apply additional filtering
     * @return array Sanitized array
     */
    public function sanitizeArray(array $data, $filter = true): array
    {
        $sanitized = [];
        foreach ($data as $key => $value) {
            $key = $this->strip($key);
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeArray($value, $filter);
            } else {
                $sanitized[$key] = $filter ? $this->strip($value) : $value;
            }
        }
        return $sanitized;
    }

    /**
     * Validate email address
     *
     * @param string $email Email address to validate
     * @return bool Whether email is valid
     */
     public function validate_email($email, $forceValidation = true)
    {
        if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
            if ($forceValidation) {
                if (extension_loaded('intl')) {
                    $checker = new Spoofchecker();
                    $checker->setChecks($checker::SINGLE_SCRIPT);
                    if ($checker->isSuspicious($email)) {
                        return false;
                    }
                }
                $validator = new EmailValidator();
                $multipleValidations = new MultipleValidationWithAnd([
                    new RFCValidation(),
                    new DNSCheckValidation()
                ]);
                return $validator->isValid($email, $multipleValidations);
            } else {
                return false;
            }
            return true;
        } else {
            return false;
        }
    }

     public function validate_name($name)
    {
        $name = trim($name);
        if (strlen($name) > 8) {
            $name = explode(' ', $name);
            if (count($name) >=  2) {
                return true;
            } else {
                return false;
            }
        } else {
            return false;
        }
    }

    public function validate_phone($phone)
    {
        $filtered_phone_number = filter_var($phone, FILTER_SANITIZE_NUMBER_INT);
        $remove_dash = str_replace('-', '', $filtered_phone_number);
        $remove_plus = str_replace('+', '', $remove_dash);
        if (strlen($remove_plus) < 12 || strlen($remove_plus) > 13) {
            return false;
        } else {
            return true;
        }
    }

    public function is_valid_domain($url)
    {
        $validation = false;
        $urlparts = parse_url(filter_var($url, FILTER_SANITIZE_URL));

        if (!isset($urlparts['host'])) {
            $urlparts['host'] = $urlparts['path'] ?? '';
        }

        if ($urlparts['host'] === '') {
            return false;
        }

        if (!isset($urlparts['scheme'])) {
            $urlparts['scheme'] = 'http';
        }

        $host = $urlparts['host'];

        // Check if host is an IP address
        $isIp = filter_var($host, FILTER_VALIDATE_IP) !== false;
        $isLocalhost = strpos($host, '127.0.0.1') === 0 || $host === 'localhost';

        // Allow IP-based URLs or localhost without DNS check
        if ($isIp || $isLocalhost) {
            $url = $urlparts['scheme'] . '://' . $host . ($urlparts['path'] ?? '') . ($urlparts['query'] ? '?' . $urlparts['query'] : '');
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $validation = true;
            }
        } else {
            // Original DNS-based validation for non-IP domains
            if (checkdnsrr($host, 'A') && in_array($urlparts['scheme'], ['http', 'https']) && ip2long($host) === false) {
                $host = preg_replace('/^www\./', '', $host);
                $url = $urlparts['scheme'] . '://' . $host . "/";
                if (filter_var($url, FILTER_VALIDATE_URL) !== false && @get_headers($url)) {
                    $validation = true;
                }
            }
        }

        // Additional check for S3-specific URLs
        if (!$validation && strpos($url, 'X-Amz-') !== false) {
            // Trust S3 URLs with AWS-specific parameters
            if (filter_var($url, FILTER_VALIDATE_URL) !== false) {
                $validation = true;
            }
        }

        return $validation;
    }

    public function validate_domain($domain_name)
    {
        return (preg_match("/^([a-zd](-*[a-zd])*)(.([a-zd](-*[a-zd])*))*$/i", $domain_name) //valid characters check
            && preg_match("/^.{1,253}$/", $domain_name) //overall length check
            && preg_match("/^.{1,63}(.[^.]{1,63})*$/", $domain_name)); //length of every label
    }

    public function groupFilter(...$variables)
    {
        if (count($variables) < 2) {
            //Only arguments greater than 2 could be filtered
            return false;
        }
        foreach ($variables as $variable) {
            //Lets filter variable value
            $value = $this->strip($variable);
            //Store data
            $save[] = array($value);
        }
        //Covert to 1D Array for easy usage
        return (new \Manomite\Engine\ArrayAdapter)->array_flatten($save);
    }

    /**
     * Comprehensively sanitize HTML input
     *
     * @param string|null $input Input to sanitize
     * @param array $htmlPurifierConfig Optional custom HTMLPurifier configuration
     * @return string|null Sanitized HTML
     */
    public function htmlSanitize(?string $input, array $htmlPurifierConfig = []): ?string
    {
        // If input is null or empty, return as is
        if ($input === null || $input === '') {
            return $input;
        }

        // First, clean the string using UTF8 methods
        $input = $this->utf8->utf8_clean($input);

        // Detect if input contains HTML
        $isHtml = $this->containsHtml($input);

        // If not HTML, use comprehensive string filtering
        if (!$isHtml) {
            return $this->comprehensiveStringFilter($input);
        }

        // Prevent potential code injection
        $antiXssSanitized = $this->comprehensiveStringFilter($antiXssSanitized);

        // Second layer: HTMLPurifier for HTML content
        try {
            // Create default HTMLPurifier configuration if not provided
            $config = $htmlPurifierConfig
                ? HTMLPurifier_Config::create($htmlPurifierConfig)
                : HTMLPurifier_Config::createDefault();

            // Comprehensive security configuration
            $config->set('HTML.Allowed', 'p,b,i,u,a[href|title],ul,ol,li,br,strong,em,span');
            $config->set('CSS.AllowedProperties', []);
            $config->set('HTML.ForbiddenElements', [
                'script', 'iframe', 'object', 'embed', 'php', 'code', 'pre',
                'applet', 'base', 'basefont', 'frame', 'frameset', 'import',
                'layer', 'link', 'meta', 'param', 'style'
            ]);
            $config->set('HTML.MaxImgLength', 1000);
            $config->set('URI.SafeIframeRegexp', '%^(https?:)?//(www\.youtube(?:-nocookie)?\.com/embed/|player\.vimeo\.com/video/)%');

            // Safer Unicode and emoji handling
            $config->set('Core.EscapeInvalidChildren', true);
            $config->set('Core.EscapeNonASCIICharacters', false);
            $config->set('Core.AllowedCharacters', '/^[\x{0000}-\x{FFFF}]/u');
            $config->set('Core.NormalizeNewlines', true);

            // Additional security settings
            $config->set('HTML.Doctype', 'HTML 4.01 Transitional');
            $config->set('HTML.TidyLevel', 'heavy');
            $config->set('HTML.SafeObject', true);
            $config->set('HTML.SafeEmbed', true);

            // Create purifier instance
            $purifier = new HTMLPurifier($config);

            // Purify the HTML
            return $purifier->purify($antiXssSanitized);
        } catch (\Exception $e) {
            // Fallback to comprehensive string filtering if HTMLPurifier fails
            return $this->comprehensiveStringFilter($antiXssSanitized);
        }
    }

    /**
     * Detect if the input contains HTML tags
     *
     * @param string $input Input to check
     * @return bool True if input contains HTML tags, false otherwise
     */
    private function containsHtml(string $input): bool
    {
        // Check for presence of HTML tags
        return preg_match('/<[^<]+>/', $input) === 1;
    }

    /**
     * Sanitize file names to prevent security vulnerabilities and ensure system compatibility
     *
     * @param string|null $filename Original filename to sanitize
     * @param string $replacement Replacement character for invalid characters (default: '_')
     * @param int $maxLength Maximum allowed filename length (default: 255)
     * @return string|null Sanitized filename with extension or null if invalid
     */
    public function sanitize_file_name(?string $filename, string $replacement = '_', int $maxLength = 255): ?string
    {
        // Return null if input is null or empty
        if ($filename === null || trim($filename) === '') {
            return null;
        }

        // Initial cleanup using existing strip method
        $filename = $this->strip($filename);

        // If nothing remains after initial cleanup, return null
        if (empty($filename)) {
            return null;
        }

        // Convert to UTF-8 using available UTF8 method
        $filename = $this->utf8->utf8_clean($filename);

        // Normalize Unicode characters if intl extension is available
        if (extension_loaded('intl') && class_exists('Normalizer')) {
            $filename = \Normalizer::normalize($filename, \Normalizer::FORM_C);
        }

        // Remove any path information to prevent traversal attacks
        $filename = basename($filename);

        // Define invalid characters for various operating systems (excluding the dot initially)
        $invalidChars = [
            // Windows reserved characters
            '<', '>', ':', '"', '/', '\\', '|', '?', '*',
            // Unix reserved characters
            '/',
            // Control characters
            ...array_map('chr', range(0, 31)),
            // Additional problematic characters
            "\0", "\x7F", "..",
        ];

        // Replace invalid characters (preserve dots for now)
        $filename = str_replace($invalidChars, $replacement, $filename);

        // Remove dangerous patterns specific to filenames
        $dangerousPatterns = [
            // Path traversal attempts
            '/\.\.+/',              // Multiple dots (but preserve single dots)
            '/^\./',               // Starting with dot
            '/\.$/',               // Ending with dot
            // Windows reserved names
            '/^(CON|PRN|AUX|NUL|COM[1-9]|LPT[1-9])$/i',
            // SQL injection attempts
            '/[\'";]/',
            // Unicode control characters
            '/[\p{C}]/u',
        ];

        $filename = preg_replace($dangerousPatterns, $replacement, $filename);

        // Additional security checks
        $filename = $this->comprehensiveStringFilter($filename);

        $filename = $this->utf8->utf8_clean($filename);
        

        // Normalize multiple replacement characters
        $filename = preg_replace('/' . preg_quote($replacement, '/') . '+/', $replacement, $filename);

        // Trim replacement characters from start and end
        $filename = trim($filename, $replacement . " \t\n\r\0\x0B");

        // Ensure filename isn't empty after sanitization
        if (empty($filename)) {
            return null;
        }

        // Split into name and extension (preserve the last dot)
        $parts = explode('.', $filename);
        $extension = '';
        $name = $filename;

        if (count($parts) > 1) {
            $extension = array_pop($parts); // Get the last part as extension
            $name = implode('.', $parts);   // Rejoin the rest as the name (allowing dots in name if present)

            // Sanitize name (allow limited special characters, including dots in multi-part names)
            $name = preg_replace('/[^a-zA-Z0-9_.-]/', $replacement, $name);

            // Sanitize extension (stricter, only alphanumeric)
            $extension = preg_replace('/[^a-zA-Z0-9]/', $replacement, $extension);

            // Block dangerous extensions
            $dangerousExtensions = [
                'php', 'php3', 'php4', 'php5', 'php7', 'phtml', 'pl', 'py', 'jsp',
                'asp', 'aspx', 'sh', 'cgi', 'exe', 'dll', 'bat', 'cmd'
            ];
            if (in_array(strtolower($extension), $dangerousExtensions)) {
                $extension = ''; // Remove dangerous extensions
            }

            // Reassemble filename with extension, ensuring a single dot
            $filename = $extension ? $name . '.' . $extension : $name;
        } else {
            // No extension case
            $filename = preg_replace('/[^a-zA-Z0-9_.-]/', $replacement, $filename);
        }

        // Truncate to maximum length (preserving extension)
        if (strlen($filename) > $maxLength) {
            if ($extension) {
                $extLength = strlen('.' . $extension);
                $nameLength = $maxLength - $extLength;
                $name = $this->utf8->utf8_substr($name, 0, $nameLength);
                $filename = $name . '.' . $extension;
            } else {
                $filename = $this->utf8->utf8_substr($filename, 0, $maxLength);
            }
        }

        // Final validation
        if (empty($filename) || $filename === '.' || $filename === '..' ||
            preg_match('/^\s+$/', $filename) || strlen($filename) > $maxLength) {
            return null;
        }

        return $filename;
    }

    /**
     * Sanitize JSON data to remove dangerous content while preserving valid data, including legitimate emojis
     *
     * @param mixed $jsonData Decoded JSON data (array, object, or scalar)
     * @return mixed Sanitized JSON data (array, object, or scalar)
     */
    public function sanitizeJsonData($jsonData)
    {
        if ($jsonData === null) {
            return null;
        }

        if (is_array($jsonData)) {
            $sanitized = [];
            foreach ($jsonData as $key => $value) {
                $cleanKey = $this->strip($key, true); // Only allow alphanumeric keys with spaces/hyphens
                if ($cleanKey === '' || $cleanKey === null) {
                    continue; // Skip invalid keys
                }
                $sanitized[$cleanKey] = $this->sanitizeJsonData($value);
            }
            return $sanitized;
        }

        if (is_object($jsonData)) {
            $sanitized = new \stdClass();
            foreach ((array) $jsonData as $key => $value) {
                $cleanKey = $this->strip($key, true); // Only allow alphanumeric keys with spaces/hyphens
                if ($cleanKey === '' || $cleanKey === null) {
                    continue; // Skip invalid keys
                }
                $sanitized->$cleanKey = $this->sanitizeJsonData($value);
            }
            return $sanitized;
        }

        // Handle scalar values (strings, numbers, booleans)
        if (is_string($jsonData)) {
            // First, apply basic XSS and injection filtering
            $value = $this->strip($jsonData);

            // Handle specific field types based on context
            if (filter_var($value, FILTER_VALIDATE_URL)) {
                // URL validation (e.g., for image, video, audio URLs)
                if (!$this->is_valid_domain($value)) {
                    return null; // Invalid URL
                }
                return $value; // Valid URL preserved
            }

            // Check if the string contains emojis
            if (preg_match('/[\p{So}\p{Sk}\p{Sm}\p{Sc}]/u', $value)) {
                $value = $this->sanitizeEmojis($value);
                if ($value === '') {
                    return null; // No valid content left after emoji sanitization
                }
            }

            // Additional sanitization for text content
            $value = $this->comprehensiveStringFilter($value);

            return $value;
        }

        if (is_numeric($jsonData)) {
            // Preserve numbers (e.g., x, y, size)
            return $jsonData + 0; // Convert to int/float as appropriate
        }

        if (is_bool($jsonData)) {
            // Preserve booleans
            return $jsonData;
        }

        // Drop anything else (e.g., resources, callables)
        return null;
    }

    /**
     * Sanitize a string containing emojis, removing invalid or injected emoji-like content
     *
     * @param string $input Input string potentially containing emojis
     * @return string Sanitized string with only valid emojis and text
     */
    private function sanitizeEmojis(string $input): string
    {
        // Use UTF8::clean to normalize encoding
        $input = $this->utf8->utf8_clean($input);

        // Define a pattern for valid Unicode emojis (covers most common emoji ranges)
        $validEmojiPattern = '/
            [\x{1F300}-\x{1F5FF}]|    # Miscellaneous Symbols and Pictographs
            [\x{1F600}-\x{1F64F}]|    # Emoticons
            [\x{1F680}-\x{1F6FF}]|    # Transport and Map Symbols
            [\x{1F900}-\x{1F9FF}]|    # Supplemental Symbols and Pictographs
            [\x{2600}-\x{26FF}]|      # Miscellaneous Symbols
            [\x{2700}-\x{27BF}]|      # Dingbats
            \x{200D}|                 # Zero Width Joiner (for combined emojis)
            \x{FE0F}                  # Variation Selector-16 (emoji presentation)
        /xu';

        // Split the string into characters (including multi-byte emojis)
        $characters = preg_split('//u', $input, -1, PREG_SPLIT_NO_EMPTY);
        $sanitized = '';

        foreach ($characters as $char) {
            if (preg_match($validEmojiPattern, $char)) {
                // Valid emoji, preserve it
                $sanitized .= $char;
            } elseif (preg_match('/[\p{L}\p{N}\p{Z}\p{P}]/u', $char)) {
                // Valid text (letters, numbers, spaces, punctuation), preserve it
                $sanitized .= $char;
            }
            // Drop anything else (e.g., injected control characters, invalid Unicode)
        }

        // Additional check for spoofing (requires intl extension)
        if (extension_loaded('intl')) {
            $checker = new Spoofchecker();
            $checker->setChecks(Spoofchecker::SINGLE_SCRIPT);
            if ($checker->isSuspicious($sanitized)) {
                // If the string looks suspicious (e.g., mixed scripts mimicking emojis), filter further
                $sanitized = preg_replace('/[^\p{L}\p{N}\p{Z}\p{P}' . $validEmojiPattern . ']/u', '', $sanitized);
            }
        }

        return $sanitized;
    }

    //Custom methods

    /**
     * Checks if the provided JSON data contains actual content beyond default values
     *
     * @param string|array $data The JSON string or decoded array to check
     * @return bool Returns true if the data contains actual content, false otherwise
     */
    public function hasActualContent($data) {
        // If data is a JSON string, decode it first
        if (is_string($data)) {
            $data = json_decode($data, true);

            // Return false if JSON is invalid
            if (json_last_error() !== JSON_ERROR_NONE) {
                return false;
            }
        }

        // Check if required fields exist
        if (!isset($data['background']) || !isset($data['textLayers']) ||
            !isset($data['emojiLayers']) || !isset($data['imageLayers'])) {
            return false;
        }

        // Check if any text layers exist
        if (count($data['textLayers']) > 0) {
            return true;
        }

        // Check if any emoji layers exist
        if (count($data['emojiLayers']) > 0) {
            return true;
        }

        // Check if any image layers exist
        if (count($data['imageLayers']) > 0) {
            return true;
        }

        // Check if background has a custom image
        if (isset($data['background']['image']) && $data['background']['image'] !== null) {
            return true;
        }

        // Check if background has a non-default color (assuming white is default)
        if (isset($data['background']['color']) && $data['background']['color'] !== "rgb(255, 255, 255)") {
            return true;
        }

        // Check if video exists
        if (isset($data['video']) && $data['video'] !== null) {
            return true;
        }

        // Check if audio exists
        if (isset($data['audio']) && $data['audio'] !== null) {
            return true;
        }

        // If we got here, the data only contains default values
        return false;
    }


}

<?php
namespace Manomite\Engine;

class SitemapGenerator {
    private $baseUrl;
    private $urls = [];
    private $visited = [];
    private $maxDepth = 3; // Reduced for performance
    private $timeout = 5; // Reduced timeout
    private $maxUrls = 1000; // Limit URLs to prevent timeouts
    private $userAgent = 'SitemapGeneratorBot/1.0';

    /**
     * Constructor
     * @param string $baseUrl The base URL to start crawling
     * @param int $maxDepth Maximum crawling depth
     * @param int $timeout HTTP request timeout in seconds
     */
    public function __construct(string $baseUrl, int $maxDepth = 3, int $timeout = 5) {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->maxDepth = $maxDepth;
        $this->timeout = $timeout;
    }

    /**
     * Crawl the website to find URLs
     * @param string $url Starting URL
     * @param int $depth Current crawling depth
     */
    public function crawl(string $url = null, int $depth = 0): void {
        if (count($this->urls) >= $this->maxUrls) {
            return;
        }

        if ($url === null) {
            $url = $this->baseUrl;
        }

        $url = $this->normalizeUrl($url);

        // Prevent infinite loops, respect max depth, and avoid duplicates
        if ($depth > $this->maxDepth || in_array($url, $this->visited) || $this->isExcludedUrl($url)) {
            return;
        }

        $this->visited[] = $url;

        try {
            $ch = curl_init();
            curl_setopt_array($ch, [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_MAXREDIRS => 5,
                CURLOPT_TIMEOUT => $this->timeout,
                CURLOPT_USERAGENT => $this->userAgent,
                CURLOPT_SSL_VERIFYPEER => false
            ]);

            $content = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);

            if ($httpCode >= 200 && $httpCode < 400 && $content !== false) {
                // Add URL to sitemap if it's within the same domain
                if ($this->isSameDomain($url)) {
                    $this->urls[$url] = [
                        'lastmod' => date('c'),
                        'priority' => $this->calculatePriority($depth),
                        'changefreq' => $this->calculateChangeFreq($url)
                    ];
                }

                // Extract links from content
                $links = $this->extractLinks($content, $url);
                foreach ($links as $link) {
                    $normalizedLink = $this->normalizeUrl($link);
                    if ($this->isValidUrl($normalizedLink) && $this->isSameDomain($normalizedLink)) {
                        $this->crawl($normalizedLink, $depth + 1);
                    }
                }
            }
        } catch (Exception $e) {
            error_log("SitemapGenerator: Error crawling $url: " . $e->getMessage());
        }
    }

    /**
     * Add a custom URL to the sitemap
     * @param string $url URL to add
     * @param float $priority Priority (0.0-1.0)
     * @param string $changefreq Change frequency
     * @param string|null $lastmod Last modification date
     */
    public function addUrl(string $url, float $priority = 0.5, string $changefreq = 'monthly', ?string $lastmod = null): void {
        $url = $this->normalizeUrl($url);
        if ($this->isValidUrl($url) && !$this->isExcludedUrl($url) && count($this->urls) < $this->maxUrls) {
            $this->urls[$url] = [
                'lastmod' => $lastmod ?? date('c'),
                'priority' => max(0.0, min(1.0, $priority)),
                'changefreq' => $this->validateChangeFreq($changefreq)
            ];
        }
    }

    /**
     * Generate XML sitemap
     * @return string XML sitemap content
     */
    public function generateSitemap(): string {
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . PHP_EOL;
        $xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . PHP_EOL;

        foreach ($this->urls as $url => $data) {
            $xml .= "\t<url>" . PHP_EOL;
            $xml .= "\t\t<loc>" . htmlspecialchars($url) . "</loc>" . PHP_EOL;
            $xml .= "\t\t<lastmod>" . $data['lastmod'] . "</lastmod>" . PHP_EOL;
            $xml .= "\t\t<changefreq>" . $data['changefreq'] . "</changefreq>" . PHP_EOL;
            $xml .= "\t\t<priority>" . number_format($data['priority'], 1) . "</priority>" . PHP_EOL;
            $xml .= "\t</url>" . PHP_EOL;
        }

        $xml .= '</urlset>';
        return $xml;
    }

    /**
     * Normalize URL to ensure consistency and resolve ../
     * @param string $url
     * @return string
     */
    private function normalizeUrl(string $url): string {
        $url = trim($url);

        // Handle fragment identifiers
        $url = preg_replace('/#.*$/', '', $url);

        // If URL is relative, resolve it against base URL
        if (!preg_match('/^https?:\/\//', $url)) {
            $url = rtrim($this->baseUrl, '/') . '/' . ltrim($url, '/');
        }

        // Parse URL components
        $parsedUrl = parse_url($url);
        if (!$parsedUrl) {
            return $url;
        }

        // Normalize path by resolving ../
        $path = isset($parsedUrl['path']) ? $parsedUrl['path'] : '';
        $pathParts = explode('/', trim($path, '/'));
        $newPathParts = [];
        foreach ($pathParts as $part) {
            if ($part === '..' && !empty($newPathParts)) {
                array_pop($newPathParts);
            } elseif ($part !== '.' && $part !== '') {
                $newPathParts[] = $part;
            }
        }
        $normalizedPath = '/' . implode('/', $newPathParts);

        // Reconstruct URL
        $normalizedUrl = $parsedUrl['scheme'] . '://' . $parsedUrl['host'];
        if (isset($parsedUrl['port'])) {
            $normalizedUrl .= ':' . $parsedUrl['port'];
        }
        $normalizedUrl .= $normalizedPath;

        // Handle query string
        if (isset($parsedUrl['query'])) {
            $normalizedUrl .= '?' . $parsedUrl['query'];
        }

        return rtrim($normalizedUrl, '/');
    }

    /**
     * Check if URL is valid
     * @param string $url
     * @return bool
     */
    private function isValidUrl(string $url): bool {
        return filter_var($url, FILTER_VALIDATE_URL) && !preg_match('/\.(pdf|jpg|jpeg|png|gif|css|js|xml|zip|rar|doc|docx)$/i', $url);
    }

    /**
     * Check if URL belongs to the same domain
     * @param string $url
     * @return bool
     */
    private function isSameDomain(string $url): bool {
        $baseHost = parse_url($this->baseUrl, PHP_URL_HOST);
        $urlHost = parse_url($url, PHP_URL_HOST);
        return $baseHost === $urlHost;
    }

    /**
     * Check if URL should be excluded
     * @param string $url
     * @return bool
     */
    private function isExcludedUrl(string $url): bool {
        $excludedPatterns = [
            '/login',
            '/recover-account',
            '/#'
        ];
        foreach ($excludedPatterns as $pattern) {
            if (preg_match($pattern, $url)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract links from HTML content
     * @param string $content
     * @param string $baseUrl
     * @return array
     */
    private function extractLinks(string $content, string $baseUrl): array {
        $links = [];
        preg_match_all('/<a\s+[^>]*href=["\'](.*?)["\']/i', $content, $matches);

        foreach ($matches[1] as $link) {
            $normalizedLink = $this->normalizeUrl($link);
            if ($this->isValidUrl($normalizedLink)) {
                $links[] = $normalizedLink;
            }
        }

        return array_unique($links);
    }

    /**
     * Calculate priority based on depth
     * @param int $depth
     * @return float
     */
    private function calculatePriority(int $depth): float {
        return max(0.1, 1.0 - ($depth * 0.1));
    }

    /**
     * Calculate change frequency based on URL
     * @param string $url
     * @return string
     */
    private function calculateChangeFreq(string $url): string {
        $path = parse_url($url, PHP_URL_PATH);
        if ($path === '/' || $path === '') {
            return 'daily';
        }
        if (strpos($path, 'blog') !== false) {
            return 'weekly';
        }
        if (strpos($path, 'property') !== false || strpos($path, 'listing') !== false) {
            return 'daily';
        }
        return 'monthly';
    }

    /**
     * Validate change frequency
     * @param string $freq
     * @return string
     */
    private function validateChangeFreq(string $freq): string {
        $validFreqs = ['always', 'hourly', 'daily', 'weekly', 'monthly', 'yearly', 'never'];
        return in_array($freq, $validFreqs) ? $freq : 'monthly';
    }
}
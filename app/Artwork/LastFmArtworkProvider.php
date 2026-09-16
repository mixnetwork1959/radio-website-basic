<?php
declare(strict_types=1);

namespace RadioWebsite\Artwork;

use RuntimeException;

final class LastFmArtworkProvider
{
    private bool $enabled;
    private string $apiKey;
    private int $timeout;
    private string $cacheDir;
    private int $positiveTtl;
    private int $negativeTtl;
    private string $userAgent;

    public function __construct(array $config)
    {
        $this->enabled = (bool) ($config['enabled'] ?? false);
        $this->apiKey = trim((string) ($config['api_key'] ?? ''));
        $this->timeout = max(2, min(20, (int) ($config['timeout'] ?? 6)));
        $this->cacheDir = (string) ($config['cache_dir'] ?? dirname(__DIR__, 2) . '/storage/cache/artwork');
        $this->positiveTtl = max(3600, (int) ($config['cache_ttl'] ?? 2592000)); // 30 days
        $this->negativeTtl = max(300, (int) ($config['negative_cache_ttl'] ?? 43200)); // 12 hours
        $this->userAgent = trim((string) ($config['user_agent'] ?? 'RadioWebsiteBasic/0.1.2'));
    }

    public function isConfigured(): bool
    {
        return $this->enabled && $this->apiKey !== '';
    }

    /** @return array{content:string,content_type:string,source:string,cached:bool}|null */
    public function artworkForTrack(array $track): ?array
    {
        if (!$this->isConfigured()) {
            return null;
        }

        $artist = trim((string) ($track['artist'] ?? ''));
        $title = trim((string) ($track['title'] ?? ''));
        $album = trim((string) ($track['album'] ?? ''));

        if ($artist === '' || $title === '') {
            return null;
        }

        $identity = $artist . "\n" . $title . "\n" . $album;
        $identity = function_exists('mb_strtolower') ? mb_strtolower($identity, 'UTF-8') : strtolower($identity);
        $key = hash('sha256', $identity);
        $cached = $this->readCache($key);
        if ($cached !== null) {
            return $cached === false ? null : $cached;
        }

        $imageUrl = $this->trackImageUrl($artist, $title);
        if ($imageUrl === null && $album !== '') {
            $imageUrl = $this->albumImageUrl($artist, $album);
        }

        if ($imageUrl === null) {
            $this->writeNegativeCache($key);
            return null;
        }

        $image = $this->downloadImage($imageUrl);
        if ($image === null) {
            $this->writeNegativeCache($key);
            return null;
        }

        $this->writePositiveCache($key, $image['content'], $image['content_type']);

        return [
            'content' => $image['content'],
            'content_type' => $image['content_type'],
            'source' => 'lastfm',
            'cached' => false,
        ];
    }

    private function trackImageUrl(string $artist, string $title): ?string
    {
        $json = $this->apiRequest([
            'method' => 'track.getInfo',
            'artist' => $artist,
            'track' => $title,
            'autocorrect' => '1',
            'format' => 'json',
        ]);

        return $this->bestImageUrl($json['track']['album']['image'] ?? null);
    }

    private function albumImageUrl(string $artist, string $album): ?string
    {
        $json = $this->apiRequest([
            'method' => 'album.getInfo',
            'artist' => $artist,
            'album' => $album,
            'autocorrect' => '1',
            'format' => 'json',
        ]);

        return $this->bestImageUrl($json['album']['image'] ?? null);
    }

    private function apiRequest(array $parameters): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is unavailable.');
        }

        $parameters['api_key'] = $this->apiKey;
        $url = 'https://ws.audioscrobbler.com/2.0/?' . http_build_query($parameters, '', '&', PHP_QUERY_RFC3986);

        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Could not initialize Last.fm connection.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: ' . $this->userAgent,
                'Connection: close',
            ],
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Last.fm connection failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Last.fm returned HTTP status ' . $status . '.');
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Last.fm returned invalid JSON.');
        }
        if (isset($decoded['error'])) {
            $errorCode = (int) $decoded['error'];
            // Missing/unknown resources are normal for less common tracks and
            // should simply continue to the next artwork fallback.
            if (in_array($errorCode, [6, 7], true)) {
                return [];
            }
            throw new RuntimeException('Last.fm error: ' . (string) ($decoded['message'] ?? $decoded['error']));
        }

        return $decoded;
    }

    private function bestImageUrl(mixed $images): ?string
    {
        if (!is_array($images)) {
            return null;
        }

        $priority = ['mega' => 5, 'extralarge' => 4, 'large' => 3, 'medium' => 2, 'small' => 1];
        $candidates = [];

        foreach ($images as $image) {
            if (!is_array($image)) {
                continue;
            }
            $url = trim((string) ($image['#text'] ?? ''));
            if ($url === '' || !preg_match('#^https?://#i', $url)) {
                continue;
            }
            $size = strtolower(trim((string) ($image['size'] ?? '')));
            $candidates[] = ['url' => $url, 'score' => $priority[$size] ?? 0];
        }

        if ($candidates === []) {
            return null;
        }

        usort($candidates, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);
        return (string) $candidates[0]['url'];
    }

    /** @return array{content:string,content_type:string}|null */
    private function downloadImage(string $url): ?array
    {
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        if (!in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $curl = curl_init();
        if ($curl === false) {
            return null;
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPHEADER => [
                'Accept: image/*,*/*;q=0.8',
                'User-Agent: ' . $this->userAgent,
                'Connection: close',
            ],
        ]);

        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = trim((string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE));
        curl_close($curl);

        if ($body === false || $status < 200 || $status >= 300 || strlen((string) $body) < 256) {
            return null;
        }

        $contentType = $this->normalizeImageType((string) $body, $contentType);
        if ($contentType === '') {
            return null;
        }

        return ['content' => (string) $body, 'content_type' => $contentType];
    }

    /** @return array{content:string,content_type:string,source:string,cached:bool}|false|null */
    private function readCache(string $key): array|false|null
    {
        $metaFile = $this->cacheDir . '/' . $key . '.json';
        if (!is_file($metaFile)) {
            return null;
        }

        $meta = json_decode((string) @file_get_contents($metaFile), true);
        if (!is_array($meta) || (int) ($meta['expires_at'] ?? 0) <= time()) {
            @unlink($metaFile);
            @unlink($this->cacheDir . '/' . $key . '.bin');
            return null;
        }

        if (($meta['status'] ?? '') === 'miss') {
            return false;
        }

        $bodyFile = $this->cacheDir . '/' . $key . '.bin';
        if (!is_file($bodyFile)) {
            @unlink($metaFile);
            return null;
        }

        $content = @file_get_contents($bodyFile);
        if ($content === false || $content === '') {
            return null;
        }

        return [
            'content' => $content,
            'content_type' => (string) ($meta['content_type'] ?? 'image/jpeg'),
            'source' => 'lastfm',
            'cached' => true,
        ];
    }

    private function writePositiveCache(string $key, string $content, string $contentType): void
    {
        if (!$this->ensureCacheDir()) {
            return;
        }

        @file_put_contents($this->cacheDir . '/' . $key . '.bin', $content, LOCK_EX);
        @file_put_contents(
            $this->cacheDir . '/' . $key . '.json',
            json_encode([
                'status' => 'hit',
                'content_type' => $contentType,
                'expires_at' => time() + $this->positiveTtl,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }

    private function writeNegativeCache(string $key): void
    {
        if (!$this->ensureCacheDir()) {
            return;
        }

        @file_put_contents(
            $this->cacheDir . '/' . $key . '.json',
            json_encode([
                'status' => 'miss',
                'expires_at' => time() + $this->negativeTtl,
            ], JSON_UNESCAPED_SLASHES) . PHP_EOL,
            LOCK_EX
        );
    }

    private function ensureCacheDir(): bool
    {
        if (is_dir($this->cacheDir)) {
            return is_writable($this->cacheDir);
        }
        return @mkdir($this->cacheDir, 0750, true) || is_dir($this->cacheDir);
    }

    private function normalizeImageType(string $data, string $contentType): string
    {
        $contentType = strtolower(trim(explode(';', $contentType, 2)[0] ?? ''));
        if (in_array($contentType, ['image/jpeg', 'image/png', 'image/gif', 'image/webp'], true)) {
            return $contentType;
        }
        if (str_starts_with($data, "\xFF\xD8\xFF")) {
            return 'image/jpeg';
        }
        if (str_starts_with($data, "\x89PNG\r\n\x1A\n")) {
            return 'image/png';
        }
        if (str_starts_with($data, 'GIF87a') || str_starts_with($data, 'GIF89a')) {
            return 'image/gif';
        }
        if (strlen($data) > 12 && substr($data, 0, 4) === 'RIFF' && substr($data, 8, 4) === 'WEBP') {
            return 'image/webp';
        }
        return '';
    }
}

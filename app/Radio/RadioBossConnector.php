<?php
declare(strict_types=1);

namespace RadioWebsite\Radio;

use RadioWebsite\Contracts\RadioSourceInterface;
use RuntimeException;
use SimpleXMLElement;
use Throwable;

final class RadioBossConnector implements RadioSourceInterface
{
    private string $baseUrl;
    private string $username;
    private string $password;
    private int $timeout;

    public function __construct(array $config)
    {
        $this->baseUrl = rtrim(trim((string) ($config['url'] ?? '')), '/') . '/';
        $this->username = trim((string) ($config['username'] ?? ''));
        $this->password = (string) ($config['password'] ?? '');
        $this->timeout = max(1, min(30, (int) ($config['timeout'] ?? 5)));

        if ($this->baseUrl === '/' || !preg_match('#^https?://#i', $this->baseUrl)) {
            throw new RuntimeException('Invalid RadioBOSS API URL.');
        }
    }

    public function playbackInfo(): array
    {
        $xml = $this->requestXml(['action' => 'playbackinfo']);

        $current = $this->trackFromNode($xml->CurrentTrack->TRACK ?? null);
        $next = $this->trackFromNode($xml->NextTrack->TRACK ?? null);
        $previous = $this->trackFromNode($xml->PrevTrack->TRACK ?? null);

        $listeners = 0;
        if (isset($xml->Streaming)) {
            $listeners = (int) ($xml->Streaming['listeners'] ?? 0);
        }
        if ($listeners <= 0 && isset($xml->CurrentTrack->TRACK)) {
            $listeners = (int) ($xml->CurrentTrack->TRACK['LISTENERS'] ?? 0);
        }

        $playback = [
            'state' => (string) ($xml->Playback['state'] ?? ''),
            'position' => (int) ($xml->Playback['pos'] ?? 0),
            'length' => (int) ($xml->Playback['len'] ?? 0),
            'time_left' => (int) ($xml->Playback['playingtimeleft'] ?? 0),
            'timestamp' => (string) ($xml->Playback['timestamp'] ?? ''),
        ];

        return [
            'current' => $current,
            'next' => $next,
            'previous' => $previous,
            'listeners' => max(0, $listeners),
            'playback' => $playback,
        ];
    }

    public function recentlyPlayed(int $limit = 5): array
    {
        $limit = max(1, min(20, $limit));
        $xml = $this->requestXml(['action' => 'getlastplayed', 'filter' => '1']);
        $tracks = [];

        foreach ($xml->TRACK ?? [] as $trackNode) {
            $track = $this->trackFromNode($trackNode);
            if ($track['artist'] === '' && $track['title'] === '') {
                continue;
            }
            $tracks[] = $track;
            if (count($tracks) >= $limit) {
                break;
            }
        }

        return $tracks;
    }

    public function artwork(string $which = 'current'): ?array
    {
        $action = match ($which) {
            'next' => 'nexttrackartwork',
            'previous', 'prev' => 'prevtrackartwork',
            default => 'trackartwork',
        };

        $response = $this->requestRaw(['action' => $action], true);
        if ($response['body'] === '') {
            return null;
        }

        $contentType = trim((string) ($response['content_type'] ?? ''));
        if ($contentType === '' || str_contains(strtolower($contentType), 'text/')) {
            $contentType = $this->detectImageType($response['body']);
        }

        if ($contentType === '') {
            return null;
        }

        return [
            'content' => $response['body'],
            'content_type' => $contentType,
        ];
    }

    private function requestXml(array $parameters): SimpleXMLElement
    {
        $response = $this->requestRaw($parameters, false);
        $body = trim($response['body']);
        if ($body === '') {
            throw new RuntimeException('RadioBOSS returned an empty response.');
        }

        $previous = libxml_use_internal_errors(true);
        try {
            $xml = simplexml_load_string($body);
            if ($xml === false) {
                throw new RuntimeException('RadioBOSS returned invalid XML.');
            }
            return $xml;
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }
    }

    /** @return array{body:string, content_type:string} */
    private function requestRaw(array $parameters, bool $binary): array
    {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is unavailable.');
        }

        $query = ['pass' => $this->password];
        if ($this->username !== '') {
            $query['user'] = $this->username;
        }
        $query = array_merge($query, $parameters);
        $url = $this->baseUrl . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);

        $curl = curl_init();
        if ($curl === false) {
            throw new RuntimeException('Could not initialize RadioBOSS connection.');
        }

        curl_setopt_array($curl, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $this->timeout,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_HTTPGET => true,
            CURLOPT_HTTPHEADER => [$binary ? 'Accept: image/*,*/*;q=0.8' : 'Accept: application/xml,text/xml,text/plain', 'Connection: close'],
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $contentType = (string) curl_getinfo($curl, CURLINFO_CONTENT_TYPE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('RadioBOSS connection failed: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('RadioBOSS returned HTTP status ' . $status . '.');
        }

        return ['body' => (string) $body, 'content_type' => $contentType];
    }

    private function trackFromNode(mixed $node): array
    {
        if (!$node instanceof SimpleXMLElement) {
            return $this->emptyTrack();
        }

        $attributes = $node->attributes();
        $artist = trim((string) ($attributes['ARTIST'] ?? ''));
        $title = trim((string) ($attributes['TITLE'] ?? ''));
        $castTitle = trim((string) ($attributes['CASTTITLE'] ?? ''));

        if ($title === '' && $castTitle !== '') {
            [$artistGuess, $titleGuess] = $this->splitCastTitle($castTitle);
            $artist = $artist !== '' ? $artist : $artistGuess;
            $title = $titleGuess;
        }

        return [
            'artist' => $artist,
            'title' => $title,
            'album' => trim((string) ($attributes['ALBUM'] ?? '')),
            'year' => trim((string) ($attributes['YEAR'] ?? '')),
            'genre' => trim((string) ($attributes['GENRE'] ?? '')),
            'duration' => trim((string) ($attributes['DURATION'] ?? '')),
            'start_time' => trim((string) ($attributes['STARTTIME'] ?? '')),
            'cast_title' => $castTitle,
        ];
    }

    private function emptyTrack(): array
    {
        return ['artist' => '', 'title' => '', 'album' => '', 'year' => '', 'genre' => '', 'duration' => '', 'start_time' => '', 'cast_title' => ''];
    }

    /** @return array{0:string,1:string} */
    private function splitCastTitle(string $value): array
    {
        $parts = preg_split('/\s+-\s+/u', $value, 2);
        if (is_array($parts) && count($parts) === 2) {
            return [trim($parts[0]), trim($parts[1])];
        }
        return ['', trim($value)];
    }

    private function detectImageType(string $data): string
    {
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

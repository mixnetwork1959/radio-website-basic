<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use RadioWebsite\Artwork\LastFmArtworkProvider;
use RadioWebsite\Radio\RadioBossConnector;

function placeholder(): never
{
    $file = __DIR__ . '/assets/img/cover-placeholder.svg';
    header('Content-Type: image/svg+xml; charset=utf-8');
    header('Cache-Control: public, max-age=60');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

function outputArtwork(array $art): never
{
    header('Content-Type: ' . (string) $art['content_type']);
    header('Cache-Control: public, max-age=300');
    header('X-Content-Type-Options: nosniff');
    echo (string) $art['content'];
    exit;
}

if (!is_installed()) {
    placeholder();
}

$config = load_config();
$which = (string) ($_GET['which'] ?? 'current');
if (!in_array($which, ['current', 'next', 'previous', 'prev'], true)) {
    $which = 'current';
}

// Historical tracks can request artwork by artist/title. This is used for
// Recently Played cards. Values are bounded because this is a public endpoint.
function artworkParam(string $name, int $limit): string
{
    $value = trim((string) ($_GET[$name] ?? ''));
    return function_exists('mb_substr')
        ? mb_substr($value, 0, $limit, 'UTF-8')
        : substr($value, 0, $limit);
}

$requestedTrack = [
    'artist' => artworkParam('artist', 180),
    'title' => artworkParam('title', 220),
    'album' => artworkParam('album', 220),
];
$historicalRequest = $requestedTrack['artist'] !== '' || $requestedTrack['title'] !== '';

try {
    $connector = new RadioBossConnector((array) ($config['radioboss'] ?? []));

    // Direct RadioBOSS artwork is only meaningful for current/next/previous.
    if (!$historicalRequest) {
        try {
            $art = $connector->artwork($which);
            if ($art !== null) {
                outputArtwork($art);
            }
        } catch (Throwable) {
            // Continue to the optional Last.fm fallback below.
        }
    }

    $lastFmConfig = (array) ($config['artwork']['lastfm'] ?? []);
    $lastFmConfig['cache_dir'] = app_root('storage/cache/artwork');
    $lastFmConfig['user_agent'] = 'RadioWebsiteBasic/' . RADIO_WEBSITE_VERSION;
    $provider = new LastFmArtworkProvider($lastFmConfig);

    if ($provider->isConfigured()) {
        if ($historicalRequest) {
            $track = $requestedTrack;
        } else {
            $playback = $connector->playbackInfo();
            $key = match ($which) {
                'next' => 'next',
                'previous', 'prev' => 'previous',
                default => 'current',
            };
            $track = (array) ($playback[$key] ?? []);
        }

        $art = $provider->artworkForTrack($track);
        if ($art !== null) {
            outputArtwork($art);
        }
    }
} catch (Throwable) {
    // Public artwork endpoint always fails gracefully to the local placeholder.
}

placeholder();

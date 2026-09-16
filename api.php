<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use RadioWebsite\Radio\RadioBossConnector;

if (!is_installed()) {
    json_response(['success' => false, 'message' => 'Website is not configured.'], 503);
}

$config = load_config();
$action = (string) ($_GET['action'] ?? 'status');

function calendar_source(array $config): string
{
    $calendar = (array) ($config['public_calendar'] ?? []);
    $configured = trim((string) ($calendar['url'] ?? ''));

    if ((bool) ($calendar['enabled'] ?? false) && $configured !== '') {
        return $configured;
    }

    if (is_file(app_root('calendar/schedule.json'))) {
        return 'calendar/schedule.json';
    }

    return '';
}

function local_calendar_path(string $source): string
{
    $pathOnly = (string) parse_url($source, PHP_URL_PATH);
    $relative = ltrim(str_replace('\\', '/', $pathOnly), '/');

    if ($relative === '' || str_contains($relative, "\0")) {
        throw new RuntimeException('Public calendar JSON path is invalid.');
    }

    $root = realpath(app_root());
    $candidate = realpath(app_root($relative));

    if ($root === false || $candidate === false || !str_starts_with($candidate, $root . DIRECTORY_SEPARATOR)) {
        throw new RuntimeException('Public calendar JSON file is outside the website directory or does not exist.');
    }

    return $candidate;
}

function fetch_calendar_json(string $source): string
{
    if (preg_match('#^https?://#i', $source)) {
        if (!function_exists('curl_init')) {
            throw new RuntimeException('PHP cURL extension is unavailable.');
        }

        $curl = curl_init($source);
        if ($curl === false) {
            throw new RuntimeException('Calendar connection could not be initialized.');
        }

        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 8,
            CURLOPT_HTTPHEADER => [
                'Accept: application/json',
                'User-Agent: RadioWebsiteBasic/' . RADIO_WEBSITE_VERSION,
            ],
        ]);

        $body = curl_exec($curl);
        $error = curl_error($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException('Public calendar could not be loaded: ' . $error);
        }
        if ($status < 200 || $status >= 300) {
            throw new RuntimeException('Public calendar returned HTTP status ' . $status . '.');
        }

        return (string) $body;
    }

    $path = local_calendar_path($source);
    $body = file_get_contents($path);

    if ($body === false) {
        throw new RuntimeException('Public calendar JSON file could not be read.');
    }

    return $body;
}

function calendar_time_from_iso(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('H:i');
    } catch (Throwable) {
        return '';
    }
}

function calendar_day_from_iso(mixed $value): string
{
    $raw = trim((string) $value);
    if ($raw === '') {
        return '';
    }

    try {
        return (new DateTimeImmutable($raw))->format('l');
    } catch (Throwable) {
        return '';
    }
}

function normalize_calendar_items(mixed $decoded): array
{
    if (!is_array($decoded)) {
        return [];
    }

    if (isset($decoded['programs']) && is_array($decoded['programs'])) {
        $items = $decoded['programs'];
    } elseif (isset($decoded['schedule']) && is_array($decoded['schedule'])) {
        $items = $decoded['schedule'];
    } else {
        $items = array_is_list($decoded) ? $decoded : [];
    }

    $normalized = [];

    foreach (array_slice($items, 0, 300) as $item) {
        if (!is_array($item)) {
            continue;
        }

        $title = trim((string) ($item['title'] ?? $item['name'] ?? ''));
        if ($title === '') {
            continue;
        }

        $day = trim((string) ($item['day'] ?? ''));
        if ($day === '') {
            $day = calendar_day_from_iso($item['start'] ?? '');
        }

        $start = trim((string) ($item['start_time'] ?? $item['startTime'] ?? ''));
        if ($start === '') {
            $start = calendar_time_from_iso($item['start'] ?? '');
        }

        $end = trim((string) ($item['end_time'] ?? $item['endTime'] ?? ''));
        if ($end === '') {
            $end = calendar_time_from_iso($item['end'] ?? '');
        }

        $normalized[] = [
            'day' => $day !== '' ? $day : 'Other',
            'start' => $start,
            'end' => $end,
            'title' => $title,
            'description' => trim((string) ($item['description'] ?? '')),
            'color' => trim((string) ($item['color'] ?? '')),
        ];
    }

    return $normalized;
}

try {
    if ($action === 'calendar' || $action === 'schedule') {
        $source = calendar_source($config);

        // Legacy v0.1.3 schedule.json is still understood for an upgraded
        // installation, but no new manual demo schedule is created.
        if ($source === '' && is_file(app_root('storage/schedule.json'))) {
            $source = 'storage/schedule.json';
        }

        if ($source === '') {
            json_response(['success' => true, 'schedule' => []]);
        }

        $path = strtolower((string) parse_url($source, PHP_URL_PATH));
        if (!str_ends_with($path, '.json')) {
            throw new RuntimeException('The configured public calendar is an HTML export and is displayed directly by the website.');
        }

        $decoded = json_decode(fetch_calendar_json($source), true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            throw new RuntimeException('Public calendar contains invalid JSON.');
        }

        $schedule = normalize_calendar_items($decoded);

        // v0.1.0-v0.1.2 accidentally installed two demonstration shows as
        // real public schedule data. Treat that exact legacy sample as empty.
        $legacyDemo = [
            ['day' => 'Monday', 'start' => '06:00', 'end' => '10:00', 'title' => 'Morning Show', 'description' => '', 'color' => ''],
            ['day' => 'Friday', 'start' => '18:00', 'end' => '22:00', 'title' => 'Friday Night', 'description' => '', 'color' => ''],
        ];
        if ($schedule === $legacyDemo) {
            $schedule = [];
        }

        json_response(['success' => true, 'schedule' => $schedule]);
    }

    if (($config['source'] ?? '') !== 'radioboss') {
        throw new RuntimeException('Unsupported radio source.');
    }

    $connector = new RadioBossConnector((array) ($config['radioboss'] ?? []));
    $playback = $connector->playbackInfo();
    $recent = $connector->recentlyPlayed(6);

    json_response([
        'success' => true,
        'station' => [
            'name' => (string) ($config['station']['name'] ?? ''),
            'tagline' => (string) ($config['station']['tagline'] ?? ''),
        ],
        'current' => $playback['current'],
        'next' => $playback['next'],
        'previous' => $playback['previous'],
        'listeners' => $playback['listeners'],
        'playback' => $playback['playback'],
        'recent' => $recent,
        'server_time' => date(DATE_ATOM),
    ]);
} catch (Throwable $e) {
    json_response(['success' => false, 'message' => $e->getMessage()], 502);
}

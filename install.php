<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use RadioWebsite\Radio\RadioBossConnector;
use RadioWebsite\Artwork\LastFmArtworkProvider;

session_start();

if (is_installed()) {
    header('Location: index.php');
    exit;
}

if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$notice = '';
$testResult = null;

$defaults = [
    'station_name' => 'My Radio Station',
    'tagline' => 'Your music. Your station.',
    'welcome_title' => '',
    'welcome_text' => '',
    'stream_url' => '',
    'api_url' => 'http://127.0.0.1:9000/',
    'api_username' => '',
    'api_password' => '',
    'api_timeout' => '5',
    'lastfm_enabled' => '1',
    'lastfm_api_key' => '',
    'primary_color' => '#14171f',
    'accent_color' => '#ff5b36',
    'facebook' => '',
    'instagram' => '',
    'youtube' => '',
    'tiktok' => '',
    'public_calendar_enabled' => '0',
    'public_calendar_url' => 'calendar/index.html',
    'contact_email' => '',
    'contact_text' => '',
    'privacy_text' => '',
    'cookies_text' => '',
    'legal_text' => '',
];

$data = array_merge($defaults, array_map(static fn($v) => is_string($v) ? trim($v) : $v, $_POST));
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data['lastfm_enabled'] = isset($_POST['lastfm_enabled']) ? '1' : '0';
    $data['public_calendar_enabled'] = isset($_POST['public_calendar_enabled']) ? '1' : '0';
}

function validHttpUrl(string $url): bool
{
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return false;
    }
    $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
    return in_array($scheme, ['http', 'https'], true);
}

function validPublicCalendarSource(string $source): bool
{
    $source = trim($source);
    if ($source === '' || str_contains($source, "\0")) {
        return false;
    }

    if (preg_match('#^https?://#i', $source)) {
        if (!validHttpUrl($source)) {
            return false;
        }
    } elseif (
        str_starts_with($source, '/') ||
        str_starts_with($source, '\\') ||
        preg_match('/^[A-Za-z]:[\\\\\/]/', $source) ||
        str_contains(str_replace('\\', '/', $source), '../')
    ) {
        return false;
    }

    $path = strtolower((string) parse_url($source, PHP_URL_PATH));
    return str_ends_with($path, '.html')
        || str_ends_with($path, '.htm')
        || str_ends_with($path, '.json');
}

function saveLogo(array $file): string
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return '';
    }
    if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Logo upload failed.');
    }
    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        throw new RuntimeException('Logo is larger than 2 MB.');
    }

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = $finfo->file((string) $file['tmp_name']);
    $allowed = ['image/png' => 'png', 'image/jpeg' => 'jpg', 'image/webp' => 'webp'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('Logo must be PNG, JPG or WEBP.');
    }

    $targetDir = app_root('assets/uploads');
    if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true) && !is_dir($targetDir)) {
        throw new RuntimeException('Upload folder could not be created.');
    }
    $filename = 'station-logo.' . $allowed[$mime];
    $target = $targetDir . '/' . $filename;
    if (!move_uploaded_file((string) $file['tmp_name'], $target)) {
        throw new RuntimeException('Logo could not be saved.');
    }
    return 'assets/uploads/' . $filename;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string) $_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        $errors[] = 'Session expired. Reload the installer and try again.';
    }

    if ($data['station_name'] === '') {
        $errors[] = 'Station name is required.';
    }
    if (!validHttpUrl($data['stream_url'])) {
        $errors[] = 'Enter a valid stream URL beginning with http:// or https://.';
    }
    if (!validHttpUrl($data['api_url'])) {
        $errors[] = 'Enter a valid RadioBOSS API URL.';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $data['primary_color'])) {
        $errors[] = 'Primary color is invalid.';
    }
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $data['accent_color'])) {
        $errors[] = 'Accent color is invalid.';
    }

    if ($data['contact_email'] !== '' && !filter_var($data['contact_email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Contact email address is invalid.';
    }

    if (
        $data['public_calendar_enabled'] === '1' &&
        !validPublicCalendarSource((string) $data['public_calendar_url'])
    ) {
        $errors[] = 'Enter a valid BroadcastScheduler calendar path or URL ending in .html, .htm or .json.';
    }

    $button = (string) ($_POST['submit_action'] ?? '');

    if ($errors === [] && $button === 'test_lastfm') {
        if ($data['lastfm_api_key'] === '') {
            $testResult = ['ok' => false, 'message' => 'Enter a Last.fm API key first.', 'detail' => ''];
        } else {
            try {
                $connector = new RadioBossConnector([
                    'url' => $data['api_url'],
                    'username' => $data['api_username'],
                    'password' => $data['api_password'],
                    'timeout' => (int) $data['api_timeout'],
                ]);
                $info = $connector->playbackInfo();
                $track = (array) ($info['current'] ?? []);
                $provider = new LastFmArtworkProvider([
                    'enabled' => true,
                    'api_key' => $data['lastfm_api_key'],
                    'timeout' => 6,
                    'cache_dir' => app_root('storage/cache/artwork'),
                    'user_agent' => 'RadioWebsiteBasic/' . RADIO_WEBSITE_VERSION,
                ]);
                $art = $provider->artworkForTrack($track);
                $label = trim((string) ($track['artist'] ?? '') . ' - ' . (string) ($track['title'] ?? ''), ' -');
                $testResult = $art !== null
                    ? ['ok' => true, 'message' => 'Last.fm artwork found.', 'detail' => $label]
                    : ['ok' => false, 'message' => 'Last.fm did not return artwork for the current track.', 'detail' => $label];
            } catch (Throwable $e) {
                $testResult = ['ok' => false, 'message' => 'Last.fm test failed: ' . $e->getMessage(), 'detail' => ''];
            }
        }
    }

    if ($errors === [] && $button === 'test') {
        try {
            $connector = new RadioBossConnector([
                'url' => $data['api_url'],
                'username' => $data['api_username'],
                'password' => $data['api_password'],
                'timeout' => (int) $data['api_timeout'],
            ]);
            $info = $connector->playbackInfo();
            $testResult = [
                'ok' => true,
                'message' => 'RadioBOSS connection successful.',
                'detail' => trim(($info['current']['artist'] ?? '') . ' - ' . ($info['current']['title'] ?? ''), ' -'),
            ];
        } catch (Throwable $e) {
            $testResult = ['ok' => false, 'message' => 'RadioBOSS test failed: ' . $e->getMessage(), 'detail' => ''];
        }
    }

    if ($errors === [] && $button === 'install') {
        try {
            $logoPath = saveLogo($_FILES['logo'] ?? []);
            $config = [
                'version' => RADIO_WEBSITE_VERSION,
                'station' => [
                    'name' => $data['station_name'],
                    'tagline' => $data['tagline'],
                    'logo' => $logoPath,
                ],
                'homepage' => [
                    'welcome_title' => trim((string) $data['welcome_title']),
                    'welcome_text' => trim((string) $data['welcome_text']),
                ],
                'stream' => ['url' => $data['stream_url']],
                'source' => 'radioboss',
                'radioboss' => [
                    'url' => rtrim($data['api_url'], '/') . '/',
                    'username' => $data['api_username'],
                    'password' => $data['api_password'],
                    'timeout' => max(1, min(30, (int) $data['api_timeout'])),
                ],
                'artwork' => [
                    'lastfm' => [
                        'enabled' => $data['lastfm_enabled'] === '1' && $data['lastfm_api_key'] !== '',
                        'api_key' => $data['lastfm_api_key'],
                        'timeout' => 6,
                        'cache_ttl' => 2592000,
                        'negative_cache_ttl' => 43200,
                    ],
                ],
                'theme' => [
                    'primary' => strtolower($data['primary_color']),
                    'accent' => strtolower($data['accent_color']),
                ],
                'social' => [
                    'facebook' => $data['facebook'],
                    'instagram' => $data['instagram'],
                    'youtube' => $data['youtube'],
                    'tiktok' => $data['tiktok'],
                ],
                'public_calendar' => [
                    'enabled' => $data['public_calendar_enabled'] === '1',
                    'provider' => 'broadcastscheduler',
                    'url' => trim((string) $data['public_calendar_url']),
                ],
                'website_info' => [
                    'contact_email' => trim((string) $data['contact_email']),
                    'contact_text' => trim((string) $data['contact_text']),
                    'privacy_text' => trim((string) $data['privacy_text']),
                    'cookies_text' => trim((string) $data['cookies_text']),
                    'legal_text' => trim((string) $data['legal_text']),
                ],
                'modules' => [
                    'song_request' => false,
                    'top20' => false,
                ],
            ];

            $storage = app_root('storage');
            if (!is_dir($storage) && !mkdir($storage, 0750, true) && !is_dir($storage)) {
                throw new RuntimeException('Storage directory could not be created.');
            }
            if (!is_writable($storage)) {
                throw new RuntimeException('Storage directory is not writable.');
            }

            $configPhp = "<?php\ndeclare(strict_types=1);\n\nreturn " . var_export($config, true) . ";\n";
            if (file_put_contents($storage . '/config.php', $configPhp, LOCK_EX) === false) {
                throw new RuntimeException('Configuration could not be written.');
            }
            @chmod($storage . '/config.php', 0640);

            file_put_contents($storage . '/installed.lock', 'Installed ' . date(DATE_ATOM) . PHP_EOL, LOCK_EX);

            header('Location: index.php?installed=1');
            exit;
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }
}

$requirements = [
    ['PHP 8.1+', version_compare(PHP_VERSION, '8.1.0', '>=')],
    ['cURL extension', function_exists('curl_init')],
    ['SimpleXML extension', function_exists('simplexml_load_string')],
    ['Fileinfo extension', class_exists('finfo')],
    ['storage/ writable', is_writable(app_root('storage'))],
    ['assets/uploads/ writable', is_writable(app_root('assets/uploads'))],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Radio Website Basic Setup</title>
<link rel="stylesheet" href="assets/css/app.css">
</head>
<body class="installer-body">
<main class="installer-shell">
    <section class="installer-card">
        <div class="eyebrow">Radio Website Basic · v<?= e(RADIO_WEBSITE_VERSION) ?></div>
        <h1>Setup Wizard</h1>
        <p class="muted">Configure the station, RadioBOSS connection, public player and optional BroadcastScheduler programme calendar. No WordPress or database required.</p>

        <div class="requirements">
            <?php foreach ($requirements as [$label, $ok]): ?>
                <div class="requirement <?= $ok ? 'ok' : 'bad' ?>"><span><?= $ok ? '✓' : '!' ?></span><?= e($label) ?></div>
            <?php endforeach; ?>
        </div>

        <?php if ($errors): ?>
            <div class="alert alert-error"><strong>Check these items:</strong><ul><?php foreach ($errors as $error): ?><li><?= e($error) ?></li><?php endforeach; ?></ul></div>
        <?php endif; ?>
        <?php if ($testResult): ?>
            <div class="alert <?= $testResult['ok'] ? 'alert-success' : 'alert-error' ?>">
                <strong><?= e($testResult['message']) ?></strong>
                <?php if ($testResult['detail'] !== ''): ?><div><?= e($testResult['detail']) ?></div><?php endif; ?>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="setup-form">
            <input type="hidden" name="csrf" value="<?= e((string) $_SESSION['csrf']) ?>">

            <h2>Station</h2>
            <div class="form-grid two">
                <label>Station name<input name="station_name" value="<?= e($data['station_name']) ?>" required></label>
                <label>Tagline<input name="tagline" value="<?= e($data['tagline']) ?>"></label>
                <label class="span-2">Stream URL<input name="stream_url" type="url" placeholder="https://stream.example.com/radio.mp3" value="<?= e($data['stream_url']) ?>" required></label>
                <label>Station logo <small>PNG/JPG/WEBP, max 2 MB</small><input name="logo" type="file" accept="image/png,image/jpeg,image/webp"></label>
                <label>Primary color<input name="primary_color" type="color" value="<?= e($data['primary_color']) ?>"></label>
                <label>Accent color<input name="accent_color" type="color" value="<?= e($data['accent_color']) ?>"></label>
            </div>

            <h2>Homepage Welcome</h2>
            <p class="hint">Optional. Use this space for your own station introduction or welcome message. If both fields are empty, the entire section is hidden and no blank space is shown.</p>
            <div class="form-grid two">
                <label class="span-2">Welcome title <small>Optional</small><input name="welcome_title" value="<?= e($data['welcome_title']) ?>" placeholder="Welcome to My Radio Station"></label>
                <label class="span-2">Welcome text <small>Optional</small><textarea name="welcome_text" rows="6" placeholder="Introduce your station, your music or your local service in your own words."><?= e($data['welcome_text']) ?></textarea></label>
            </div>

            <h2>RadioBOSS API</h2>
            <div class="form-grid two">
                <label class="span-2">API URL<input name="api_url" type="url" value="<?= e($data['api_url']) ?>" required><small>Example: http://your-host:9000/</small></label>
                <label>API username <small>Optional</small><input name="api_username" value="<?= e($data['api_username']) ?>"></label>
                <label>API password<input name="api_password" type="password" value="<?= e($data['api_password']) ?>"></label>
                <label>Timeout (seconds)<input name="api_timeout" type="number" min="1" max="30" value="<?= e($data['api_timeout']) ?>"></label>
            </div>
            <button class="button button-secondary" type="submit" name="submit_action" value="test">Test RadioBOSS Connection</button>

            <h2>Artwork fallback</h2>
            <div class="form-grid two">
                <label class="span-2 checkbox-row"><input name="lastfm_enabled" type="checkbox" value="1" <?= $data['lastfm_enabled'] === '1' ? 'checked' : '' ?>><span>Use Last.fm when RadioBOSS has no cover artwork</span></label>
                <label class="span-2">Last.fm API key <small>Optional, stored server-side</small><input name="lastfm_api_key" type="password" value="<?= e($data['lastfm_api_key']) ?>" autocomplete="off"><small>The website first tries RadioBOSS artwork. Only when no image is available will it ask Last.fm for the current artist/title and cache the result locally.</small></label>
            </div>
            <button class="button button-secondary" type="submit" name="submit_action" value="test_lastfm">Test Last.fm Cover Lookup</button>
            <p class="hint">A Last.fm API key can be created at <a href="https://www.last.fm/api/account/create" target="_blank" rel="noopener">last.fm/api/account/create</a>. The key is never sent to website visitors.</p>

            <h2>Social links</h2>
            <div class="form-grid two">
                <label>Facebook<input name="facebook" type="url" value="<?= e($data['facebook']) ?>"></label>
                <label>Instagram<input name="instagram" type="url" value="<?= e($data['instagram']) ?>"></label>
                <label>YouTube<input name="youtube" type="url" value="<?= e($data['youtube']) ?>"></label>
                <label>TikTok<input name="tiktok" type="url" value="<?= e($data['tiktok']) ?>"></label>
            </div>

            <h2>Public Programme Calendar</h2>
            <div class="form-grid two">
                <label class="span-2 checkbox-row">
                    <input name="public_calendar_enabled" type="checkbox" value="1" <?= $data['public_calendar_enabled'] === '1' ? 'checked' : '' ?>>
                    <span>Show the BroadcastScheduler public programme calendar on the website</span>
                </label>
                <label class="span-2">Calendar file / URL
                    <input name="public_calendar_url" value="<?= e($data['public_calendar_url']) ?>" placeholder="calendar/index.html">
                    <small>Recommended: upload the BroadcastScheduler export to a <code>calendar</code> folder and use <code>calendar/index.html</code>. JSON exports such as <code>calendar/schedule.json</code> are also supported.</small>
                </label>
            </div>
            <div class="alert alert-info">
                <strong>BroadcastScheduler required for the public programme calendar.</strong>
                <div>The free version is sufficient — the paid version is not required. In BroadcastScheduler open <em>Public Calendar</em>, choose the programmes to publish and use <em>Publish Website</em>. Upload the generated calendar files to your webspace.</div>
            </div>

            <h2>Website Information & Legal Pages</h2>
            <p class="hint">The website provides the pages and fields only. The station operator is responsible for entering the correct contact, privacy, cookie and legal information for their country and service.</p>
            <div class="form-grid two">
                <label class="span-2">Contact email <small>Optional</small><input name="contact_email" type="email" value="<?= e($data['contact_email']) ?>" placeholder="studio@example.com"></label>
                <label class="span-2">Contact information <small>Address, telephone, opening hours or other contact details</small><textarea name="contact_text" rows="5" placeholder="Enter your own contact information here."><?= e($data['contact_text']) ?></textarea></label>
                <label class="span-2">Privacy policy <small>Enter your own privacy policy. No legal text is supplied by Radio Website Basic.</small><textarea name="privacy_text" rows="8" placeholder="Enter your privacy policy here."><?= e($data['privacy_text']) ?></textarea></label>
                <label class="span-2">Cookie notice <small>Optional. If filled in, this text is also shown as a dismissible cookie notice on the website.</small><textarea name="cookies_text" rows="5" placeholder="Enter your cookie notice here."><?= e($data['cookies_text']) ?></textarea></label>
                <label class="span-2">Legal notice / Imprint <small>Optional depending on your jurisdiction. Enter your own legally required information.</small><textarea name="legal_text" rows="8" placeholder="Enter your legal notice or imprint here."><?= e($data['legal_text']) ?></textarea></label>
            </div>
            <div class="alert alert-info">
                <strong>Important</strong>
                <div>These fields are intentionally blank. Radio Website Basic does not generate legal wording or decide which notices your station is required to publish.</div>
            </div>

            <div class="install-actions">
                <button class="button button-primary" type="submit" name="submit_action" value="install">Install Website</button>
            </div>
        </form>
    </section>
</main>
</body>
</html>

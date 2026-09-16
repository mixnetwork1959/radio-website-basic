<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

if (!is_installed()) {
    header('Location: install.php');
    exit;
}

$config = load_config();
$station = (array) ($config['station'] ?? []);
$theme = (array) ($config['theme'] ?? []);
$homepage = (array) ($config['homepage'] ?? []);
$welcomeTitle = trim((string) ($homepage['welcome_title'] ?? ''));
$welcomeText = trim((string) ($homepage['welcome_text'] ?? ''));
$showWelcome = $welcomeTitle !== '' || $welcomeText !== '';
$social = array_filter((array) ($config['social'] ?? []), static fn($url) => is_string($url) && $url !== '');
$websiteInfo = (array) ($config['website_info'] ?? []);
$cookieNotice = trim((string) ($websiteInfo['cookies_text'] ?? ''));
$name = (string) ($station['name'] ?? 'Radio Station');
$logo = (string) ($station['logo'] ?? '');
$streamUrl = (string) ($config['stream']['url'] ?? '');
$primary = (string) ($theme['primary'] ?? '#14171f');
$accent = (string) ($theme['accent'] ?? '#ff5b36');

$calendarConfig = (array) ($config['public_calendar'] ?? []);
$calendarEnabled = (bool) ($calendarConfig['enabled'] ?? false);
$calendarUrl = trim((string) ($calendarConfig['url'] ?? ''));

if (!$calendarEnabled && $calendarUrl === '') {
    if (is_file(app_root('calendar/index.html'))) {
        $calendarEnabled = true;
        $calendarUrl = 'calendar/index.html';
    } elseif (is_file(app_root('calendar/schedule.json'))) {
        $calendarEnabled = true;
        $calendarUrl = 'calendar/schedule.json';
    }
}

if ($calendarEnabled && $calendarUrl === '') {
    $calendarUrl = 'calendar/index.html';
}

$calendarPath = strtolower((string) parse_url($calendarUrl, PHP_URL_PATH));
$calendarType = str_ends_with($calendarPath, '.json') ? 'json' : 'html';
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e($primary) ?>">
<title><?= e($name) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(RADIO_WEBSITE_VERSION) ?>">
<style>:root{--station-primary:<?= e($primary) ?>;--station-accent:<?= e($accent) ?>}</style>
</head>
<body>
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="index.php" aria-label="<?= e($name) ?> home">
            <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($name) ?> logo"><?php else: ?><span class="brand-fallback"><?= e(station_initials($name)) ?></span><?php endif; ?>
            <span><strong><?= e($name) ?></strong><small><?= e((string) ($station['tagline'] ?? '')) ?></small></span>
        </a>
        <nav class="main-nav">
            <a href="#listen">Listen Live</a>
            <a href="#recent">Recently Played</a>
            <?php if ($calendarEnabled): ?><a href="schedule.php">Schedule</a><?php endif; ?>
            <a href="info.php">Info</a>
        </nav>
    </div>
</header>

<main>
<section class="hero" id="listen">
    <div class="container hero-grid">
        <div class="cover-wrap"><img id="current-cover" class="current-cover" src="artwork.php?which=current" alt="Current track artwork"></div>
        <div class="onair-card">
            <div class="eyebrow onair"><span class="live-dot"></span> ON AIR</div>
            <h1 id="now-title">Loading current track…</h1>
            <p id="now-artist" class="now-artist">Please wait</p>
            <div class="meta-row"><span id="listener-count">— listeners</span><span id="connection-state">Connecting…</span></div>
            <div class="player-controls">
                <button id="play-toggle" class="play-button" type="button" aria-label="Play stream">▶</button>
                <div class="volume-group"><span>Volume</span><input id="volume" type="range" min="0" max="1" step="0.01" value="0.8"></div>
            </div>
            <audio id="radio-stream" preload="none" src="<?= e($streamUrl) ?>"></audio>
            <div class="next-track"><span>Next</span><strong id="next-track">Loading…</strong></div>
        </div>
    </div>
</section>

<?php if ($showWelcome): ?>
<section class="welcome-section" aria-label="Station welcome">
    <div class="container welcome-inner">
        <?php if ($welcomeTitle !== ''): ?><h2><?= e($welcomeTitle) ?></h2><?php endif; ?>
        <?php if ($welcomeText !== ''): ?><div class="welcome-text"><?= nl2br(e($welcomeText)) ?></div><?php endif; ?>
    </div>
</section>
<?php endif; ?>

<section class="content-section" id="recent">
    <div class="container">
        <div class="section-heading"><div><div class="eyebrow">Music history</div><h2>Recently Played</h2></div></div>
        <div id="recent-list" class="recent-grid"><div class="empty-card">Loading recently played tracks…</div></div>
    </div>
</section>

<?php if ($social): ?>
<section class="social-strip"><div class="container social-inner"><strong>Follow <?= e($name) ?></strong><div class="social-links">
<?php foreach ($social as $network => $url): ?><a href="<?= e((string) $url) ?>" target="_blank" rel="noopener noreferrer"><?= e(ucfirst((string) $network)) ?></a><?php endforeach; ?>
</div></div></section>
<?php endif; ?>
</main>

<footer class="site-footer"><div class="container footer-inner"><div>© <?= date('Y') ?> <?= e($name) ?> <span>·</span> Radio Website Basic v<?= e(RADIO_WEBSITE_VERSION) ?></div><nav class="footer-links" aria-label="Website information"><a href="info.php#contact">Contact</a><a href="info.php#privacy">Privacy</a><a href="info.php#cookies">Cookies</a><a href="info.php#legal">Legal</a></nav></div></footer>
<?php if ($cookieNotice !== ''): ?>
<div id="cookie-notice" class="cookie-notice" data-notice-key="<?= e(substr(hash('sha256', $cookieNotice), 0, 16)) ?>" hidden>
    <div class="cookie-notice-inner"><div><strong>Cookie notice</strong><p><?= nl2br(e($cookieNotice)) ?></p><a href="info.php#cookies">More information</a></div><button id="cookie-notice-dismiss" class="cookie-notice-button" type="button">OK</button></div>
</div>
<?php endif; ?>
<script>
window.RadioWebsite={
    apiUrl:'api.php',
    artworkUrl:'artwork.php',
    stationName:<?= json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,
    publicCalendar:<?= json_encode([
        'enabled' => $calendarEnabled,
        'type' => $calendarType,
        'url' => $calendarUrl,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>
};
</script>
<script src="assets/js/app.js?v=<?= e(RADIO_WEBSITE_VERSION) ?>"></script>
</body>
</html>

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
$social = array_filter((array) ($config['social'] ?? []), static fn($url) => is_string($url) && $url !== '');
$websiteInfo = (array) ($config['website_info'] ?? []);
$cookieNotice = trim((string) ($websiteInfo['cookies_text'] ?? ''));
$name = (string) ($station['name'] ?? 'Radio Station');
$logo = (string) ($station['logo'] ?? '');
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
<title>Schedule · <?= e($name) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(RADIO_WEBSITE_VERSION) ?>">
<style>:root{--station-primary:<?= e($primary) ?>;--station-accent:<?= e($accent) ?>}</style>
</head>
<body class="schedule-page">
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="index.php" aria-label="<?= e($name) ?> home">
            <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($name) ?> logo"><?php else: ?><span class="brand-fallback"><?= e(station_initials($name)) ?></span><?php endif; ?>
            <span><strong><?= e($name) ?></strong><small><?= e((string) ($station['tagline'] ?? '')) ?></small></span>
        </a>
        <nav class="main-nav">
            <a href="index.php#listen">Listen Live</a>
            <a href="index.php#recent">Recently Played</a>
            <?php if ($calendarEnabled): ?><a href="schedule.php" aria-current="page">Schedule</a><?php endif; ?>
            <a href="info.php">Info</a>
        </nav>
    </div>
</header>

<main>
<section class="content-section schedule-page-section">
    <div class="calendar-page-container">
        <div class="section-heading schedule-page-heading">
            <div><div class="eyebrow">On the radio</div><h1>Programme Schedule</h1></div>
            <a class="schedule-back-link" href="index.php">← Back to Listen Live</a>
        </div>

        <?php if (!$calendarEnabled): ?>
            <div class="empty-card">Programme schedule is currently unavailable.</div>
        <?php elseif ($calendarType === 'json'): ?>
            <div id="schedule-list" class="schedule-grid schedule-grid-wide"><div class="empty-card">Loading programme schedule…</div></div>
        <?php else: ?>
            <div class="calendar-frame-wrap calendar-frame-wrap-wide">
                <iframe
                    id="public-calendar-frame"
                    class="calendar-frame calendar-frame-auto"
                    src="<?= e($calendarUrl) ?>"
                    title="<?= e($name) ?> programme schedule"
                    loading="eager"
                    referrerpolicy="same-origin"
                    scrolling="no"
                ></iframe>
            </div>
            <p class="calendar-note">Programme data is published by BroadcastScheduler. The free version is sufficient for this public calendar.</p>
        <?php endif; ?>
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

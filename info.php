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
$name = (string) ($station['name'] ?? 'Radio Station');
$logo = (string) ($station['logo'] ?? '');
$primary = (string) ($theme['primary'] ?? '#14171f');
$accent = (string) ($theme['accent'] ?? '#ff5b36');
$contactEmail = trim((string) ($websiteInfo['contact_email'] ?? ''));
$contactText = trim((string) ($websiteInfo['contact_text'] ?? ''));
$privacyText = trim((string) ($websiteInfo['privacy_text'] ?? ''));
$cookieNotice = trim((string) ($websiteInfo['cookies_text'] ?? ''));
$legalText = trim((string) ($websiteInfo['legal_text'] ?? ''));

$calendarConfig = (array) ($config['public_calendar'] ?? []);
$calendarEnabled = (bool) ($calendarConfig['enabled'] ?? false);
$calendarUrl = trim((string) ($calendarConfig['url'] ?? ''));
if (!$calendarEnabled && $calendarUrl === '') {
    $calendarEnabled = is_file(app_root('calendar/index.html')) || is_file(app_root('calendar/schedule.json'));
}

function info_text(string $text, string $fallback): string
{
    if ($text === '') {
        return '<p class="muted">' . e($fallback) . '</p>';
    }
    return '<div class="prose-text">' . nl2br(e($text)) . '</div>';
}
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="theme-color" content="<?= e($primary) ?>">
<title>Information · <?= e($name) ?></title>
<link rel="stylesheet" href="assets/css/app.css?v=<?= e(RADIO_WEBSITE_VERSION) ?>">
<style>:root{--station-primary:<?= e($primary) ?>;--station-accent:<?= e($accent) ?>}</style>
</head>
<body class="info-page">
<header class="site-header">
    <div class="container header-inner">
        <a class="brand" href="index.php" aria-label="<?= e($name) ?> home">
            <?php if ($logo !== ''): ?><img src="<?= e($logo) ?>" alt="<?= e($name) ?> logo"><?php else: ?><span class="brand-fallback"><?= e(station_initials($name)) ?></span><?php endif; ?>
            <span><strong><?= e($name) ?></strong><small><?= e((string) ($station['tagline'] ?? '')) ?></small></span>
        </a>
        <nav class="main-nav">
            <a href="index.php#listen">Listen Live</a>
            <a href="index.php#recent">Recently Played</a>
            <?php if ($calendarEnabled): ?><a href="schedule.php">Schedule</a><?php endif; ?>
            <a href="info.php" aria-current="page">Info</a>
        </nav>
    </div>
</header>

<main>
<section class="content-section info-page-section">
    <div class="container info-container">
        <div class="section-heading info-heading"><div><div class="eyebrow">Station information</div><h1>Contact & Website Information</h1></div></div>
        <nav class="info-jump-nav" aria-label="Information sections">
            <a href="#contact">Contact</a><a href="#privacy">Privacy</a><a href="#cookies">Cookies</a><a href="#legal">Legal Notice</a>
        </nav>

        <section id="contact" class="info-card">
            <div class="eyebrow">Contact</div><h2>Contact</h2>
            <?php if ($contactEmail !== ''): ?><p><a class="info-email" href="mailto:<?= e($contactEmail) ?>"><?= e($contactEmail) ?></a></p><?php endif; ?>
            <?= info_text($contactText, 'Contact information has not been added by the station operator yet.') ?>
        </section>

        <section id="privacy" class="info-card">
            <div class="eyebrow">Privacy</div><h2>Privacy Policy</h2>
            <?= info_text($privacyText, 'A privacy policy has not been added by the station operator yet.') ?>
        </section>

        <section id="cookies" class="info-card">
            <div class="eyebrow">Cookies</div><h2>Cookie Notice</h2>
            <?= info_text($cookieNotice, 'A cookie notice has not been added by the station operator yet.') ?>
        </section>

        <section id="legal" class="info-card">
            <div class="eyebrow">Legal</div><h2>Legal Notice / Imprint</h2>
            <?= info_text($legalText, 'A legal notice or imprint has not been added by the station operator yet.') ?>
        </section>
    </div>
</section>

<?php if ($social): ?>
<section class="social-strip"><div class="container social-inner"><strong>Follow <?= e($name) ?></strong><div class="social-links">
<?php foreach ($social as $network => $url): ?><a href="<?= e((string) $url) ?>" target="_blank" rel="noopener noreferrer"><?= e(ucfirst((string) $network)) ?></a><?php endforeach; ?>
</div></div></section>
<?php endif; ?>
</main>

<footer class="site-footer"><div class="container footer-inner"><div>© <?= date('Y') ?> <?= e($name) ?> <span>·</span> Radio Website Basic v<?= e(RADIO_WEBSITE_VERSION) ?></div><nav class="footer-links" aria-label="Website information"><a href="#contact">Contact</a><a href="#privacy">Privacy</a><a href="#cookies">Cookies</a><a href="#legal">Legal</a></nav></div></footer>
<?php if ($cookieNotice !== ''): ?>
<div id="cookie-notice" class="cookie-notice" data-notice-key="<?= e(substr(hash('sha256', $cookieNotice), 0, 16)) ?>" hidden>
    <div class="cookie-notice-inner"><div><strong>Cookie notice</strong><p><?= nl2br(e($cookieNotice)) ?></p><a href="#cookies">More information</a></div><button id="cookie-notice-dismiss" class="cookie-notice-button" type="button">OK</button></div>
</div>
<?php endif; ?>
<script>
window.RadioWebsite={apiUrl:'api.php',artworkUrl:'artwork.php',stationName:<?= json_encode($name, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>,publicCalendar:{enabled:false,type:'html',url:''}};
</script>
<script src="assets/js/app.js?v=<?= e(RADIO_WEBSITE_VERSION) ?>"></script>
</body>
</html>

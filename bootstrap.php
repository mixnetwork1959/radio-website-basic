<?php
declare(strict_types=1);

const RADIO_WEBSITE_VERSION = '0.1.9';

require_once __DIR__ . '/app/Support/helpers.php';
require_once __DIR__ . '/app/Contracts/RadioSourceInterface.php';
require_once __DIR__ . '/app/Radio/RadioBossConnector.php';
require_once __DIR__ . '/app/Artwork/LastFmArtworkProvider.php';

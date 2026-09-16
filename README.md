# Radio Website Basic v0.1.9

Standalone PHP radio website with a browser setup wizard and RadioBOSS Remote Control API integration.

## Included

- No WordPress and no SQL database required
- Setup wizard
- RadioBOSS API connection test
- Stream player with volume control
- Now Playing
- Next Track
- Current artwork proxy
- Optional Last.fm artwork fallback with local cache
- Recently Played with cover thumbnails
- Listener count
- Optional public programme calendar from RadioBOSS BroadcastScheduler
- Social links
- Logo + station colors
- Responsive/mobile layout
- Credentials and API keys remain server-side; they are never sent to browser JavaScript
- Connector architecture prepared for future data sources such as AzuraCast

SongRequest and Top 20 are deliberately not enabled in this core build. They are reserved as later modules.

## Requirements

- PHP 8.1 or newer
- PHP cURL extension
- PHP SimpleXML extension
- PHP Fileinfo extension
- `storage/` writable by PHP
- `assets/uploads/` writable by PHP
- The web server must be able to reach the configured RadioBOSS Remote Control API address
- Optional Last.fm API key for external artwork fallback
- Optional RadioBOSS BroadcastScheduler for the public programme calendar

## Install

1. Upload the complete folder contents to the webspace.
2. Open `install.php` in a browser.
3. Enter the station and stream information.
4. Enter the RadioBOSS Remote Control API URL, optional API user, and password.
5. Click **Test RadioBOSS Connection**.
6. Optionally enter a Last.fm API key and click **Test Last.fm Cover Lookup**.
7. Add an optional logo, colors and social links.
8. Optionally enable the **Public Programme Calendar**.
9. Click **Install Website**.

The installer writes:

- `storage/config.php`
- `storage/installed.lock`
- `storage/cache/artwork/` as needed for cached Last.fm artwork

After installation, `install.php` redirects to the website and does not reopen while the lock/configuration are present.

## Public Programme Calendar

The public programme calendar is supplied by **RadioBOSS BroadcastScheduler**.

**The free BroadcastScheduler version is sufficient. The paid version is not required for this website feature.**

In BroadcastScheduler:

1. Open **Public Calendar**.
2. Select the programmes that should be public.
3. Click **Publish Website**.
4. Upload the generated calendar files to the webspace.

The simplest layout is:

```text
radio-website/
  calendar/
    index.html
```

Then enter this in the website installer:

```text
calendar/index.html
```

The website embeds BroadcastScheduler's standalone calendar directly.

### JSON calendar support

Radio Website Basic can also display a JSON calendar, for example:

```text
calendar/schedule.json
```

Supported JSON roots are:

```json
[
  {
    "day": "Monday",
    "start_time": "06:00",
    "end_time": "10:00",
    "title": "Morning Show",
    "description": "Optional public text",
    "color": "#3b82f6"
  }
]
```

or:

```json
{
  "programs": []
}
```

Fields named `start` and `end` may also contain ISO date/time values.

For upgraded v0.1.3 installations, placing BroadcastScheduler's exported `index.html` at `calendar/index.html` enables the calendar automatically even when the older `storage/config.php` does not yet contain the new calendar settings.

## Artwork order

The public artwork endpoint uses this order:

1. RadioBOSS `trackartwork` / `nexttrackartwork` / `prevtrackartwork`
2. Last.fm `track.getInfo` using artist + title
3. Last.fm `album.getInfo` when RadioBOSS supplies an album name and track lookup has no image
4. Local placeholder

Last.fm images are downloaded server-side and cached locally. Successful artwork is cached for 30 days; a no-artwork result is cached for 12 hours to avoid repeatedly querying Last.fm. The API key remains only in `storage/config.php`.

## RadioBOSS

The connector uses the standard Remote Control API actions:

- `playbackinfo` for current/next/previous track and listeners
- `getlastplayed` for Recently Played
- `trackartwork` / `nexttrackartwork` / `prevtrackartwork` for cover images

In RadioBOSS, enable **Remote Control API** and ensure the API port is reachable from the web server. Do not expose the API without a strong password or restricted API user.

## Important HTTPS note

If the website is served over HTTPS but the public audio stream uses plain HTTP, modern browsers may block the stream as mixed content. Prefer an HTTPS stream URL.

For the programme calendar, use a same-site HTTPS calendar URL when possible. A local path such as `calendar/index.html` is the simplest option.

## Architecture

```text
Browser
  ├─ Stream URL (public audio)
  └─ PHP website
       ├─ api.php
       ├─ artwork.php
       │    ├─ RadioBOSS artwork
       │    └─ Last.fm fallback + local cache
       ├─ Public Programme Calendar
       │    └─ BroadcastScheduler Free or paid
       └─ RadioBossConnector
              └─ RadioBOSS Remote Control API
```

## Changelog

### v0.1.9
- Added optional **Homepage Welcome** fields to the Setup Wizard.
- Station operators can enter their own welcome title and introduction text.
- The welcome area appears between the live player and Recently Played.
- When both fields are empty, the section is omitted completely with no empty placeholder space.

### v0.1.8
- Restored Recently Played to the compact list layout with cover thumbnails.
- Restored the original Next Track presentation.
- Next Track now always keeps a visible status text if RadioBOSS temporarily has no next item.
- Kept the v0.1.7 menu order and Info / Contact / Privacy / Cookies / Legal features unchanged.

### v0.1.7
- Top menu order changed to Listen Live, Recently Played, Schedule, Info.
- Added a dedicated Info page with Contact, Privacy, Cookies and Legal Notice / Imprint sections.
- Added setup fields for station-supplied contact and legal information.
- No legal wording is generated or prefilled; the station operator supplies its own content.
- Added an optional dismissible cookie notice when cookie text is configured.
- Added Contact, Privacy, Cookies and Legal links to the footer.
- Fixed the internal version constant so the displayed version matches the package version.

### v0.1.6
- Visual polish for header, player, listener/status badges and responsive layout.
- Recently Played redesigned as cover cards.
- Improved mobile navigation so Schedule remains accessible.

### v0.1.5
- Public programme calendar moved from the home page to a dedicated `schedule.php` page.
- HTML BroadcastScheduler calendar uses the full browser width.
- Same-origin calendar iframe height is adjusted automatically to reduce internal scrollbars.
- JSON calendar exports continue to use the native responsive schedule cards.

### v0.1.4
- Replaced the old manual schedule entry with a BroadcastScheduler public programme calendar integration.
- BroadcastScheduler Free is sufficient; the paid version is not required.
- Supports the existing standalone BroadcastScheduler `index.html` export.
- Added optional JSON calendar support for future/direct data integration.
- Existing v0.1.3 installations auto-detect `calendar/index.html` or `calendar/schedule.json`.

### v0.1.3
- Recently Played entries now include cover thumbnails using the same cached Last.fm artwork fallback.
- Removed the accidental Monday/Friday demonstration schedule from installer defaults.

### v0.1.2
- Added optional Last.fm API artwork fallback and local caching.
- RadioBOSS artwork remains the first choice.

### v0.1.1
- Smoother background updates without visible section rebuilding on every poll.

### v0.1.0
- Initial standalone PHP core with RadioBOSS integration.

## Website Information & Legal Pages

The setup wizard provides optional fields for contact information, privacy policy, cookie notice and legal notice/imprint. The software intentionally does not provide legal wording. The station operator must enter and maintain its own legally appropriate content.

The top navigation uses a single **Info** item. The information page contains Contact, Privacy, Cookies and Legal Notice sections. When a cookie notice is configured, a dismissible notice is shown on the site and links to the Cookies section.

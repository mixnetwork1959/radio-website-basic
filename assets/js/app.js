(() => {
  'use strict';

  const cfg = window.RadioWebsite || {};
  const $ = (id) => document.getElementById(id);
  const stream = $('radio-stream');
  const play = $('play-toggle');
  const volume = $('volume');
  const state = $('connection-state');
  const cover = $('current-cover');
  const publicCalendar = cfg.publicCalendar || {};

  let lastTrackKey = '';
  let lastRecentSignature = '';
  let lastScheduleSignature = '';

  function setTextIfChanged(element, value) {
    if (element && element.textContent !== value) element.textContent = value;
  }

  function text(value, fallback = '—') {
    const v = String(value ?? '').trim();
    return v || fallback;
  }

  function trackLabel(track) {
    if (!track) return '—';
    const artist = String(track.artist || '').trim();
    const title = String(track.title || '').trim();
    if (artist && title) return `${artist} – ${title}`;
    return title || artist || '—';
  }

  function escapeHtml(value) {
    return String(value ?? '').replace(/[&<>'"]/g, (char) => ({
      '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#039;', '"': '&quot;'
    })[char]);
  }

  async function loadStatus() {
    const nowTitle = $('now-title');
    const recentRoot = $('recent-list');
    if (!nowTitle && !recentRoot && !state && !cover) return;

    try {
      const response = await fetch(`${cfg.apiUrl}?action=status&_=${Date.now()}`, { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Radio data unavailable');

      setTextIfChanged($('now-title'), text(data.current?.title, 'Live radio'));
      setTextIfChanged($('now-artist'), text(data.current?.artist, cfg.stationName || 'On air'));
      const nextElement = $('next-track');
      if (nextElement) {
        const nextLabel = trackLabel(data.next);
        setTextIfChanged(nextElement, nextLabel === '—' ? 'Waiting for next track…' : nextLabel);
      }
      const listeners = Number(data.listeners || 0);
      setTextIfChanged($('listener-count'), `${listeners} listener${listeners === 1 ? '' : 's'}`);
      setTextIfChanged(state, 'Live data connected');
      if (state) state.classList.remove('error');

      const key = `${data.current?.artist || ''}|${data.current?.title || ''}`;
      if (cover && key !== lastTrackKey) {
        lastTrackKey = key;
        cover.src = `${cfg.artworkUrl}?which=current&v=${encodeURIComponent(key)}&t=${Date.now()}`;
      }

      const recentTracks = Array.isArray(data.recent) ? data.recent : [];
      const recentSignature = JSON.stringify(recentTracks);
      if (recentSignature !== lastRecentSignature) {
        lastRecentSignature = recentSignature;
        renderRecent(recentTracks);
      }
    } catch (error) {
      setTextIfChanged(state, 'Radio data unavailable');
      if (state) state.classList.add('error');
    }
  }

  function renderRecent(tracks) {
    const root = $('recent-list');
    if (!root) return;
    if (!tracks.length) {
      root.innerHTML = '<div class="empty-card">No recently played tracks are available yet.</div>';
      return;
    }
    root.innerHTML = tracks.map((track) => {
      const params = new URLSearchParams({
        artist: String(track.artist || ''),
        title: String(track.title || ''),
        album: String(track.album || '')
      });
      const artworkSrc = `${cfg.artworkUrl}?${params.toString()}`;
      return `
        <article class="track-card">
          <img class="track-cover" src="${escapeHtml(artworkSrc)}" alt="" loading="lazy">
          <div class="track-info"><strong>${escapeHtml(text(track.title, 'Unknown title'))}</strong><span>${escapeHtml(text(track.artist, 'Unknown artist'))}</span></div>
          <small>${escapeHtml(track.start_time || track.duration || '')}</small>
        </article>
      `;
    }).join('');
  }

  async function loadSchedule() {
    const root = $('schedule-list');
    if (!root || !publicCalendar.enabled || publicCalendar.type !== 'json') return;

    try {
      const response = await fetch(`${cfg.apiUrl}?action=calendar&_=${Date.now()}`, { cache: 'no-store' });
      const data = await response.json();
      if (!response.ok || !data.success) throw new Error(data.message || 'Schedule unavailable');

      const scheduleItems = Array.isArray(data.schedule) ? data.schedule : [];
      const scheduleSignature = JSON.stringify(scheduleItems);
      if (scheduleSignature !== lastScheduleSignature) {
        lastScheduleSignature = scheduleSignature;
        renderSchedule(scheduleItems);
      }
    } catch (_) {
      root.innerHTML = '<div class="empty-card">Programme schedule is currently unavailable.</div>';
    }
  }

  function renderSchedule(items) {
    const root = $('schedule-list');
    if (!root) return;

    if (!items.length) {
      root.innerHTML = '<div class="empty-card">No public programmes are available for this week.</div>';
      return;
    }

    const dayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
    const groups = {};

    items.forEach((item) => {
      const day = text(item.day, 'Other');
      groups[day] = groups[day] || [];
      groups[day].push(item);
    });

    const entries = Object.entries(groups).sort(([a], [b]) => {
      const ai = dayOrder.indexOf(a);
      const bi = dayOrder.indexOf(b);
      if (ai === -1 && bi === -1) return a.localeCompare(b);
      if (ai === -1) return 1;
      if (bi === -1) return -1;
      return ai - bi;
    });

    root.innerHTML = entries.map(([day, shows]) => `
      <article class="schedule-day">
        <h3>${escapeHtml(day)}</h3>
        ${shows.map((show) => {
          const color = /^#[0-9a-f]{6}$/i.test(String(show.color || '')) ? String(show.color) : '';
          const style = color ? ` style="--programme-color:${color}"` : '';
          const description = String(show.description || '').trim();
          return `
            <div class="schedule-show"${style}>
              <span>${escapeHtml(show.start || '')}${show.end ? `–${escapeHtml(show.end)}` : ''}</span>
              <div>
                <strong>${escapeHtml(show.title || 'Programme')}</strong>
                ${description ? `<small>${escapeHtml(description)}</small>` : ''}
              </div>
            </div>
          `;
        }).join('')}
      </article>
    `).join('');
  }

  function fitPublicCalendarFrame() {
    const frame = $('public-calendar-frame');
    if (!frame) return;

    const applyFit = () => {
      try {
        const doc = frame.contentDocument;
        if (!doc || !doc.documentElement) return;

        if (!doc.getElementById('radio-website-calendar-fit')) {
          const style = doc.createElement('style');
          style.id = 'radio-website-calendar-fit';
          style.textContent = `
            html, body { overflow-x: hidden !important; }
            .app { width: 100% !important; max-width: none !important; padding: 14px !important; }
            .week-grid {
              overflow: visible !important;
              grid-template-columns: 68px repeat(7, minmax(0, 1fr)) !important;
            }
            .day-heading, .day-column, .program { min-width: 0 !important; }
            @media (max-width: 980px) {
              :root { --hour-height: 66px !important; --time-width: 56px !important; }
              .app { padding: 8px !important; }
              .week-grid { grid-template-columns: 56px repeat(7, minmax(0, 1fr)) !important; }
              .program { left: 2px !important; right: 2px !important; padding: 5px !important; }
              .program-title { font-size: .72rem !important; }
              .program-time { font-size: .61rem !important; }
              .program-description { display: none !important; }
              .day-heading { font-size: .72rem !important; }
            }
          `;
          doc.head.appendChild(style);
        }

        const resize = () => {
          const bodyHeight = doc.body ? doc.body.scrollHeight : 0;
          const htmlHeight = doc.documentElement.scrollHeight;
          const height = Math.max(bodyHeight, htmlHeight, 720);
          frame.style.height = `${Math.ceil(height + 4)}px`;
        };

        resize();
        window.setTimeout(resize, 100);
        window.setTimeout(resize, 500);
        window.setTimeout(resize, 1200);

        if ('ResizeObserver' in window && doc.body && !frame._calendarResizeObserver) {
          const observer = new ResizeObserver(resize);
          observer.observe(doc.body);
          frame._calendarResizeObserver = observer;
        }
      } catch (_) {
        // Cross-origin calendar URLs cannot be measured. The full-width page
        // still provides a much larger viewport than the old home-page embed.
      }
    };

    frame.addEventListener('load', applyFit);
    if (frame.contentDocument?.readyState === 'complete') applyFit();
  }

  if (stream && play) {
    stream.volume = Number(volume?.value || 0.8);
    play.addEventListener('click', async () => {
      try {
        if (stream.paused) {
          await stream.play();
          play.textContent = '❚❚';
          play.setAttribute('aria-label', 'Pause stream');
        } else {
          stream.pause();
          play.textContent = '▶';
          play.setAttribute('aria-label', 'Play stream');
        }
      } catch (_) {
        setTextIfChanged(state, 'Stream could not start');
        state.classList.add('error');
      }
    });
    stream.addEventListener('playing', () => {
      setTextIfChanged(state, 'Stream playing');
      state.classList.remove('error');
    });
    stream.addEventListener('error', () => {
      setTextIfChanged(state, 'Stream unavailable');
      state.classList.add('error');
    });
    if (volume) volume.addEventListener('input', () => { stream.volume = Number(volume.value); });
  }

  const cookieNotice = $('cookie-notice');
  const cookieDismiss = $('cookie-notice-dismiss');
  if (cookieNotice) {
    const noticeKey = cookieNotice.dataset.noticeKey || 'default';
    const storageKey = `radioWebsiteCookieNoticeDismissed:${noticeKey}`;
    let dismissed = false;
    try { dismissed = window.localStorage.getItem(storageKey) === '1'; } catch (_) {}
    if (!dismissed) cookieNotice.hidden = false;
    if (cookieDismiss) {
      cookieDismiss.addEventListener('click', () => {
        cookieNotice.hidden = true;
        try { window.localStorage.setItem(storageKey, '1'); } catch (_) {}
      });
    }
  }

  loadStatus();
  loadSchedule();
  fitPublicCalendarFrame();
  setInterval(loadStatus, 10000);
  if (publicCalendar.enabled && publicCalendar.type === 'json') {
    setInterval(loadSchedule, 300000);
  }
})();

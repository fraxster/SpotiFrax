const el = (id) => document.getElementById(id);

const gate = el('gate');
const display = el('display');

let localProgressMs = 0;
let localDurationMs = 0;
let lastSyncAt = 0;
let isPlaying = false;
let qrRenderedFor = null;
let isAdmin = false;
let lastSettingsKey = null;

function fmtTime(ms) {
  if (!ms || ms < 0) ms = 0;
  const total = Math.floor(ms / 1000);
  const m = Math.floor(total / 60);
  const s = total % 60;
  return `${m}:${String(s).padStart(2, '0')}`;
}

function showGate(title, detail) {
  if (title) el('gate-title').textContent = title;
  if (detail) el('gate-detail').textContent = detail;
  gate.hidden = false;
  display.hidden = true;
}

function showDisplay() {
  gate.hidden = true;
  display.hidden = false;
}

el('login-btn').addEventListener('click', () => {
  window.location.href = 'login.php';
});

// ---------- Settings (logo, title, widget sizes, admin mode) ----------

async function loadSettings() {
  try {
    const res = await fetch('api/settings.php');
    const data = await res.json();
    isAdmin = Boolean(data.isAdmin);
    applySettings(data.settings || {});
  } catch (_) { /* ignore */ }
}

function applySettings(s) {
  // Avoid redundant DOM churn: only re-apply when something changed.
  const key = JSON.stringify(s) + '|' + isAdmin;
  if (key === lastSettingsKey) return;
  lastSettingsKey = key;

  const root = document.documentElement.style;
  root.setProperty('--album-scale', s.albumScale ?? 1);
  root.setProperty('--qr-scale', s.qrScale ?? 1);
  root.setProperty('--queue-scale', s.queueScale ?? 1);

  const logo = el('brand-logo');
  const title = el('brand-title');
  if (s.logoUrl) {
    logo.src = s.logoUrl;
    logo.hidden = false;
  } else {
    logo.hidden = true;
    logo.removeAttribute('src');
  }
  title.textContent = s.title || '';
}

async function loadQr() {
  try {
    const res = await fetch('api/qrcode.php');
    const data = await res.json();
    if (data.url && qrRenderedFor !== data.url) {
      // Render the QR client-side (no server-side QR dependency needed).
      const holder = el('qr');
      holder.innerHTML = '';
      // eslint-disable-next-line no-undef
      new QRCode(holder, { text: data.url, width: 220, height: 220, correctLevel: QRCode.CorrectLevel.M });
      qrRenderedFor = data.url;
      el('qr-url').textContent = data.url;
    }
  } catch (_) { /* ignore */ }
}

async function refreshNowPlaying() {
  try {
    const res = await fetch('api/now-playing.php');
    if (res.status === 401) {
      showGate('Connect Spotify to start the jukebox');
      return;
    }
    showDisplay();
    const data = await res.json();

    if (!data.track) {
      el('title').textContent = 'Nothing playing';
      el('artist').textContent = 'Start playback on any Spotify device';
      el('art').removeAttribute('src');
      isPlaying = false;
      localDurationMs = 0;
      localProgressMs = 0;
      updateProgressUi();
      return;
    }

    const t = data.track;
    el('title').textContent = t.name;
    el('artist').textContent = t.artists.join(', ');
    if (t.image) el('art').src = t.image;

    localProgressMs = data.progressMs || 0;
    localDurationMs = t.durationMs || 0;
    lastSyncAt = Date.now();
    isPlaying = data.isPlaying;
    updateProgressUi();
  } catch (err) {
    console.error(err);
  }
}

async function refreshQueue() {
  try {
    const res = await fetch('api/queue.php');
    if (!res.ok) return;
    const data = await res.json();
    const list = el('queue');
    const items = data.queue || [];

    if (items.length === 0) {
      list.innerHTML = '<li class="empty">Queue is empty — scan the code to add songs.</li>';
      return;
    }

    list.innerHTML = items.slice(0, 8).map((t, i) => `
      <li class="queue-item">
        <div class="q-rank">${i + 1}</div>
        <img src="${t.image || ''}" alt="" />
        <div class="meta">
          <div class="t">${escapeHtml(t.name)}</div>
          <div class="a">${escapeHtml(t.artists.join(', '))}</div>
        </div>
        <div class="q-votes" title="votes">▲ ${t.votes ?? 0}</div>
        ${isAdmin ? `<button class="q-remove" data-id="${escapeHtml(t.id)}" title="Remove from queue">✕</button>` : ''}
      </li>
    `).join('');
  } catch (err) {
    console.error(err);
  }
}

// Moderator: remove a song (only wired up; buttons only exist when admin).
el('queue').addEventListener('click', async (e) => {
  const btn = e.target.closest('.q-remove');
  if (!btn) return;
  const trackId = btn.dataset.id;
  btn.disabled = true;
  try {
    const res = await fetch('admin/remove-track.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ trackId }),
    });
    if (res.status === 403) {
      isAdmin = false;
      lastSettingsKey = null; // force re-render to drop remove buttons
      refreshQueue();
      return;
    }
    // Refresh immediately so the removed song disappears.
    refreshQueue();
  } catch (_) {
    btn.disabled = false;
  }
});

function updateProgressUi() {
  const pct = localDurationMs ? Math.min(100, (localProgressMs / localDurationMs) * 100) : 0;
  el('bar').style.width = `${pct}%`;
  el('elapsed').textContent = fmtTime(localProgressMs);
  el('duration').textContent = fmtTime(localDurationMs);
}

// Smoothly advance the progress bar between server syncs.
setInterval(() => {
  if (isPlaying && localDurationMs) {
    const elapsed = Date.now() - lastSyncAt;
    const projected = Math.min(localDurationMs, localProgressMs + elapsed);
    el('bar').style.width = `${(projected / localDurationMs) * 100}%`;
    el('elapsed').textContent = fmtTime(projected);
  }
}, 1000);

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

async function init() {
  const res = await fetch('api/status.php').then((r) => r.json()).catch(() => ({ authenticated: false }));
  if (!res.authenticated) {
    showGate('Connect Spotify to start the jukebox');
    return;
  }
  showDisplay();
  await Promise.all([loadSettings(), loadQr(), refreshNowPlaying(), refreshQueue()]);
}

// Apply branding/sizes right away, independent of Spotify auth state.
loadSettings();
init();
// Re-sync with the server periodically. The queue poll also drives the
// server-side feeder (there's no background process on shared hosting).
setInterval(refreshNowPlaying, 4000);
setInterval(refreshQueue, 5000);
setInterval(loadQr, 60000);
// Pick up admin changes (logo, sizes) and admin-mode transitions live.
setInterval(loadSettings, 5000);

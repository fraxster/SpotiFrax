import express from 'express';
import crypto from 'node:crypto';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import QRCode from 'qrcode';

import { config } from './config.js';
import {
  buildAuthorizeUrl,
  exchangeCodeForTokens,
  isAuthenticated,
  searchTracks,
  getTrack,
  getNowPlaying,
} from './spotify.js';
import {
  addTrack,
  toggleVote,
  getQueueFor,
  startFeeder,
} from './jukebox.js';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
const publicDir = path.join(__dirname, '..', 'public');

const app = express();
app.use(express.json());
app.use(express.static(publicDir));

// Assign each visitor a stable, anonymous guest id via cookie so we can track
// one-vote-per-guest without any login. Not security-grade — it's a party.
app.use((req, res, next) => {
  const existing = parseCookie(req.headers.cookie || '')['jukebox_gid'];
  if (existing) {
    req.guestId = existing;
  } else {
    const gid = crypto.randomBytes(12).toString('hex');
    req.guestId = gid;
    res.setHeader(
      'Set-Cookie',
      `jukebox_gid=${gid}; Path=/; Max-Age=${60 * 60 * 24 * 30}; SameSite=Lax`
    );
  }
  next();
});

function parseCookie(header) {
  return header.split(';').reduce((acc, part) => {
    const idx = part.indexOf('=');
    if (idx === -1) return acc;
    const key = part.slice(0, idx).trim();
    const val = part.slice(idx + 1).trim();
    if (key) acc[key] = decodeURIComponent(val);
    return acc;
  }, {});
}

// Simple in-memory OAuth state store to protect against CSRF on the callback.
const pendingStates = new Set();

// ---------- OAuth ----------

app.get('/login', (req, res) => {
  const state = crypto.randomBytes(16).toString('hex');
  pendingStates.add(state);
  // States are short-lived; clean up after 10 minutes.
  setTimeout(() => pendingStates.delete(state), 10 * 60 * 1000).unref();
  res.redirect(buildAuthorizeUrl(state));
});

app.get('/callback', async (req, res) => {
  const { code, state, error } = req.query;

  if (error) {
    return res.status(400).send(renderMessage('Spotify authorization failed', String(error)));
  }
  if (!state || !pendingStates.has(String(state))) {
    return res.status(400).send(renderMessage('Invalid state', 'The login request could not be verified. Try again.'));
  }
  pendingStates.delete(String(state));

  if (!code) {
    return res.status(400).send(renderMessage('Login error', 'Spotify did not return an authorization code.'));
  }

  try {
    await exchangeCodeForTokens(String(code));
    console.log('[callback] Login complete, redirecting to display.');
    res.redirect('/');
  } catch (err) {
    console.error('[callback] Token exchange failed:', err.message);
    res.status(500).send(renderMessage('Login error', err.message));
  }
});

// ---------- API ----------

app.get('/api/status', (req, res) => {
  const authenticated = isAuthenticated();
  console.log(`[status] authenticated=${authenticated}`);
  res.json({ authenticated });
});

app.get('/api/search', requireAuth, async (req, res) => {
  const q = String(req.query.q || '').trim();
  if (!q) return res.json({ tracks: [] });
  try {
    const tracks = await searchTracks(q);
    res.json({ tracks });
  } catch (err) {
    console.error(err);
    res.status(502).json({ error: 'search_failed', message: err.message });
  }
});

// Add a song to the app-managed request queue. The adder implicitly upvotes it.
// We resolve the full track from its URI/id so the queue can display + play it.
app.post('/api/queue', requireAuth, async (req, res) => {
  const uri = String(req.body?.uri || '');
  if (!uri.startsWith('spotify:track:')) {
    return res.status(400).json({ error: 'bad_uri', message: 'A valid track URI is required.' });
  }
  const trackId = uri.split(':')[2];
  try {
    const track = await getTrack(trackId);
    addTrack(track, req.guestId);
    res.json({ ok: true, queue: getQueueFor(req.guestId) });
  } catch (err) {
    console.error(err);
    res.status(502).json({ error: 'queue_failed', message: err.message });
  }
});

// Toggle this guest's vote on a queued track.
app.post('/api/vote', requireAuth, (req, res) => {
  const trackId = String(req.body?.trackId || '');
  if (!trackId) {
    return res.status(400).json({ error: 'bad_request', message: 'trackId is required.' });
  }
  try {
    const entry = toggleVote(trackId, req.guestId);
    res.json({ ok: true, entry, queue: getQueueFor(req.guestId) });
  } catch (err) {
    if (err.code === 'NOT_FOUND') {
      return res.status(404).json({ error: 'not_found', message: err.message });
    }
    console.error(err);
    res.status(500).json({ error: 'vote_failed', message: err.message });
  }
});

app.get('/api/now-playing', requireAuth, async (req, res) => {
  try {
    const now = await getNowPlaying();
    res.json(now);
  } catch (err) {
    console.error(err);
    res.status(502).json({ error: 'now_playing_failed', message: err.message });
  }
});

// The app-managed, vote-sorted request queue (highest votes first).
app.get('/api/queue', (req, res) => {
  res.json({ queue: getQueueFor(req.guestId) });
});

// QR code (PNG data URL) that points guests at the add page.
app.get('/api/qrcode', async (req, res) => {
  const target = `${config.publicBaseUrl.replace(/\/$/, '')}/add`;
  try {
    const dataUrl = await QRCode.toDataURL(target, {
      margin: 1,
      width: 320,
      color: { dark: '#000000', light: '#ffffff' },
    });
    res.json({ url: target, dataUrl });
  } catch (err) {
    console.error(err);
    res.status(500).json({ error: 'qr_failed', message: err.message });
  }
});

// ---------- Pages ----------

app.get('/', (req, res) => {
  res.sendFile(path.join(publicDir, 'display.html'));
});

app.get('/add', (req, res) => {
  res.sendFile(path.join(publicDir, 'add.html'));
});

// ---------- Helpers ----------

function requireAuth(req, res, next) {
  if (!isAuthenticated()) {
    return res.status(401).json({ error: 'not_authenticated', message: 'Host has not logged in to Spotify yet.' });
  }
  next();
}

function renderMessage(title, detail) {
  return `<!doctype html><html><head><meta charset="utf-8"><title>${title}</title>
  <style>body{font-family:system-ui;background:#121212;color:#fff;display:grid;place-items:center;height:100vh;margin:0}
  .card{max-width:520px;padding:32px;text-align:center}a{color:#1db954}</style></head>
  <body><div class="card"><h1>${title}</h1><p>${detail}</p><p><a href="/">Back to jukebox</a></p></div></body></html>`;
}

app.listen(config.port, () => {
  console.log(`\n🎵 SpotiFrax jukebox running`);
  console.log(`   Display:  ${config.publicBaseUrl}`);
  console.log(`   Add page: ${config.publicBaseUrl}/add`);
  if (!config.clientId || !config.clientSecret) {
    console.log('\n⚠️  Set SPOTIFY_CLIENT_ID and SPOTIFY_CLIENT_SECRET in .env before logging in.');
  }
  // Start the background loop that feeds the top-voted song into Spotify.
  startFeeder();
});

import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { config } from './config.js';

const SPOTIFY_ACCOUNTS = 'https://accounts.spotify.com';
const SPOTIFY_API = 'https://api.spotify.com/v1';

const __dirname = path.dirname(fileURLToPath(import.meta.url));
// Persist tokens to disk so a server restart (e.g. --watch) keeps the host logged in.
const TOKEN_FILE = path.join(__dirname, '..', '.tokens.json');

/**
 * Token store for the single host account, backed by a file so restarts
 * don't force a re-login. For multi-tenant you'd persist per user.
 */
const tokens = {
  accessToken: null,
  refreshToken: null,
  expiresAt: 0, // epoch ms
};

// Load any previously saved tokens on startup.
loadTokens();

function loadTokens() {
  try {
    if (!fs.existsSync(TOKEN_FILE)) return;
    const saved = JSON.parse(fs.readFileSync(TOKEN_FILE, 'utf8'));
    if (saved.refreshToken) {
      tokens.accessToken = saved.accessToken ?? null;
      tokens.refreshToken = saved.refreshToken;
      tokens.expiresAt = saved.expiresAt ?? 0;
      console.log('[auth] Loaded saved tokens — host still authenticated.');
    }
  } catch (err) {
    console.warn('[auth] Could not read saved tokens:', err.message);
  }
}

function saveTokens() {
  try {
    fs.writeFileSync(TOKEN_FILE, JSON.stringify(tokens), { mode: 0o600 });
  } catch (err) {
    console.warn('[auth] Could not save tokens:', err.message);
  }
}

export function isAuthenticated() {
  return Boolean(tokens.refreshToken || (tokens.accessToken && Date.now() < tokens.expiresAt));
}

function basicAuthHeader() {
  const raw = `${config.clientId}:${config.clientSecret}`;
  return `Basic ${Buffer.from(raw).toString('base64')}`;
}

/** Build the URL the host visits to authorize the app. */
export function buildAuthorizeUrl(state) {
  const params = new URLSearchParams({
    response_type: 'code',
    client_id: config.clientId,
    scope: config.scopes,
    redirect_uri: config.redirectUri,
    state,
    show_dialog: 'false',
  });
  return `${SPOTIFY_ACCOUNTS}/authorize?${params.toString()}`;
}

/** Exchange an authorization code for access + refresh tokens. */
export async function exchangeCodeForTokens(code) {
  const body = new URLSearchParams({
    grant_type: 'authorization_code',
    code,
    redirect_uri: config.redirectUri,
  });

  const res = await fetch(`${SPOTIFY_ACCOUNTS}/api/token`, {
    method: 'POST',
    headers: {
      Authorization: basicAuthHeader(),
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body,
  });

  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Token exchange failed (${res.status}): ${text}`);
  }

  const data = await res.json();
  storeTokenResponse(data);
  console.log('[auth] Token exchange succeeded. Host is now authenticated.');
  return data;
}

function storeTokenResponse(data) {
  if (data.access_token) tokens.accessToken = data.access_token;
  // Refresh responses don't always include a new refresh token — keep the old one.
  if (data.refresh_token) tokens.refreshToken = data.refresh_token;
  const expiresInMs = (data.expires_in ?? 3600) * 1000;
  // Refresh a minute early to avoid edge-of-expiry failures.
  tokens.expiresAt = Date.now() + expiresInMs - 60_000;
  saveTokens();
}

async function refreshAccessToken() {
  if (!tokens.refreshToken) {
    throw new Error('Not authenticated: no refresh token. Host must log in.');
  }

  const body = new URLSearchParams({
    grant_type: 'refresh_token',
    refresh_token: tokens.refreshToken,
  });

  const res = await fetch(`${SPOTIFY_ACCOUNTS}/api/token`, {
    method: 'POST',
    headers: {
      Authorization: basicAuthHeader(),
      'Content-Type': 'application/x-www-form-urlencoded',
    },
    body,
  });

  if (!res.ok) {
    const text = await res.text();
    // A 400 here usually means the refresh token was revoked/invalid.
    // Clear the store so the display correctly shows the login gate again.
    if (res.status === 400) {
      clearTokens();
      console.warn('[auth] Refresh token rejected — cleared. Host must log in again.');
    }
    throw new Error(`Token refresh failed (${res.status}): ${text}`);
  }

  const data = await res.json();
  storeTokenResponse(data);
}

function clearTokens() {
  tokens.accessToken = null;
  tokens.refreshToken = null;
  tokens.expiresAt = 0;
  try {
    if (fs.existsSync(TOKEN_FILE)) fs.unlinkSync(TOKEN_FILE);
  } catch (_) { /* ignore */ }
}

async function getValidAccessToken() {
  if (!tokens.accessToken || Date.now() >= tokens.expiresAt) {
    await refreshAccessToken();
  }
  return tokens.accessToken;
}

/**
 * Authenticated fetch against the Spotify Web API.
 * Retries once after a forced token refresh on a 401.
 */
async function apiFetch(path, options = {}, retry = true) {
  const accessToken = await getValidAccessToken();
  const res = await fetch(`${SPOTIFY_API}${path}`, {
    ...options,
    headers: {
      Authorization: `Bearer ${accessToken}`,
      'Content-Type': 'application/json',
      ...(options.headers || {}),
    },
  });

  if (res.status === 401 && retry) {
    await refreshAccessToken();
    return apiFetch(path, options, false);
  }

  return res;
}

/** Search tracks. Returns a trimmed array of track objects. */
export async function searchTracks(query, limit = 12) {
  const params = new URLSearchParams({
    q: query,
    type: 'track',
    limit: String(limit),
  });
  const res = await apiFetch(`/search?${params.toString()}`);
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Search failed (${res.status}): ${text}`);
  }
  const data = await res.json();
  return (data.tracks?.items || []).map(trimTrack);
}

/** Fetch a single track's details by its Spotify id. */
export async function getTrack(trackId) {
  const res = await apiFetch(`/tracks/${encodeURIComponent(trackId)}`);
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Get track failed (${res.status}): ${text}`);
  }
  const data = await res.json();
  return trimTrack(data);
}

/** Add a track (by URI) to the active device's playback queue. */
export async function addToQueue(uri) {
  const params = new URLSearchParams({ uri });
  const res = await apiFetch(`/me/player/queue?${params.toString()}`, {
    method: 'POST',
  });

  if (res.status === 404) {
    throw new NoActiveDeviceError();
  }
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Add to queue failed (${res.status}): ${text}`);
  }
  return true;
}

/** Current playback state (now playing + progress). */
export async function getNowPlaying() {
  const res = await apiFetch('/me/player/currently-playing');
  if (res.status === 204) {
    return { isPlaying: false, track: null };
  }
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Now playing failed (${res.status}): ${text}`);
  }
  const data = await res.json();
  if (!data || !data.item) {
    return { isPlaying: false, track: null };
  }
  return {
    isPlaying: Boolean(data.is_playing),
    progressMs: data.progress_ms ?? 0,
    track: trimTrack(data.item),
  };
}

/** Upcoming queue (Spotify returns currently_playing + queue array). */
export async function getQueue() {
  const res = await apiFetch('/me/player/queue');
  if (res.status === 204) {
    return { currentlyPlaying: null, queue: [] };
  }
  if (!res.ok) {
    const text = await res.text();
    throw new Error(`Queue fetch failed (${res.status}): ${text}`);
  }
  const data = await res.json();
  return {
    currentlyPlaying: data.currently_playing ? trimTrack(data.currently_playing) : null,
    queue: (data.queue || []).map(trimTrack),
  };
}

/** Reduce a Spotify track object to the fields the UI needs. */
function trimTrack(t) {
  if (!t) return null;
  return {
    id: t.id,
    uri: t.uri,
    name: t.name,
    durationMs: t.duration_ms,
    artists: (t.artists || []).map((a) => a.name),
    album: t.album?.name,
    image:
      t.album?.images?.[0]?.url ||
      t.album?.images?.[1]?.url ||
      null,
  };
}

export class NoActiveDeviceError extends Error {
  constructor() {
    super('No active Spotify device found. Open Spotify and start playing on a device.');
    this.name = 'NoActiveDeviceError';
    this.code = 'NO_ACTIVE_DEVICE';
  }
}

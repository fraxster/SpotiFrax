import dotenv from 'dotenv';

dotenv.config();

function required(name, fallback) {
  const value = process.env[name] ?? fallback;
  if (value === undefined || value === '') {
    console.warn(`[config] Missing environment variable: ${name}`);
  }
  return value;
}

export const config = {
  clientId: required('SPOTIFY_CLIENT_ID'),
  clientSecret: required('SPOTIFY_CLIENT_SECRET'),
  redirectUri: required('SPOTIFY_REDIRECT_URI', 'http://127.0.0.1:8888/callback'),
  publicBaseUrl: required('PUBLIC_BASE_URL', 'http://127.0.0.1:8888'),
  port: Number(process.env.PORT || 8888),
  sessionSecret: required('SESSION_SECRET', 'dev-insecure-secret-change-me'),

  // Scopes needed to read playback + control the queue.
  scopes: [
    'user-read-playback-state',
    'user-modify-playback-state',
    'user-read-currently-playing',
  ].join(' '),
};

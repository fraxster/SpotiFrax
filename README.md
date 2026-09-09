# 🎵 SpotiFrax — Spotify Jukebox

A party jukebox powered by Spotify. Put the **display** on a big screen. Guests
**scan the QR code** on their phones to search Spotify and add songs to the
playback queue. The display shows the **now-playing** track (with album art and a
live progress bar) and the **upcoming queue**.

```
┌──────────────────────────────┐        ┌──────────────┐
│  Now Playing (album art)      │        │   Add song   │
│  ▓▓▓▓▓▓▓░░░░░  1:12 / 3:40     │        │  [ QR code ] │
│                               │        ├──────────────┤
│                               │        │  Up next     │
│                               │        │  1. …        │
└──────────────────────────────┘        └──────────────┘
        DISPLAY  (big screen)               GUEST (phone)
```

## Requirements

- **Node.js 18 or newer** (uses built-in `fetch` and `--watch`).
- A **Spotify Premium** account. Playback control (queue, now-playing) is a
  Premium-only feature of the Spotify Web API.
- The Spotify app open and **actively playing on some device** (phone, desktop,
  speaker). Spotify needs an *active device* to accept queue additions.

> Don't have Node yet? Install it from <https://nodejs.org> (LTS), or via a
> version manager like `nvm`, `fnm`, or `volta`. Then re-run the steps below.

## 1. Create a Spotify app

1. Go to the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard).
2. Create an app. Copy the **Client ID** and **Client Secret**.
3. In the app settings, add a **Redirect URI** that exactly matches your
   `SPOTIFY_REDIRECT_URI` below. For local use:
   ```
   http://127.0.0.1:8888/callback
   ```
   (Spotify no longer allows `localhost` — use `127.0.0.1`.)

## 2. Configure environment

```bash
cp .env.example .env
```

Edit `.env`:

| Variable                | What it is                                                        |
| ----------------------- | ----------------------------------------------------------------- |
| `SPOTIFY_CLIENT_ID`     | From the Spotify dashboard.                                       |
| `SPOTIFY_CLIENT_SECRET` | From the Spotify dashboard.                                       |
| `SPOTIFY_REDIRECT_URI`  | Must match the dashboard Redirect URI exactly.                    |
| `PUBLIC_BASE_URL`       | The URL guests reach. See the QR note below.                     |
| `PORT`                  | Server port (default `8888`).                                     |
| `SESSION_SECRET`        | Any long random string.                                           |

## 3. Install & run

```bash
npm install
npm start        # or: npm run dev  (auto-restart on file changes)
```

Open the display: <http://127.0.0.1:8888>

> **Hosting it online?** See **[DEPLOYMENT.md](./DEPLOYMENT.md)** for putting
> SpotiFrax on a web host (PaaS, VPS, or a Node-capable shared host), including
> HTTPS, the production redirect URI, and keeping the voting feeder alive.
>
> **On classic PHP-only shared hosting?** There's a no-Node, no-database
> **[PHP version in `php/`](./php/README.md)** built for exactly that.

## 4. Use it

1. On the display, click **Log in with Spotify** and authorize the app (host).
2. Start playing something in your Spotify app so there's an **active device**.
3. Guests scan the QR code, search a song, and tap **Add**.
4. Added songs appear in **Up next**. Guests **vote** on queued songs — the
   most-voted song rises to the top and plays next.

## Voting: how songs get ordered

Spotify's Web API **cannot reorder its own playback queue** — once a song is in
Spotify's queue you can't move it. So SpotiFrax keeps its **own request queue**
and only hands songs to Spotify one at a time:

- Guests add songs to the SpotiFrax queue (adding counts as your first vote).
- Anyone can tap **▲** to vote a song up, or tap again to remove their vote.
  One vote per guest per song (tracked by an anonymous cookie).
- The queue is sorted by **votes** (highest first), ties broken by who was
  added first.
- A background feeder watches the currently playing track. About 8 seconds
  before it ends, it pushes the **current top-voted song** into Spotify so it
  plays next, then removes it from the SpotiFrax queue.

The upshot: votes cast before a song leaves the queue decide play order. Once a
song has been handed to Spotify it's locked in (Spotify won't let us pull it
back), so late votes only affect songs still waiting in the queue.

## Making the QR code work for guests' phones

Guests' phones can't reach `127.0.0.1` — that points at their own device.
For a real party, run the server on a machine on the same Wi-Fi and set
`PUBLIC_BASE_URL` (and the app's Redirect URI / `SPOTIFY_REDIRECT_URI`) to that
machine's LAN IP, e.g.:

```
PUBLIC_BASE_URL=http://192.168.1.20:8888
SPOTIFY_REDIRECT_URI=http://192.168.1.20:8888/callback
```

Find your IP with `ipconfig getifaddr en0` (macOS). Add the matching Redirect URI
in the Spotify dashboard. Alternatively, expose the server with a tunneling tool
(e.g. `ngrok http 8888`) and use the public HTTPS URL for both values.

## How it works

- **Backend** (`src/`): Express server. Handles Spotify OAuth
  (Authorization Code flow), refreshes tokens automatically, and proxies the
  Spotify Web API.
  - `config.js` — env + OAuth scopes.
  - `spotify.js` — token store (persisted to `.tokens.json`), `searchTracks`,
    `getTrack`, `addToQueue`, `getNowPlaying`.
  - `jukebox.js` — the vote-sorted request queue and the background feeder that
    hands the top song to Spotify near the end of each track.
  - `server.js` — routes, guest-id cookie, & static hosting.
- **Frontend** (`public/`):
  - `display.html` / `display.js` — big-screen now-playing + vote-sorted queue + QR.
  - `add.html` / `add.js` — mobile search, add, and vote page.

### API endpoints

| Method | Path                | Purpose                              |
| ------ | ------------------- | ------------------------------------ |
| GET    | `/login`            | Start Spotify OAuth (host).          |
| GET    | `/callback`         | OAuth redirect target.               |
| GET    | `/api/status`       | Whether the host is authenticated.   |
| GET    | `/api/search?q=`    | Search tracks.                       |
| POST   | `/api/queue`        | Add `{ uri }` to the request queue.  |
| POST   | `/api/vote`         | Toggle this guest's vote: `{ trackId }`. |
| GET    | `/api/now-playing`  | Current track + progress.            |
| GET    | `/api/queue`        | Vote-sorted request queue.           |
| GET    | `/api/qrcode`       | QR code (PNG data URL) for `/add`.   |

## Notes & limitations

- **Scopes used:** `user-read-playback-state`, `user-modify-playback-state`,
  `user-read-currently-playing`.
- Tokens are persisted to `.tokens.json` so a restart doesn't force re-login.
  Delete that file (or revoke access in Spotify) to log out.
- **The Spotify queue can't be reordered via the API.** Voting reorders
  SpotiFrax's own request queue; the feeder then hands songs to Spotify one at a
  time near the end of each track. A song already handed to Spotify can't be
  moved by later votes.
- Voting is per-guest via an anonymous cookie — clearing cookies or using a new
  device gives a fresh identity. It's a party, not an election.
- **"No active device"** means Spotify isn't actively playing anywhere. The
  feeder holds the queue and retries once you start playback on a device.

## License

MIT

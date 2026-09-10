# 🎵 SpotiFrax — PHP version

The same Spotify jukebox as the Node app, rewritten in **plain PHP** so it runs
on **classic shared hosting** (cPanel/Plesk, FTP-only PHP hosts) with no Node,
no Composer, and no database. Put the display on a big screen; guests scan a QR
code to search, add, and **vote** on songs. The most-voted song plays next.

## Why a PHP version?

The Node version needs a host that keeps a process running. Plenty of cheap
shared hosting only runs PHP per-request. This version fits that: state lives in
JSON files, and the "feeder" that hands the top-voted song to Spotify runs
**on each poll request** instead of in a background loop.

## Requirements

- **PHP 7.4+** (8.x recommended) with the **cURL** extension enabled (standard
  on virtually all hosts).
- A writable directory for state (the included `data/` folder, or a custom path).
- A **Spotify Premium** account, with Spotify **actively playing on a device**
  (queue control and now-playing are Premium features).
- A public **HTTPS** URL for a real deployment (Spotify requires it for the
  redirect URI on any non-`127.0.0.1` domain).

## Files

```
php/
├── index.php            # Display page (big screen)
├── add.php              # Guest page (mobile: search + add + vote)
├── admin.php            # Management page (logo, sizes; log in to moderate)
├── login.php            # Starts Spotify OAuth
├── callback.php         # OAuth redirect target
├── config.php           # Reads config.local.php (or env vars)
├── config.local.example.php  # Copy to config.local.php and fill in
├── api/                 # Public JSON endpoints (status, search, queue, vote, now-playing, qrcode, settings)
├── admin/               # Admin-only endpoints (login, logout, save-settings, upload-logo, remove-track)
├── assets/              # styles.css, display.js, add.js, admin.js, qrcode.min.js, uploads/
├── lib/                 # Store.php, Spotify.php, Jukebox.php, Settings.php, bootstrap.php
└── data/                # JSON state (tokens/queue/settings) — must be writable
```

## 1. Create a Spotify app

1. Go to the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard)
   and create an app. Copy the **Client ID** and **Client Secret**.
2. Add a **Redirect URI** matching where `callback.php` will live:
   - Local test: `http://127.0.0.1:8888/callback.php`
   - Production: `https://your-domain.com/callback.php`
     (or `https://your-domain.com/spotifrax/callback.php` if in a subfolder).

   Spotify only allows `http` for `127.0.0.1`; any real domain must be `https`.

## 2. Configure

Copy the example config and fill it in:

```bash
cp config.local.example.php config.local.php
```

Edit `config.local.php`:

```php
return [
    'SPOTIFY_CLIENT_ID'     => '…',
    'SPOTIFY_CLIENT_SECRET' => '…',
    'SPOTIFY_REDIRECT_URI'  => 'https://your-domain.com/callback.php',
    'PUBLIC_BASE_URL'       => 'https://your-domain.com',
    'ADMIN_PASSWORD'        => 'a-strong-password',  // enables the manage page
    // 'DATA_DIR'           => '/home/youruser/spotifrax-data', // optional
];
```

`PUBLIC_BASE_URL` is what the QR code points at (it links to
`PUBLIC_BASE_URL/add.php`), so it must be the address guests' phones can reach.
`ADMIN_PASSWORD` unlocks the [management page and moderation](#management-page--moderation);
leave it out to disable admin entirely.

> Prefer environment variables? `config.local.php` takes precedence when it sets
> a non-empty value; an environment variable is used only as a fallback for keys
> the file doesn't define.

## 3. Upload and set permissions

Upload the contents of this `php/` folder to your web space (e.g. `public_html/`
or a subfolder). Then make the state directory writable by the web server:

```bash
chmod 770 data
# If PHP runs as a different user than your FTP user and 770 isn't enough:
# chmod 777 data   (less ideal; use only if your host requires it)
```

The app writes `data/tokens.json`, `data/queue.json`, and `data/settings.json`
here. If you set a custom `DATA_DIR`, make **that** directory writable instead —
ideally place it **outside** the web root so state files can never be served.

If you want to **upload logos** from the management page, also make the uploads
folder writable:

```bash
chmod 775 assets/uploads
```

(You can skip this and use a logo **URL** instead, which needs no writable folder.)

## 4. Local testing (optional)

With PHP installed you can run the built-in server from inside `php/`:

```bash
php -S 127.0.0.1:8888
```

Then open <http://127.0.0.1:8888>. Use the `127.0.0.1:8888/callback.php` redirect
URI for this.

## 5. Use it

1. Open the display URL. Click **Log in with Spotify** (host).
2. Start playback on any Spotify device so there's an **active device**.
3. Guests scan the QR code, search, add a song, and tap **▲** to vote.
4. The most-voted song rises to the top and is handed to Spotify as the current
   track nears its end.

## How voting works (and a key Spotify limit)

Spotify's Web API **cannot reorder its own playback queue**. So SpotiFrax keeps
its **own** queue (`data/queue.json`) and hands songs to Spotify one at a time:

- Adding a song counts as your first vote. Tapping **▲** adds/removes a vote.
  One vote per guest per song (anonymous cookie).
- The queue is sorted by votes, ties broken by who was added first.
- **The feeder runs on each poll** (there's no background process on shared
  hosting). When the display or a guest phone fetches the queue / now-playing,
  the server checks whether the current track is within ~8 seconds of ending and,
  if so, pushes the top-voted song into Spotify and removes it from the queue.

Because the feeder only runs when someone's page is open and polling, **keep the
display page open** during the event. If every page is closed, nothing polls and
the queue won't advance — see the cron option below to make it self-driving.

### Optional: a cron job to drive the feeder without an open page

If your host offers cron, hit the queue endpoint periodically so the feeder runs
even when no page is open:

```
* * * * * curl -s "https://your-domain.com/api/queue.php" > /dev/null
```

(Every minute is plenty; the feeder throttles itself internally.)

## Management page & moderation

Set `ADMIN_PASSWORD` in `config.local.php`, then open **`admin.php`**
(e.g. `https://your-domain.com/admin.php`) and log in with that password.

From the management page you can:

- **Branding** — set a display **title** and a **logo**, either by pasting an
  image **URL** or **uploading** a file (PNG/JPG/GIF/WEBP/SVG, up to 3 MB). The
  logo and title show at the top of the now-playing panel.
- **Widget sizes** — three sliders scale the **album art**, the **QR code**, and
  the **queue text** independently (shown as a percentage).

Click **Save changes**. The display picks up new settings within a few seconds —
no reload needed — because it polls `api/settings.php` on a timer.

### Moderating the queue (removing songs)

While you're logged in as admin, open the **display** (`index.php`) in the same
browser. A small **✕ remove** button appears on each song in the “Up next” list.
Clicking it deletes that request from the queue (votes and all). Guests never see
these buttons — they only appear for a logged-in admin session.

> Admin state lives in a PHP **session cookie**, so the display and `admin.php`
> must be opened in the **same browser** for moderation buttons to appear. Use
> the **Log out** button on `admin.php` when you're done.

Note: removing a song only affects **SpotiFrax's** queue. A song that has already
been handed to Spotify (the current or on-deck track) can't be pulled back via
the API — see the Spotify limitation above.

## API endpoints

| Method | Path | Purpose |
| --- | --- | --- |
| GET  | `login.php`          | Start Spotify OAuth (host). |
| GET  | `callback.php`       | OAuth redirect target. |
| GET  | `api/status.php`     | Whether the host is authenticated. |
| GET  | `api/search.php?q=`  | Search tracks. |
| POST | `api/queue.php`      | Add `{ uri }` to the request queue. |
| GET  | `api/queue.php`      | Vote-sorted queue (also runs the feeder). |
| POST | `api/vote.php`       | Toggle this guest's vote: `{ trackId }`. |
| GET  | `api/now-playing.php`| Current track + progress (also runs the feeder). |
| GET  | `api/qrcode.php`     | The add-page URL (QR is drawn client-side). |
| GET  | `api/settings.php`   | Display settings + whether you're an admin. |
| POST | `admin/login.php`    | Admin login: `{ password }`. |
| GET  | `admin/logout.php`   | End the admin session. |
| POST | `admin/save-settings.php` | Save settings (admin only). |
| POST | `admin/upload-logo.php`   | Upload a logo file (admin only, `multipart`). |
| POST | `admin/remove-track.php`  | Remove a song: `{ trackId }` (admin only). |

## Security notes

- `config.local.php`, `data/`, and `lib/` are protected by `.htaccess` rules and
  are git-ignored. On **Nginx** (no `.htaccess`), block these paths yourself, or
  set `DATA_DIR` to a folder outside the web root.
- Never commit `config.local.php` or the `data/` JSON files — they hold your
  secret and Spotify tokens.
- The guest cookie is a casual anonymous id, not a security control; clearing
  cookies lets someone vote again. It's a party, not an election.
- Keep `SPOTIFY_CLIENT_SECRET` server-side only.
- **Admin:** the management page and moderation are gated by `ADMIN_PASSWORD`
  (compared in constant time) and a PHP session. Use a strong password and serve
  the site over **HTTPS** so the login and session cookie aren't sent in clear.
  Admin is fully disabled if `ADMIN_PASSWORD` is empty. Uploaded logos are
  size/type-checked and stored in `assets/uploads/`, where an `.htaccess` turns
  off script execution.

## Troubleshooting

- **"INVALID_CLIENT: Invalid redirect URI"** → `SPOTIFY_REDIRECT_URI` must match
  the dashboard entry exactly, including `https`, host, path, and `callback.php`.
- **Stuck on "Connect Spotify"** → login failed or `data/` isn't writable (tokens
  couldn't be saved). Check the web server error log for `[SpotiFrax]` lines and
  confirm `data/` permissions.
- **Songs don't advance by votes** → no active Spotify device, or no page is open
  to drive the request-time feeder. Start playback; keep the display open or add
  the cron job above.
- **500 error / blank page** → confirm PHP 7.4+ and the cURL extension; check the
  host's PHP error log.
- **QR doesn't reach guests** → `PUBLIC_BASE_URL` must be the public https address,
  not `127.0.0.1` or a LAN IP guests can't reach.

## Differences from the Node version

| | Node | PHP |
| --- | --- | --- |
| Runtime | Persistent Node process | Per-request PHP |
| Queue feeder | Background loop | Runs on each poll (or via cron) |
| State storage | In-memory + `.tokens.json` | JSON files in `data/` |
| Hosting | PaaS / VPS / Node panel | Classic shared hosting works |
| Dependencies | npm packages | None (uses cURL + a vendored QR JS) |

## Credits

QR rendering uses [qrcodejs](https://github.com/davidshimjs/qrcodejs) (MIT),
vendored at `assets/qrcode.min.js` so no CDN or internet access is needed.

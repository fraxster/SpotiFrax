# Deploying SpotiFrax to a web host

This is a **Node.js server** (Express) with a long-running background process
(the voting feeder). It is **not** a static site or a PHP app, so it needs a host
that can run a persistent Node process and let it make outbound HTTPS calls to
Spotify.

Read this whole page before picking a host — the "Will my host work?" section
saves the most pain.

---

## Will my host work?

| Host type | Works? | Notes |
| --- | --- | --- |
| Node-capable PaaS (Render, Railway, Fly.io, Heroku, DigitalOcean App Platform) | ✅ Easiest | Runs `npm start`, gives you HTTPS. Recommended. |
| VPS / cloud VM (DigitalOcean Droplet, Hetzner, EC2, Linode) | ✅ Most control | You install Node + a process manager + a reverse proxy yourself. |
| Shared hosting **with a "Setup Node.js App"** panel (cPanel/Plesk, e.g. A2, Namecheap, Hostinger) | ⚠️ Sometimes | Works only if the panel supports persistent Node apps. The background feeder needs the process to stay alive. |
| Classic shared hosting (PHP/HTML only, FTP upload) | ❌ Not for the Node app | Can't run Node — but there's a **[PHP version](./php/README.md)** built exactly for this. |
| Static hosts (GitHub Pages, Netlify static, S3) | ❌ No | Static files only; there's no server to run. |

**Two hard requirements**, whatever you choose:

1. **A public HTTPS URL.** Spotify requires the OAuth redirect URI to be
   reachable, and guests' phones need to reach it. In production use `https://`.
2. **A persistent process.** The feeder that pushes top-voted songs into Spotify
   runs in the background. Serverless/function hosts that spin down between
   requests will pause voting playback — avoid them for this app.

---

## Before you deploy (applies to every host)

### 1. Update the Spotify app settings

In the [Spotify Developer Dashboard](https://developer.spotify.com/dashboard),
edit your app and add a **Redirect URI** that matches your production domain:

```
https://your-domain.com/callback
```

Spotify allows `http` only for `127.0.0.1`. For any real domain you **must** use
`https`. Keep your local `http://127.0.0.1:8888/callback` entry too if you still
develop locally — an app can have several redirect URIs.

### 2. Set environment variables (do NOT upload your `.env`)

Set these in your host's dashboard / config, using your real domain:

| Variable | Production value |
| --- | --- |
| `SPOTIFY_CLIENT_ID` | from the dashboard |
| `SPOTIFY_CLIENT_SECRET` | from the dashboard (keep secret) |
| `SPOTIFY_REDIRECT_URI` | `https://your-domain.com/callback` |
| `PUBLIC_BASE_URL` | `https://your-domain.com` |
| `PORT` | whatever the host tells you to bind to (see below) |
| `SESSION_SECRET` | a long random string |

> **Port:** Many PaaS hosts inject their own `PORT` and expect you to listen on
> it. The app already reads `process.env.PORT`, so leave it unset locally but let
> the platform set it. On a VPS you choose the port and put a reverse proxy in
> front.

### 3. Note about saved login (`.tokens.json`)

The host logs in **once** and the refresh token is saved to `.tokens.json` next
to the code. Two things follow:

- The app process needs **write access** to its own directory (true on VPS/PaaS
  disks; check "ephemeral filesystem" notes below).
- On hosts with an **ephemeral filesystem** (many PaaS free tiers wipe the disk
  on every deploy/restart), `.tokens.json` disappears and the host must log in
  again after each restart. That's fine for a party. For a permanent install,
  attach a persistent volume or move token storage to a database/secret store.

---

## Option A — Node PaaS (recommended: Render / Railway / Fly.io)

Fastest path. Example uses **Render**; Railway/Fly/Heroku are very similar.

1. Push this project to a GitHub repo.
2. Create a new **Web Service** on the host and point it at the repo.
3. Configure:
   - **Build command:** `npm install`
   - **Start command:** `npm start`
   - **Environment:** add the variables from the table above.
4. Deploy. The host gives you a URL like `https://spotifrax.onrender.com`.
5. Set `PUBLIC_BASE_URL` and `SPOTIFY_REDIRECT_URI` to that URL (and add the
   `/callback` redirect URI in the Spotify dashboard), then redeploy so the
   values take effect.
6. Open the URL, click **Log in with Spotify**, start playback on a device, and
   share the QR code.

**Keep it awake:** free tiers often sleep after idle time, which pauses the
feeder. Use a paid "always-on" tier for a real event, or accept that playback
control resumes only when the site is next hit.

**Persist the login (optional):** attach a persistent disk and ensure the app's
working directory (where `.tokens.json` is written) lives on it, so a restart
doesn't require re-login.

---

## Option B — VPS / cloud VM (full control)

You get a Linux box and set everything up. This survives restarts cleanly and is
a good permanent home.

### 1. Install Node.js 18+

```bash
# Example on Ubuntu/Debian using NodeSource
curl -fsSL https://deb.nodesource.com/setup_20.x | sudo -E bash -
sudo apt-get install -y nodejs
node -v   # should print v20.x (or v18+)
```

### 2. Get the code and install deps

```bash
git clone <your-repo-url> spotifrax
cd spotifrax
npm install --omit=dev
```

### 3. Create the `.env` on the server

Create `/home/youruser/spotifrax/.env` with your production values (see the
env table above). Use your domain in `PUBLIC_BASE_URL` and `SPOTIFY_REDIRECT_URI`.

### 4. Keep it running with a process manager

Using **PM2** so it restarts on crash and on reboot:

```bash
sudo npm install -g pm2
pm2 start src/server.js --name spotifrax
pm2 save
pm2 startup        # follow the printed command to enable start-on-boot
```

Check logs (you'll see the auth and `[jukebox]` feeder messages):

```bash
pm2 logs spotifrax
```

### 5. Put HTTPS in front with a reverse proxy

Terminate TLS with Nginx (or Caddy, which is simpler) and proxy to the app on
its local port (default `8888`).

**Nginx** site config:

```nginx
server {
    server_name your-domain.com;

    location / {
        proxy_pass         http://127.0.0.1:8888;
        proxy_http_version 1.1;
        proxy_set_header   Host              $host;
        proxy_set_header   X-Real-IP         $remote_addr;
        proxy_set_header   X-Forwarded-For   $proxy_add_x_forwarded_for;
        proxy_set_header   X-Forwarded-Proto $scheme;
    }
}
```

Then get a free certificate:

```bash
sudo apt-get install -y certbot python3-certbot-nginx
sudo certbot --nginx -d your-domain.com
```

**Caddy** is a one-file alternative that auto-provisions HTTPS. A `Caddyfile`:

```
your-domain.com {
    reverse_proxy 127.0.0.1:8888
}
```

### 6. Point DNS and finish

- Add an `A` record for `your-domain.com` → your server's IP.
- Confirm `https://your-domain.com` loads the display.
- Log in with Spotify, start playback, share the QR.

---

## Option C — Shared hosting with a Node.js app panel (cPanel/Plesk)

Only works if your plan explicitly offers **"Setup Node.js App"** (or similar).
Classic PHP-only shared hosting will not run this.

Typical cPanel flow:

1. Upload the project (Git or the File Manager). Do **not** upload `node_modules`
   or `.env`.
2. Open **Setup Node.js App** → **Create Application**:
   - **Node version:** 18 or newer.
   - **Application root:** the folder you uploaded to.
   - **Application startup file:** `src/server.js`.
3. Add the environment variables from the table above in the panel.
4. Click **Run NPM Install**, then **Start** the app.
5. The panel maps your domain to the app and handles HTTPS. Set
   `PUBLIC_BASE_URL` / `SPOTIFY_REDIRECT_URI` to that HTTPS domain and add the
   redirect URI in the Spotify dashboard.

**Caveat:** some shared panels recycle idle Node apps, which pauses the feeder.
If songs stop advancing when the page is idle, the app is being suspended —
you'll need an always-on tier or a VPS instead.

---

## Post-deploy checklist

- [ ] `https://your-domain.com` shows the display (not a "connect" error page).
- [ ] Spotify dashboard has `https://your-domain.com/callback` as a redirect URI.
- [ ] `SPOTIFY_REDIRECT_URI` and `PUBLIC_BASE_URL` both use the **https** domain.
- [ ] Clicking **Log in with Spotify** completes and returns to the display
      (server log shows `[callback] Login complete`).
- [ ] Playback is active on a Spotify device; now-playing shows on the display.
- [ ] Scanning the QR on a phone opens the add page **over the internet**.
- [ ] Adding a song and voting reorders the queue; the feeder logs
      `[jukebox] Fed top-voted "…"` as songs hand off.

## Security notes for a public deployment

- Never commit or upload `.env` or `.tokens.json`; both are already git-ignored.
- Anyone with the URL can add and vote on songs — that's the point at a party,
  but don't expose it wider than you intend. Put it behind a hard-to-guess
  subdomain or basic auth if needed.
- Keep `SPOTIFY_CLIENT_SECRET` only in server-side environment variables, never
  in client code.
- The guest cookie is a casual, anonymous identifier, not a security control.
  Determined users can clear cookies to vote again.

## Troubleshooting

- **"INVALID_CLIENT: Invalid redirect URI"** on login → the dashboard redirect
  URI doesn't byte-for-byte match `SPOTIFY_REDIRECT_URI` (check http vs https,
  trailing slash, port).
- **Stuck on "Connect Spotify"** after deploy → the process restarted and lost
  `.tokens.json` (ephemeral disk), or login failed. Check server logs for
  `[callback] Token exchange failed`.
- **Songs never advance by votes** → no active Spotify device, or the host
  platform is suspending the idle process. Start playback; use an always-on tier.
- **QR works on your laptop but not guests' phones** → `PUBLIC_BASE_URL` still
  points at localhost/LAN instead of the public https domain.

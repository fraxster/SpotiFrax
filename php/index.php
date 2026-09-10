<?php
/**
 * Display page (big screen). Pure HTML/JS shell — all data comes from api/*.php.
 * This is a .php file only so it works as the directory index on any host.
 */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>SpotiFrax Jukebox</title>
  <link rel="stylesheet" href="assets/styles.css" />
</head>
<body>
  <!-- Logged-out / no-device state -->
  <div id="gate" class="center-screen" hidden>
    <div class="card">
      <div class="brand" style="justify-content:center;font-size:28px;margin-bottom:16px;">
        <span class="dot"></span> SpotiFrax
      </div>
      <h1 id="gate-title">Connect Spotify to start the jukebox</h1>
      <p id="gate-detail" style="color:var(--muted)">
        The host needs to log in with a Spotify Premium account, then start playback on any device.
      </p>
      <p><button id="login-btn">Log in with Spotify</button></p>
      <p style="margin-top:18px;"><a href="admin.php" style="color:var(--muted);font-size:13px;">Manage display</a></p>
    </div>
  </div>

  <!-- Main display -->
  <main id="display" class="display" hidden>
    <section class="now">
      <div id="brand-bar" class="brand-bar">
        <img id="brand-logo" class="brand-logo" alt="" hidden />
        <span id="brand-title" class="brand-title"></span>
      </div>
      <img id="art" class="now-art" alt="Album art" />
      <div>
        <h1 id="title" class="now-title">Nothing playing</h1>
        <p id="artist" class="now-artist">Start playing on Spotify</p>
      </div>
      <div>
        <div class="progress"><div id="bar" class="progress-bar"></div></div>
        <div class="times"><span id="elapsed">0:00</span><span id="duration">0:00</span></div>
      </div>
    </section>

    <aside class="side">
      <div class="qr-card">
        <h2>Add a song</h2>
        <p style="color:var(--muted);margin:0 0 14px;">Scan to request tracks</p>
        <!-- The QR is rendered into this div by qrcode.min.js -->
        <div id="qr" style="display:inline-block;background:#fff;padding:10px;border-radius:12px;"></div>
        <p id="qr-url"></p>
      </div>

      <div class="queue-card">
        <h2>Up next · most voted plays first</h2>
        <ul id="queue" class="queue-list">
          <li class="empty">Queue is empty — scan the code to add songs.</li>
        </ul>
      </div>
    </aside>
  </main>

  <script src="assets/qrcode.min.js"></script>
  <script src="assets/display.js"></script>
</body>
</html>

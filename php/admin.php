<?php
/** Management page. Client-side talks to admin/*.php; auth is session-based. */
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Manage · SpotiFrax</title>
  <link rel="stylesheet" href="assets/styles.css" />
</head>
<body>
  <div class="admin-page">
    <header class="add-header">
      <div class="brand"><span class="dot"></span> SpotiFrax · Manage</div>
      <a id="open-display" href="index.php" target="_blank" rel="noopener" style="font-size:14px;">Open display ↗</a>
    </header>

    <!-- Login -->
    <section id="login-view" hidden>
      <h1 style="font-size:22px;margin:0 0 6px;">Admin login</h1>
      <p style="color:var(--muted);margin:0 0 18px;">Enter the admin password to manage the display and moderate the queue.</p>
      <form id="login-form" class="admin-card">
        <label class="field">
          <span>Password</span>
          <input id="password" type="password" autocomplete="current-password" />
        </label>
        <button type="submit">Log in</button>
        <p id="login-error" class="err" hidden></p>
      </form>
      <p id="admin-disabled" class="hint" hidden>
        Admin is disabled: set <code>ADMIN_PASSWORD</code> in <code>config.local.php</code> to enable it.
      </p>
    </section>

    <!-- Management controls -->
    <section id="manage-view" hidden>
      <div class="admin-card">
        <h2>Branding</h2>
        <label class="field">
          <span>Display title (optional)</span>
          <input id="title" type="text" maxlength="60" placeholder="e.g. Fredrik's Party" />
        </label>

        <label class="field">
          <span>Logo image URL</span>
          <input id="logoUrl" type="url" placeholder="https://… or leave blank for text logo" />
        </label>

        <div class="field">
          <span>Or upload a logo</span>
          <div class="upload-row">
            <input id="logoFile" type="file" accept="image/png,image/jpeg,image/gif,image/webp,image/svg+xml" />
            <button type="button" id="upload-btn" class="secondary">Upload</button>
          </div>
          <div id="logo-preview" class="logo-preview" hidden>
            <img id="logo-preview-img" alt="Logo preview" />
            <button type="button" id="logo-clear" class="secondary small">Remove logo</button>
          </div>
        </div>
      </div>

      <div class="admin-card">
        <h2>Widget sizes</h2>
        <div class="field">
          <span>Album art <em id="albumScale-val">100%</em></span>
          <input id="albumScale" type="range" min="0.6" max="1.6" step="0.05" value="1" />
        </div>
        <div class="field">
          <span>QR code <em id="qrScale-val">100%</em></span>
          <input id="qrScale" type="range" min="0.6" max="1.8" step="0.05" value="1" />
        </div>
        <div class="field">
          <span>Queue text <em id="queueScale-val">100%</em></span>
          <input id="queueScale" type="range" min="0.7" max="1.6" step="0.05" value="1" />
        </div>
      </div>

      <div class="admin-actions">
        <button type="button" id="save-btn">Save changes</button>
        <button type="button" id="logout-btn" class="secondary">Log out</button>
        <span id="save-status" class="save-status"></span>
      </div>

      <p class="hint" style="margin-top:20px;">
        Moderating: open the display, then remove songs directly from the “Up next” list —
        remove buttons appear there while you’re logged in as admin.
      </p>
    </section>
  </div>

  <div id="toast" class="toast"></div>

  <script src="assets/admin.js"></script>
</body>
</html>

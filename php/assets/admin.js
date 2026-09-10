const el = (id) => document.getElementById(id);

const loginView = el('login-view');
const manageView = el('manage-view');
const toastEl = el('toast');
let toastTimer = null;

const scaleFields = ['albumScale', 'qrScale', 'queueScale'];

function toast(message, type = 'ok') {
  clearTimeout(toastTimer);
  toastEl.textContent = message;
  toastEl.className = `toast show ${type}`;
  toastTimer = setTimeout(() => { toastEl.className = 'toast'; }, 3000);
}

function pct(v) {
  return `${Math.round(Number(v) * 100)}%`;
}

function showLogin(adminEnabled) {
  loginView.hidden = false;
  manageView.hidden = true;
  el('admin-disabled').hidden = adminEnabled;
  el('login-form').hidden = !adminEnabled;
}

function showManage() {
  loginView.hidden = true;
  manageView.hidden = false;
}

function applySettingsToForm(s) {
  el('title').value = s.title || '';
  el('logoUrl').value = s.logoUrl || '';
  scaleFields.forEach((k) => {
    const val = s[k] ?? 1;
    el(k).value = val;
    el(`${k}-val`).textContent = pct(val);
  });
  updateLogoPreview(s.logoUrl || '');
}

function updateLogoPreview(url) {
  const wrap = el('logo-preview');
  const img = el('logo-preview-img');
  if (url) {
    img.src = url;
    wrap.hidden = false;
  } else {
    img.removeAttribute('src');
    wrap.hidden = true;
  }
}

// Keep the % labels live as sliders move.
scaleFields.forEach((k) => {
  el(k).addEventListener('input', () => {
    el(`${k}-val`).textContent = pct(el(k).value);
  });
});

el('logoUrl').addEventListener('input', () => updateLogoPreview(el('logoUrl').value.trim()));

// ---------- Auth ----------

el('login-form').addEventListener('submit', async (e) => {
  e.preventDefault();
  el('login-error').hidden = true;
  const password = el('password').value;
  try {
    const res = await fetch('admin/login.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ password }),
    });
    if (res.ok) {
      el('password').value = '';
      await loadSettings();
    } else {
      const data = await res.json().catch(() => ({}));
      el('login-error').textContent = data.message || 'Login failed.';
      el('login-error').hidden = false;
    }
  } catch (_) {
    el('login-error').textContent = 'Network error. Try again.';
    el('login-error').hidden = false;
  }
});

el('logout-btn').addEventListener('click', async () => {
  await fetch('admin/logout.php').catch(() => {});
  showLogin(true);
  toast('Logged out', 'ok');
});

// ---------- Settings ----------

async function loadSettings() {
  const res = await fetch('api/settings.php').then((r) => r.json()).catch(() => null);
  if (!res) {
    toast('Could not load settings', 'error');
    return;
  }
  if (res.isAdmin) {
    applySettingsToForm(res.settings || {});
    showManage();
  } else {
    showLogin(Boolean(res.adminEnabled));
  }
}

function collectSettings() {
  return {
    title: el('title').value,
    logoUrl: el('logoUrl').value.trim(),
    albumScale: parseFloat(el('albumScale').value),
    qrScale: parseFloat(el('qrScale').value),
    queueScale: parseFloat(el('queueScale').value),
  };
}

el('save-btn').addEventListener('click', async () => {
  const status = el('save-status');
  status.textContent = 'Saving…';
  try {
    const res = await fetch('admin/save-settings.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(collectSettings()),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      applySettingsToForm(data.settings || {});
      status.textContent = 'Saved ✓';
      toast('Settings saved', 'ok');
      setTimeout(() => { status.textContent = ''; }, 2500);
    } else if (res.status === 403) {
      status.textContent = '';
      toast('Session expired — log in again', 'error');
      showLogin(true);
    } else {
      status.textContent = '';
      toast(data.message || 'Save failed', 'error');
    }
  } catch (_) {
    status.textContent = '';
    toast('Network error', 'error');
  }
});

// ---------- Logo upload ----------

el('upload-btn').addEventListener('click', async () => {
  const input = el('logoFile');
  if (!input.files || !input.files[0]) {
    toast('Choose an image first', 'error');
    return;
  }
  const fd = new FormData();
  fd.append('logo', input.files[0]);
  el('upload-btn').disabled = true;
  el('upload-btn').textContent = 'Uploading…';
  try {
    const res = await fetch('admin/upload-logo.php', { method: 'POST', body: fd });
    const data = await res.json().catch(() => ({}));
    if (res.ok) {
      el('logoUrl').value = data.logoUrl || '';
      updateLogoPreview(data.logoUrl || '');
      toast('Logo uploaded', 'ok');
    } else if (res.status === 403) {
      toast('Session expired — log in again', 'error');
      showLogin(true);
    } else {
      toast(data.message || 'Upload failed', 'error');
    }
  } catch (_) {
    toast('Network error', 'error');
  } finally {
    el('upload-btn').disabled = false;
    el('upload-btn').textContent = 'Upload';
    el('logoFile').value = '';
  }
});

el('logo-clear').addEventListener('click', () => {
  el('logoUrl').value = '';
  updateLogoPreview('');
  toast('Logo cleared — remember to Save', 'ok');
});

loadSettings();

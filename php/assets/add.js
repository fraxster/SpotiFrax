const el = (id) => document.getElementById(id);
const input = el('q');
const results = el('results');
const spinner = el('spinner');
const hint = el('hint');
const toastEl = el('toast');

let debounceTimer = null;
let currentController = null;
let toastTimer = null;

function escapeHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;').replace(/'/g, '&#039;');
}

function toast(message, type = 'ok') {
  clearTimeout(toastTimer);
  toastEl.textContent = message;
  toastEl.className = `toast show ${type}`;
  toastTimer = setTimeout(() => {
    toastEl.className = 'toast';
  }, 3000);
}

function setLoading(loading) {
  spinner.hidden = !loading;
  if (loading) {
    results.innerHTML = '';
    hint.hidden = true;
  }
}

async function doSearch(q) {
  if (currentController) currentController.abort();
  currentController = new AbortController();

  setLoading(true);
  try {
    const res = await fetch(`api/search.php?q=${encodeURIComponent(q)}`, {
      signal: currentController.signal,
    });

    if (res.status === 401) {
      setLoading(false);
      results.innerHTML = '';
      hint.hidden = false;
      hint.textContent = 'The jukebox host is not connected to Spotify yet.';
      return;
    }

    const data = await res.json();
    setLoading(false);
    renderResults(data.tracks || []);
  } catch (err) {
    if (err.name === 'AbortError') return;
    setLoading(false);
    hint.hidden = false;
    hint.textContent = 'Something went wrong searching. Try again.';
  }
}

function renderResults(tracks) {
  if (tracks.length === 0) {
    results.innerHTML = '';
    hint.hidden = false;
    hint.textContent = 'No results. Try a different search.';
    return;
  }
  hint.hidden = true;
  results.innerHTML = tracks.map((t) => `
    <li class="result" data-uri="${escapeHtml(t.uri)}" data-name="${escapeHtml(t.name)}">
      <img src="${t.image || ''}" alt="" />
      <div class="meta">
        <div class="t">${escapeHtml(t.name)}</div>
        <div class="a">${escapeHtml(t.artists.join(', '))}</div>
      </div>
      <button type="button" class="add-btn">Add</button>
    </li>
  `).join('');
}

results.addEventListener('click', async (e) => {
  const btn = e.target.closest('.add-btn');
  if (!btn) return;
  const li = btn.closest('.result');
  const uri = li.dataset.uri;
  const name = li.dataset.name;

  btn.disabled = true;
  btn.textContent = 'Adding…';

  try {
    const res = await fetch('api/queue.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ uri }),
    });
    const data = await res.json().catch(() => ({}));

    if (res.ok) {
      toast(`Added “${name}” to the queue`, 'ok');
      btn.textContent = 'Added ✓';
      if (data.queue) renderQueue(data.queue);
    } else if (res.status === 401) {
      toast('Host is not connected to Spotify.', 'error');
      btn.disabled = false;
      btn.textContent = 'Add';
    } else {
      toast(data.message || 'Could not add song.', 'error');
      btn.disabled = false;
      btn.textContent = 'Add';
    }
  } catch (err) {
    toast('Network error. Try again.', 'error');
    btn.disabled = false;
    btn.textContent = 'Add';
  }
});

input.addEventListener('input', () => {
  const q = input.value.trim();
  clearTimeout(debounceTimer);
  if (!q) {
    results.innerHTML = '';
    spinner.hidden = true;
    hint.hidden = false;
    hint.textContent = 'Start typing to find a song.';
    return;
  }
  debounceTimer = setTimeout(() => doSearch(q), 350);
});

input.focus();

// ---------- Voting queue ----------

const voteList = el('vote-list');
const queueEmpty = el('queue-empty');

function renderQueue(queue) {
  if (!queue || queue.length === 0) {
    voteList.innerHTML = '';
    queueEmpty.hidden = false;
    return;
  }
  queueEmpty.hidden = true;
  voteList.innerHTML = queue.map((t, i) => `
    <li class="result" data-id="${escapeHtml(t.id)}">
      <div class="rank">${i + 1}</div>
      <img src="${t.image || ''}" alt="" />
      <div class="meta">
        <div class="t">${escapeHtml(t.name)}</div>
        <div class="a">${escapeHtml(t.artists.join(', '))}</div>
      </div>
      <button type="button" class="vote-btn ${t.votedByMe ? 'voted' : ''}" data-id="${escapeHtml(t.id)}">
        <span class="arrow">▲</span>
        <span class="count">${t.votes}</span>
      </button>
    </li>
  `).join('');
}

voteList.addEventListener('click', async (e) => {
  const btn = e.target.closest('.vote-btn');
  if (!btn) return;
  const trackId = btn.dataset.id;
  btn.disabled = true;
  try {
    const res = await fetch('api/vote.php', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ trackId }),
    });
    const data = await res.json().catch(() => ({}));
    if (res.ok && data.queue) {
      renderQueue(data.queue);
    } else if (res.status === 404) {
      toast('That song already started playing.', 'error');
      refreshQueue();
    } else if (res.status === 401) {
      toast('Host is not connected to Spotify.', 'error');
    } else {
      toast(data.message || 'Could not vote.', 'error');
    }
  } catch (err) {
    toast('Network error. Try again.', 'error');
  } finally {
    btn.disabled = false;
  }
});

async function refreshQueue() {
  try {
    const res = await fetch('api/queue.php');
    if (!res.ok) return;
    const data = await res.json();
    renderQueue(data.queue || []);
  } catch (_) { /* ignore */ }
}

refreshQueue();
setInterval(refreshQueue, 4000);

import { getNowPlaying, addToQueue, NoActiveDeviceError } from './spotify.js';

/**
 * App-managed request queue with voting.
 *
 * Why this exists: Spotify's Web API cannot reorder, remove from, or read a
 * mutable playback queue we control. So to support "most-voted plays next" we
 * keep our OWN queue here. A background feeder pushes the current top-voted
 * track into Spotify's playback queue as the playing track nears its end.
 *
 * Each entry:
 *   { id, track, votes, voters:Set<guestId>, addedAt, addedBy, pushed:boolean }
 * `id` is the Spotify track id. One entry per track (re-adding just bumps it).
 */

const entries = new Map(); // id -> entry

// Feeder state
let feederTimer = null;
let lastPushedTrackId = null; // Spotify track id we last handed off
let lastNowPlayingId = null;

/** Sorted view: highest votes first, then oldest addition first. */
function sortedEntries() {
  return [...entries.values()].sort((a, b) => {
    if (b.votes !== a.votes) return b.votes - a.votes;
    return a.addedAt - b.addedAt;
  });
}

/** Public queue shape for the API/UI. `guestId` marks which the caller voted for. */
export function getQueueFor(guestId) {
  return sortedEntries().map((e) => ({
    id: e.id,
    uri: e.track.uri,
    name: e.track.name,
    artists: e.track.artists,
    album: e.track.album,
    image: e.track.image,
    durationMs: e.track.durationMs,
    votes: e.votes,
    votedByMe: guestId ? e.voters.has(guestId) : false,
    addedAt: e.addedAt,
  }));
}

/**
 * Add a track to the request queue. The adder implicitly casts one vote.
 * Re-adding an existing track just registers the adder's vote.
 */
export function addTrack(track, guestId) {
  if (!track?.id || !track?.uri) {
    throw new Error('A valid track is required.');
  }

  let entry = entries.get(track.id);
  if (!entry) {
    entry = {
      id: track.id,
      track,
      votes: 0,
      voters: new Set(),
      addedAt: Date.now(),
      addedBy: guestId || null,
      pushed: false,
    };
    entries.set(track.id, entry);
  }

  // The act of adding counts as a vote from that guest.
  if (guestId && !entry.voters.has(guestId)) {
    entry.voters.add(guestId);
    entry.votes = entry.voters.size;
  }

  return { added: true, entry: publicEntry(entry, guestId) };
}

/** Toggle a guest's vote on a queued track. Returns the new state. */
export function toggleVote(trackId, guestId) {
  const entry = entries.get(trackId);
  if (!entry) {
    const err = new Error('That song is no longer in the queue.');
    err.code = 'NOT_FOUND';
    throw err;
  }
  if (!guestId) {
    const err = new Error('Missing guest identity.');
    err.code = 'NO_GUEST';
    throw err;
  }

  if (entry.voters.has(guestId)) {
    entry.voters.delete(guestId);
  } else {
    entry.voters.add(guestId);
  }
  entry.votes = entry.voters.size;

  // A song with zero votes stays in the queue (it was still requested), but if
  // it drops to zero AND nobody added it explicitly we keep it — removal only
  // happens when it's fed to Spotify. This keeps behavior predictable.
  return publicEntry(entry, guestId);
}

function publicEntry(e, guestId) {
  return {
    id: e.id,
    uri: e.track.uri,
    name: e.track.name,
    artists: e.track.artists,
    image: e.track.image,
    votes: e.votes,
    votedByMe: guestId ? e.voters.has(guestId) : false,
  };
}

/** Remove the top entry and return it (used by the feeder). */
function popTop() {
  const sorted = sortedEntries();
  const top = sorted[0];
  if (!top) return null;
  entries.delete(top.id);
  return top;
}

/**
 * Feeder loop.
 *
 * Polls Spotify's now-playing. When the current track is within `LEAD_MS` of
 * ending (or when nothing is playing but we have requests and an active device),
 * push the top-voted request into Spotify's queue so it plays next.
 *
 * We guard with `lastPushedTrackId` so we only hand off once per gap.
 */
const POLL_MS = 3000;
const LEAD_MS = 8000; // push the next song ~8s before the current ends

export function startFeeder() {
  if (feederTimer) return;
  feederTimer = setInterval(runFeederTick, POLL_MS);
  feederTimer.unref?.();
  console.log('[jukebox] Voting feeder started.');
}

async function runFeederTick() {
  if (entries.size === 0) return;

  let now;
  try {
    now = await getNowPlaying();
  } catch (err) {
    // Not authenticated yet, or transient error — try again next tick.
    return;
  }

  // Track changes reset the "already pushed" guard.
  const currentId = now?.track?.id || null;
  if (currentId && currentId !== lastNowPlayingId) {
    lastNowPlayingId = currentId;
    lastPushedTrackId = null;
  }

  const nothingPlaying = !now || !now.track || !now.isPlaying;

  let shouldPush = false;
  if (nothingPlaying) {
    // Nothing is playing but people have requested songs — try to start one.
    shouldPush = true;
  } else {
    const remaining = (now.track.durationMs || 0) - (now.progressMs || 0);
    if (remaining <= LEAD_MS && lastPushedTrackId !== currentId) {
      shouldPush = true;
    }
  }

  if (!shouldPush) return;

  const top = popTop();
  if (!top) return;

  try {
    await addToQueue(top.track.uri);
    lastPushedTrackId = currentId; // one hand-off per current track
    top.pushed = true;
    console.log(`[jukebox] Fed top-voted "${top.track.name}" (${top.votes} votes) into Spotify.`);
  } catch (err) {
    // If we couldn't push (e.g. no active device), put it back so it isn't lost.
    entries.set(top.id, top);
    if (err instanceof NoActiveDeviceError) {
      // Quietly wait for a device; nothing more to do this tick.
      return;
    }
    console.error('[jukebox] Failed to feed track:', err.message);
  }
}

/** For tests/diagnostics. */
export function _debugState() {
  return { size: entries.size, lastPushedTrackId, lastNowPlayingId };
}

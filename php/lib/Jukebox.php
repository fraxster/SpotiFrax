<?php
/**
 * Jukebox: the app-managed, vote-sorted request queue plus a request-time feeder.
 *
 * Why not just use Spotify's queue? Spotify's Web API cannot reorder its own
 * playback queue, so to make "most-voted plays next" work we keep our own queue
 * (persisted as queue.json) and hand songs to Spotify one at a time.
 *
 * The Node version runs a background loop for this. PHP on shared hosting has no
 * always-on process, so the feeder runs opportunistically on each poll request
 * (see maybeFeed()): whenever the display/guests fetch state, we check whether
 * the current track is near its end and, if so, push the top-voted song.
 *
 * queue.json shape:
 *   {
 *     "entries": { "<trackId>": { track, votes, voters:[gid...], addedAt, addedBy } },
 *     "lastPushedTrackId": string|null,
 *     "lastNowPlayingId": string|null
 *   }
 */

declare(strict_types=1);

final class Jukebox
{
    private const LEAD_MS = 8000;   // push next song ~8s before current ends
    private const FEED_THROTTLE = 2; // don't run the feeder more than every N seconds

    private Store $store;
    private Spotify $spotify;

    public function __construct(Store $store, Spotify $spotify)
    {
        $this->store = $store;
        $this->spotify = $spotify;
    }

    private function defaultState(): array
    {
        return ['entries' => [], 'lastPushedTrackId' => null, 'lastNowPlayingId' => null, 'lastFeedAt' => 0];
    }

    /** Vote-sorted queue for the API/UI. Marks which entries $guestId voted for. */
    public function getQueueFor(string $guestId): array
    {
        $state = $this->store->read('queue', $this->defaultState());
        $entries = array_values($state['entries'] ?? []);

        usort($entries, static function ($a, $b) {
            $av = count($a['voters'] ?? []);
            $bv = count($b['voters'] ?? []);
            if ($av !== $bv) {
                return $bv <=> $av;           // higher votes first
            }
            return ($a['addedAt'] ?? 0) <=> ($b['addedAt'] ?? 0); // then oldest first
        });

        return array_map(function ($e) use ($guestId) {
            $voters = $e['voters'] ?? [];
            $t = $e['track'];
            return [
                'id'         => $t['id'],
                'uri'        => $t['uri'],
                'name'       => $t['name'],
                'artists'    => $t['artists'],
                'album'      => $t['album'] ?? '',
                'image'      => $t['image'] ?? null,
                'durationMs' => $t['durationMs'] ?? 0,
                'votes'      => count($voters),
                'votedByMe'  => in_array($guestId, $voters, true),
                'addedAt'    => $e['addedAt'] ?? 0,
            ];
        }, $entries);
    }

    /** Add a track to the queue. Adding counts as the guest's first vote. */
    public function addTrack(array $track, string $guestId): void
    {
        if (empty($track['id']) || empty($track['uri'])) {
            throw new InvalidArgumentException('A valid track is required.');
        }
        $this->store->update('queue', function (array $state) use ($track, $guestId): array {
            $state = $state ?: $this->defaultState();
            $id = $track['id'];
            if (!isset($state['entries'][$id])) {
                $state['entries'][$id] = [
                    'track'   => $track,
                    'voters'  => [],
                    'addedAt' => (int) (microtime(true) * 1000),
                    'addedBy' => $guestId,
                ];
            }
            $voters = $state['entries'][$id]['voters'];
            if (!in_array($guestId, $voters, true)) {
                $voters[] = $guestId;
                $state['entries'][$id]['voters'] = $voters;
            }
            return $state;
        }, $this->defaultState());
    }

    /** Toggle a guest's vote on a queued track. Returns updated public queue. */
    public function toggleVote(string $trackId, string $guestId): void
    {
        $found = true;
        $this->store->update('queue', function (array $state) use ($trackId, $guestId, &$found): array {
            $state = $state ?: $this->defaultState();
            if (!isset($state['entries'][$trackId])) {
                $found = false;
                return $state;
            }
            $voters = $state['entries'][$trackId]['voters'] ?? [];
            $idx = array_search($guestId, $voters, true);
            if ($idx === false) {
                $voters[] = $guestId;
            } else {
                array_splice($voters, $idx, 1);
            }
            $state['entries'][$trackId]['voters'] = array_values($voters);
            return $state;
        }, $this->defaultState());

        if (!$found) {
            $e = new RuntimeException('That song is no longer in the queue.');
            throw $e;
        }
    }

    /** Moderator action: remove a track from the queue entirely, votes and all. */
    public function removeTrack(string $trackId): bool
    {
        $removed = false;
        $this->store->update('queue', function (array $state) use ($trackId, &$removed): array {
            $state = $state ?: $this->defaultState();
            if (isset($state['entries'][$trackId])) {
                unset($state['entries'][$trackId]);
                $removed = true;
            }
            return $state;
        }, $this->defaultState());
        return $removed;
    }

    /**
     * Request-time feeder. Call this on state polls. If the currently playing
     * track is within LEAD_MS of ending (or nothing is playing), push the top
     * voted song into Spotify and remove it from our queue.
     *
     * Safe to call often — it throttles itself and guards against double-pushing.
     * Silently no-ops when not authenticated or no device is active.
     */
    public function maybeFeed(): void
    {
        $state = $this->store->read('queue', $this->defaultState());
        if (empty($state['entries'])) {
            return;
        }
        // Throttle: don't hammer the Spotify API on every single poll.
        if (time() - (int) ($state['lastFeedAt'] ?? 0) < self::FEED_THROTTLE) {
            return;
        }
        if (!$this->spotify->isAuthenticated()) {
            return;
        }

        try {
            $now = $this->spotify->getNowPlaying();
        } catch (Throwable $e) {
            return; // transient; try next poll
        }

        $currentId = $now['track']['id'] ?? null;

        // Decide whether to push.
        $shouldPush = false;
        $nothingPlaying = empty($now['track']) || empty($now['isPlaying']);
        if ($nothingPlaying) {
            $shouldPush = true;
        } else {
            $remaining = ($now['track']['durationMs'] ?? 0) - ($now['progressMs'] ?? 0);
            if ($remaining <= self::LEAD_MS && ($state['lastPushedTrackId'] ?? null) !== $currentId) {
                $shouldPush = true;
            }
        }

        // Reset the push guard when the playing track changes.
        if ($currentId && $currentId !== ($state['lastNowPlayingId'] ?? null)) {
            $this->store->update('queue', function (array $s) use ($currentId): array {
                $s['lastNowPlayingId'] = $currentId;
                $s['lastPushedTrackId'] = null;
                return $s;
            }, $this->defaultState());
        }

        if (!$shouldPush) {
            return;
        }

        // Pop the top entry (highest votes) and hand it to Spotify.
        $top = $this->peekTop();
        if (!$top) {
            return;
        }

        try {
            $this->spotify->addToQueue($top['track']['uri']);
            // Success: remove from our queue and record the push guard.
            $this->store->update('queue', function (array $s) use ($top, $currentId): array {
                unset($s['entries'][$top['track']['id']]);
                $s['lastPushedTrackId'] = $currentId;
                $s['lastFeedAt'] = time();
                return $s;
            }, $this->defaultState());
            error_log('[SpotiFrax] Fed top-voted "' . $top['track']['name'] . '" into Spotify.');
        } catch (NoActiveDeviceException $e) {
            // No device yet — leave the song in the queue and try again later.
            $this->store->update('queue', function (array $s): array {
                $s['lastFeedAt'] = time();
                return $s;
            }, $this->defaultState());
        } catch (Throwable $e) {
            error_log('[SpotiFrax] Feeder failed: ' . $e->getMessage());
        }
    }

    /** The current highest-voted entry, without removing it. */
    private function peekTop(): ?array
    {
        $state = $this->store->read('queue', $this->defaultState());
        $entries = array_values($state['entries'] ?? []);
        if (!$entries) {
            return null;
        }
        usort($entries, static function ($a, $b) {
            $av = count($a['voters'] ?? []);
            $bv = count($b['voters'] ?? []);
            if ($av !== $bv) {
                return $bv <=> $av;
            }
            return ($a['addedAt'] ?? 0) <=> ($b['addedAt'] ?? 0);
        });
        return $entries[0];
    }
}

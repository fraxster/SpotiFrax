<?php
/**
 * Store: tiny JSON file storage with advisory locking.
 *
 * Shared hosting reliably has a writable filesystem but not always a database,
 * so we persist state (tokens, queue) as JSON files. All reads/writes take a
 * file lock so simultaneous guest requests don't corrupt the data.
 */

declare(strict_types=1);

final class Store
{
    private string $dir;

    public function __construct(string $dir)
    {
        $this->dir = rtrim($dir, '/');
        if (!is_dir($this->dir)) {
            // 0770: owner + group only. The web server must be able to write here.
            @mkdir($this->dir, 0770, true);
        }
    }

    private function path(string $name): string
    {
        // Guard the filename to a safe slug.
        $safe = preg_replace('/[^a-z0-9_\-]/i', '', $name);
        return $this->dir . '/' . $safe . '.json';
    }

    /** Read a JSON file, returning $default if missing or unreadable. */
    public function read(string $name, array $default = []): array
    {
        $file = $this->path($name);
        if (!is_file($file)) {
            return $default;
        }
        $fh = @fopen($file, 'rb');
        if ($fh === false) {
            return $default;
        }
        try {
            flock($fh, LOCK_SH);
            $raw = stream_get_contents($fh);
            flock($fh, LOCK_UN);
        } finally {
            fclose($fh);
        }
        if ($raw === false || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    /** Overwrite a JSON file atomically. */
    public function write(string $name, array $data): void
    {
        $file = $this->path($name);
        $tmp = $file . '.tmp.' . getmypid();
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            throw new RuntimeException('Failed to encode JSON for ' . $name);
        }
        // Write to a temp file then rename — rename is atomic on the same volume.
        if (file_put_contents($tmp, $json, LOCK_EX) === false) {
            throw new RuntimeException('Failed to write ' . $tmp . ' (is DATA_DIR writable?)');
        }
        @chmod($tmp, 0660);
        if (!rename($tmp, $file)) {
            @unlink($tmp);
            throw new RuntimeException('Failed to move temp file into place for ' . $name);
        }
    }

    /**
     * Read-modify-write under an exclusive lock. The callback receives the
     * current data and must return the new data. Prevents lost updates when
     * many guests vote at once.
     */
    public function update(string $name, callable $mutator, array $default = []): array
    {
        $lockFile = $this->path($name) . '.lock';
        $lh = fopen($lockFile, 'c');
        if ($lh === false) {
            throw new RuntimeException('Could not open lock for ' . $name);
        }
        try {
            flock($lh, LOCK_EX);
            $current = $this->read($name, $default);
            $next = $mutator($current);
            if (!is_array($next)) {
                $next = $current;
            }
            $this->write($name, $next);
            return $next;
        } finally {
            flock($lh, LOCK_UN);
            fclose($lh);
        }
    }

    public function delete(string $name): void
    {
        $file = $this->path($name);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

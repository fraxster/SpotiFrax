<?php
/**
 * Settings: display customization managed from the admin page and persisted to
 * settings.json. Read publicly by the display; written only by an admin.
 *
 * Fields:
 *   logoUrl     string  URL/path to a custom logo image (empty = show text brand)
 *   albumScale  float   size multiplier for the now-playing album art (0.6–1.6)
 *   qrScale     float   size multiplier for the QR code (0.6–1.8)
 *   queueScale  float   font-size multiplier for the up-next queue (0.7–1.6)
 *   title       string  optional heading shown on the display
 */

declare(strict_types=1);

require_once __DIR__ . '/Store.php';

final class Settings
{
    private Store $store;

    public function __construct(Store $store)
    {
        $this->store = $store;
    }

    public static function defaults(): array
    {
        return [
            'logoUrl'    => '',
            'albumScale' => 1.0,
            'qrScale'    => 1.0,
            'queueScale' => 1.0,
            'title'      => '',
        ];
    }

    /** Current settings, merged over defaults so missing keys are always present. */
    public function get(): array
    {
        $saved = $this->store->read('settings', []);
        return array_merge(self::defaults(), is_array($saved) ? $saved : []);
    }

    /**
     * Save a partial or full update. Values are validated/clamped so the display
     * can't be broken by out-of-range input. Unknown keys are ignored.
     */
    public function save(array $input): array
    {
        $current = $this->get();

        if (array_key_exists('logoUrl', $input)) {
            $current['logoUrl'] = $this->sanitizeLogoUrl((string) $input['logoUrl']);
        }
        if (array_key_exists('title', $input)) {
            $current['title'] = mb_substr(trim((string) $input['title']), 0, 60);
        }
        foreach (['albumScale' => [0.6, 1.6], 'qrScale' => [0.6, 1.8], 'queueScale' => [0.7, 1.6]] as $key => [$min, $max]) {
            if (array_key_exists($key, $input)) {
                $current[$key] = $this->clampFloat($input[$key], $min, $max, 1.0);
            }
        }

        $this->store->write('settings', $current);
        return $current;
    }

    private function clampFloat($v, float $min, float $max, float $fallback): float
    {
        if (!is_numeric($v)) {
            return $fallback;
        }
        $f = (float) $v;
        if ($f < $min) {
            return $min;
        }
        if ($f > $max) {
            return $max;
        }
        // Round to 2 decimals to keep the JSON tidy.
        return round($f, 2);
    }

    /** Only allow http(s) URLs or a same-site relative path (e.g. an uploaded file). */
    private function sanitizeLogoUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        // Relative path served by us (uploaded logo lives under assets/uploads/).
        if (strpos($url, '/') === 0 || strpos($url, 'assets/') === 0) {
            return $url;
        }
        if (filter_var($url, FILTER_VALIDATE_URL) && preg_match('#^https?://#i', $url)) {
            return $url;
        }
        // Reject anything else (javascript:, data:, etc.).
        return '';
    }
}

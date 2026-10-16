<?php
declare(strict_types=1);

/** Comparison keys only: never rewrite an entry's original link or GUID. */
final class DeduplicateUrl {
    private const TRACKERS = [
        'utm_source', 'utm_medium', 'utm_campaign', 'utm_term', 'utm_content', 'utm_id',
        'gclid', 'fbclid', 'msclkid',
    ];

    /** @return array<string,mixed>|null */
    private static function webParts(string $url): ?array {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])
            || !in_array(strtolower($parts['scheme']), ['http', 'https'], true)
            || isset($parts['user']) || isset($parts['pass'])
            || preg_match('/[\x00-\x20\x7f]/', $url)) {
            return null;
        }
        return $parts;
    }

    public static function canonical(string $url): string {
        // FreshRSS stores HTML-encoded links. Decode once, not recursively.
        $decoded = html_entity_decode($url, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $parts = self::webParts($decoded);
        if ($parts === null) {
            return $url;
        }
        $host = strtolower($parts['host']);
        // parse_url() converts :0443 to integer 443 and drops an empty colon.
        // Keep raw authority spelling so canonical equality cannot exceed the
        // candidate query's raw-prefix coverage for these ambiguous forms.
        if (!preg_match('~^https?://([^/?#]+)~i', $decoded, $authority)) {
            return $url;
        }
        $key = strtolower($parts['scheme']) . '://' . strtolower($authority[1]);
        $key .= $parts['path'] ?? '';
        if (isset($parts['query'])) {
            // Do not parse_str(): repeated keys, ordering, encoding and values matter.
            $kept = [];
            $removed = false;
            foreach (explode('&', $parts['query']) as $parameter) {
                $name = rawurldecode(explode('=', $parameter, 2)[0]);
                if (in_array($name, self::TRACKERS, true)) {
                    $removed = true;
                } else {
                    $kept[] = $parameter;
                }
            }
            if (!$removed || $kept !== []) {
                $key .= '?' . implode('&', $kept);
            }
        }
        if (isset($parts['fragment']) && !(in_array($host,
                ['www.ndtv.com', 'www.ndtvprofit.com', 'sports.ndtv.com'], true)
                && $parts['fragment'] === 'publisher=newsstand')) {
            $key .= '#' . $parts['fragment'];
        }
        return $key;
    }
}

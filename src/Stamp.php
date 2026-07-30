<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * Provenance stamping for `carve fmt --stamp`.
 *
 * The marker records the spec version a document was last processed under, which
 * is what makes the upgrade procedure in the spec's versioning page actionable:
 * you only need to review `[behavior]` changelog entries between a document's
 * stamped version and the version you are moving to. Writing the marker was not
 * enough for that - something has to read it back, which is what read() and
 * needsReview() are for.
 */
final class Stamp
{
    /**
     * Build the marker text with no surrounding blank lines or trailing newline.
     */
    public static function buildMarker(string $generatedBy, string $form): string
    {
        if ($form === 'block') {
            return "%%%\n"
                . 'carve-version: ' . CarveConverter::SPEC_VERSION . "\n"
                . 'generated-by: ' . $generatedBy . "\n"
                . '%%%';
        }

        return '%% carve-version: ' . CarveConverter::SPEC_VERSION . '; generated-by: ' . $generatedBy;
    }

    /**
     * Remove a trailing provenance marker, returning the body with a single
     * trailing newline or an empty string when the body is empty.
     */
    public static function stripTrailingMarker(string $formatted): string
    {
        $lines = explode("\n", (string)preg_replace('/\n+$/', '', $formatted));

        $last = $lines[count($lines) - 1] ?? '';
        if (preg_match('/^%%[ \t]*carve-version:/', $last) === 1) {
            array_pop($lines);
        } elseif (preg_match('/^%{3,}[ \t]*$/', $last) === 1) {
            $fence = trim($last);
            for ($i = count($lines) - 2; $i >= 0; $i--) {
                if (trim($lines[$i]) !== $fence) {
                    continue;
                }
                if (preg_match('/^carve-version:/', trim($lines[$i + 1] ?? '')) === 1) {
                    array_splice($lines, $i);
                }

                break;
            }
        }

        $lineCount = count($lines);
        while ($lineCount > 0 && trim($lines[$lineCount - 1]) === '') {
            array_pop($lines);
            $lineCount--;
        }

        return $lines !== [] ? implode("\n", $lines) . "\n" : '';
    }

    /**
     * Read a document's provenance marker.
     *
     * Recognizes both documented forms - the trailing `%%` line and the `%%%`
     * block - and is identified by `carve-version:` as the first field, so an
     * unrelated trailing comment is not mistaken for a marker.
     *
     * @return array{version: string, generatedBy: string|null}|null Null when the
     *     document carries no marker, which is the normal case for hand-written
     *     documents and means "unknown, treat as the oldest version you support".
     */
    public static function read(string $source): ?array
    {
        $lines = explode("\n", (string)preg_replace('/\n+$/', '', $source));
        $last = trim($lines[count($lines) - 1] ?? '');

        if (preg_match('/^%%[ \t]*carve-version:[ \t]*([^;\s]+)(?:[ \t]*;[ \t]*generated-by:[ \t]*(.+))?$/', $last, $matches) === 1) {
            return [
                'version' => $matches[1],
                'generatedBy' => isset($matches[2]) && trim($matches[2]) !== '' ? trim($matches[2]) : null,
            ];
        }

        // Block form: the closing fence is last, the fields sit above it.
        if (preg_match('/^%{3,}$/', $last) !== 1) {
            return null;
        }

        $version = null;
        $generatedBy = null;
        for ($i = count($lines) - 2; $i >= 0; $i--) {
            $line = trim($lines[$i]);
            if (preg_match('/^%{3,}$/', $line) === 1) {
                break;
            }
            if (preg_match('/^carve-version:[ \t]*(.+)$/', $line, $matches) === 1) {
                $version = trim($matches[1]);
            } elseif (preg_match('/^generated-by:[ \t]*(.+)$/', $line, $matches) === 1) {
                $generatedBy = trim($matches[1]);
            }
        }

        return $version === null ? null : ['version' => $version, 'generatedBy' => $generatedBy];
    }

    /**
     * Whether a document was last processed under an older spec version than
     * this implementation targets, so its `[behavior]` changelog entries are
     * worth reviewing.
     *
     * An unstamped document answers true: its provenance is unknown, and
     * assuming it is current is the unsafe direction.
     */
    public static function needsReview(string $source, ?string $currentVersion = null): bool
    {
        $current = $currentVersion ?? CarveConverter::SPEC_VERSION;
        $stamp = self::read($source);

        if ($stamp === null) {
            return true;
        }

        return version_compare($stamp['version'], $current, '<');
    }

    /**
     * Append or replace the provenance marker on already-formatted Carve.
     */
    public static function stampCarve(string $formatted, string $generatedBy, string $form = 'line'): string
    {
        $body = self::stripTrailingMarker($formatted);
        $marker = self::buildMarker($generatedBy, $form);
        if ($body === '') {
            return $marker . "\n";
        }

        return (string)preg_replace('/\n$/', '', $body) . "\n\n" . $marker . "\n";
    }
}

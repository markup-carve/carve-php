<?php

declare(strict_types=1);

namespace MarkupCarve\Carve;

/**
 * Provenance stamping for `carve fmt --stamp`.
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

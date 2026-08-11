<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Migration;

/**
 * Inserts explicit paragraph boundaries that Carve 0.1 inferred from openers.
 */
final class Carve01To02Migrator
{
    public static function migrate(string $source): string
    {
        $eol = str_contains($source, "\r\n") ? "\r\n" : "\n";
        $hadFinalEol = str_ends_with($source, "\n");
        $lines = explode("\n", str_replace("\r\n", "\n", $source));
        if ($hadFinalEol) {
            array_pop($lines);
        }
        $frontmatterEnd = null;
        if (preg_match('/^---(?: ?[A-Za-z0-9]+)?[ \t]*$/', $lines[0] ?? '') === 1) {
            foreach (array_slice($lines, 1, null, true) as $lineIndex => $candidate) {
                if (preg_match('/^---[ \t]*$/', $candidate) === 1) {
                    $frontmatterEnd = $lineIndex;

                    break;
                }
            }
        }

        $out = [];
        $opaque = null;
        $paragraphOpen = false;
        $attachmentMarker = null;
        $attachmentColumn = null;
        $activeDedent = null;
        $colonWidths = [];
        $absorbingColon = false;
        foreach ($lines as $index => $raw) {
            if ($frontmatterEnd !== null && $index <= $frontmatterEnd) {
                $out[] = $raw;

                continue;
            }
            if ($activeDedent !== null && preg_match('/^ {' . $activeDedent . '}/', $raw) === 1) {
                $raw = substr($raw, $activeDedent);
            }
            preg_match('/^((?:[ \t]*> ?)+)(.*)$/', $raw, $quoted);
            $prefix = $quoted[1] ?? '';
            $line = $quoted[2] ?? ltrim($raw, " \t");

            if ($opaque !== null) {
                $out[] = $raw;
                if (preg_match('/^' . preg_quote($opaque['char'], '/') . '{' . $opaque['width'] . ',}[ \t]*$/', $line) === 1) {
                    $opaque = null;
                    $activeDedent = null;
                    $attachmentMarker = null;
                    $attachmentColumn = null;
                }

                continue;
            }

            if (trim($line) === '') {
                $out[] = $raw;
                $paragraphOpen = false;
                if ($activeDedent === null) {
                    $attachmentMarker = null;
                }
                $attachmentColumn = null;
                $activeDedent = null;
                $absorbingColon = false;

                continue;
            }

            $fence = preg_match('/^(`{3,}|~{3,})/', $line, $fenceMatch) === 1 ? $fenceMatch[1] : null;
            $colon = preg_match('/^(:{3,})(?: +(.*))?[ \t]*$/', $line, $colonMatch) === 1 ? $colonMatch : null;
            $colonCloser = $colon !== null && ($colon[2] ?? '') === '' && end($colonWidths) === strlen($colon[1]);
            $absorbedBareColon = $paragraphOpen
                && $absorbingColon
                && preg_match('/^:{3,}[ \t]*$/', $line) === 1;
            if ($absorbedBareColon) {
                $colon = null;
                $colonCloser = false;
            }
            $fenceCloses = false;
            if ($fence !== null) {
                foreach (array_slice($lines, $index + 1) as $candidate) {
                    $body = preg_replace('/^(?:[ \t]*> ?)+/', '', $candidate);
                    $body = ltrim((string)$body, " \t");
                    if (preg_match('/^' . preg_quote($fence[0], '/') . '{' . strlen($fence) . ',}[ \t]*$/', $body) === 1) {
                        $fenceCloses = true;

                        break;
                    }
                }
            }
            $oldInterrupter = !$absorbedBareColon && !$colonCloser && ($prefix !== '' || $fenceCloses || preg_match(
                '/^(?:#{1,6} |>(?: |$)|(?:---|\*\*\*|___)[ \t]*$|\|.*\|[ \t]*$|:{2,}(?: |$)|\[[^\]]+\]: +|\[\^[^\]]+\]: +|\*\[[A-Z][^\]]*\]: +|%%|\{[^{}]+\}[ \t]*$)/',
                $line,
            ) === 1);
            $previous = (string)end($out);
            $previousBody = trim((string)preg_replace('/^(?:[ \t]*> ?)+/', '', $previous));
            $previousIsContinuation = $previousBody === '+' || preg_match('/^(?::  |(?:[-*]|[0-9]+[.)]) +)\+$/', $previousBody) === 1;
            $currentIndent = strlen($raw) - strlen(ltrim($raw, " \t"));
            $reachesContainerColumn = $attachmentColumn === null || $currentIndent <= $attachmentColumn;
            if ($oldInterrupter && $reachesContainerColumn && $paragraphOpen && !$previousIsContinuation && $out !== [] && trim($previous, " \t>") !== '') {
                $out[] = $attachmentMarker ?? ($prefix === '' ? '' : rtrim($prefix));
                if ($prefix === '' && $attachmentMarker !== null && $attachmentColumn !== null) {
                    $activeDedent = $attachmentColumn;
                    if (preg_match('/^ {' . $activeDedent . '}/', $raw) === 1) {
                        $raw = substr($raw, $activeDedent);
                        $line = ltrim($raw, " \t");
                    }
                }
            }
            $out[] = $raw;

            if ($colonCloser) {
                array_pop($colonWidths);
                $paragraphOpen = false;
                $activeDedent = null;
                $attachmentMarker = null;
                $attachmentColumn = null;
            } elseif ($colon !== null) {
                $colonWidths[] = strlen($colon[1]);
                if ($activeDedent !== null) {
                    $opaque = ['char' => ':', 'width' => strlen($colon[1])];
                }
                $paragraphOpen = false;
                if ($activeDedent === null) {
                    $attachmentMarker = null;
                }
            } elseif ($fence !== null && $fenceCloses) {
                $opaque = ['char' => $fence[0], 'width' => strlen($fence)];
                $paragraphOpen = false;
                if ($activeDedent === null) {
                    $attachmentMarker = null;
                }
            } elseif (preg_match('/^(%{3,})/', $line, $comment) === 1) {
                $opaque = ['char' => '%', 'width' => strlen($comment[1])];
                $paragraphOpen = false;
                $attachmentMarker = null;
            } elseif ($oldInterrupter) {
                $paragraphOpen = $prefix !== '' && !self::isBlockOpener($line);
                $attachmentMarker = null;
                $absorbingColon = false;
            } else {
                if (!$paragraphOpen && $activeDedent === null) {
                    $attachmentMarker = preg_match('/^(\s*)(?:(?:[-*]|[0-9]+[.)]) +|:  |\[\^[^\]]+\]: +)\S/', $raw, $attachable) === 1
                        ? $attachable[1] . '+'
                        : null;
                    // The match includes the first content byte; the preceding
                    // width is the item's content column.
                    $attachmentColumn = isset($attachable[0]) ? strlen($attachable[0]) - 1 : null;
                }
                $paragraphOpen = trim($line) !== '+';
                if ($paragraphOpen && preg_match('/^:{3,}\S/', $line) === 1) {
                    $absorbingColon = true;
                }
                if (!$paragraphOpen) {
                    $attachmentMarker = null;
                }
            }
        }

        return implode($eol, $out) . ($hadFinalEol ? $eol : '');
    }

    private static function isBlockOpener(string $line): bool
    {
        return preg_match(
            '/^(?:#{1,6} |(?:[-*]|[0-9]+[.)]) +|(?:---|\*\*\*|___)[ \t]*$|\|.*\|[ \t]*$|:{2,}(?: |$)|\[[^\]]+\]: +|\[\^[^\]]+\]: +|\*\[[A-Z][^\]]*\]: +|%%|\{[^{}]+\}[ \t]*$)/',
            $line,
        ) === 1;
    }
}

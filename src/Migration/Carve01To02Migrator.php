<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Migration;

/** Insert explicit paragraph boundaries that Carve 0.1 inferred from openers. */
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

        $out = [];
        $opaque = null;
        $paragraphOpen = false;
        $attachmentMarker = null;
        $colonWidths = [];
        foreach ($lines as $index => $raw) {
            preg_match('/^((?:[ \t]*> ?)+)(.*)$/', $raw, $quoted);
            $prefix = $quoted[1] ?? '';
            $line = $quoted[2] ?? ltrim($raw, " \t");

            if ($opaque !== null) {
                $out[] = $raw;
                if (preg_match('/^' . preg_quote($opaque['char'], '/') . '{' . $opaque['width'] . ',}[ \t]*$/', $line) === 1) {
                    $opaque = null;
                }

                continue;
            }

            if (trim($line) === '') {
                $out[] = $raw;
                $paragraphOpen = false;
                $attachmentMarker = null;
                continue;
            }

            $fence = preg_match('/^(`{3,}|~{3,})/', $line, $fenceMatch) === 1 ? $fenceMatch[1] : null;
            $colon = preg_match('/^(:{3,})(?: +(.*))?[ \t]*$/', $line, $colonMatch) === 1 ? $colonMatch : null;
            $colonCloser = $colon !== null && !isset($colon[2]) && end($colonWidths) === strlen($colon[1]);
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
            $oldInterrupter = !$colonCloser && ($fenceCloses || preg_match(
                '/^(?:#{1,6} |>(?: |$)|(?:---|\*\*\*|___)[ \t]*$|\|.*\|[ \t]*$|:{2,}(?: |$)|\[[^\]]+\]: +|\[\^[^\]]+\]: +|\*\[[A-Z][^\]]*\]: +|%%|\{[^{}]+\}[ \t]*$)/',
                $line,
            ) === 1);
            $previous = (string)end($out);
            $previousBody = trim((string)preg_replace('/^(?:[ \t]*> ?)+/', '', $previous));
            $previousIsContinuation = $previousBody === '+' || preg_match('/^(?::  |(?:[-*]|[0-9]+[.)]) +)\+$/', $previousBody) === 1;
            if ($oldInterrupter && $paragraphOpen && !$previousIsContinuation && $out !== [] && trim($previous, " \t>") !== '') {
                $out[] = $prefix === '' ? ($attachmentMarker ?? '') : rtrim($prefix);
            }
            $out[] = $raw;

            if ($colonCloser) {
                array_pop($colonWidths);
                $paragraphOpen = false;
                $attachmentMarker = null;
            } elseif ($colon !== null) {
                $colonWidths[] = strlen($colon[1]);
                $paragraphOpen = false;
                $attachmentMarker = null;
            } elseif ($fence !== null && $fenceCloses) {
                $opaque = ['char' => $fence[0], 'width' => strlen($fence)];
                $paragraphOpen = false;
                $attachmentMarker = null;
            } elseif (preg_match('/^(%{3,})/', $line, $comment) === 1) {
                $opaque = ['char' => '%', 'width' => strlen($comment[1])];
                $paragraphOpen = false;
                $attachmentMarker = null;
            } elseif ($oldInterrupter) {
                $paragraphOpen = preg_match('/^> +\S/', $line) === 1;
                $attachmentMarker = $paragraphOpen ? '+' : null;
            } else {
                if (!$paragraphOpen) {
                    $attachmentMarker = preg_match('/^(\s*)(?:(?:[-*]|[0-9]+[.)]) +|:  |\[\^[^\]]+\]: +)\S/', $raw, $attachable) === 1
                        ? $attachable[1] . '+'
                        : null;
                }
                $paragraphOpen = trim($line) !== '+';
                if (!$paragraphOpen) {
                    $attachmentMarker = null;
                }
            }
        }

        return implode($eol, $out) . ($hadFinalEol ? $eol : '');
    }
}

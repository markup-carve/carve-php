<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;

trait ReportsDjotLosses
{
    /**
     * Assess the original input so paths survive folding and inserted lines.
     *
     * @return list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    private function djotLosses(string $source): array
    {
        $source = str_replace(["\r\n", "\r"], "\n", $source);
        $mask = $this->djotEmphasisMask($source);
        $losses = [];
        $add = static function (int $at, string $message) use ($source, &$losses): void {
            $line = substr_count(substr($source, 0, $at), "\n") + 1;
            $key = $line . ':' . $message;
            $losses[$key] = new MigrationDiagnostic('structure-unspellable', $message, 'warning', 'dropped', 'exact', 'line:' . $line);
        };
        DjotEmphasis::convert($source, $mask, static fn (string $text): string => $text, [], static function (int $at) use ($add): void {
            $add($at, 'Nested emphasis of the same kind is flattened; its text is preserved.');
        });
        $lines = explode("\n", $source);
        $lineCount = count($lines);
        $linkMask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['destinations' => true]);
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($linkMask[$at] !== '{' || $this->isDjotEscaped($source, $at)) {
                continue;
            }
            $attrs = $this->readDjotWordAttributes($source, $at);
            if ($attrs === null) {
                continue;
            }
            for ($k = $at; $k < $attrs['end']; $k++) {
                if ($linkMask[$k] !== "\n") {
                    $linkMask[$k] = ' ';
                }
            }
            $at = $attrs['end'] - 1;
        }
        $visible = explode("\n", $linkMask);
        $definitions = [];
        $definitionLines = $headingLines = [];
        $tableRows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $plain = new CarveConverter(smartTypography: false, renderer: new PlainTextRenderer());
        $offsets = [];
        $offset = 0;
        foreach ($lines as $n => $line) {
            $offsets[$n] = $offset;
            $offset += strlen($line) + 1;
        }
        foreach ($lines as $n => $line) {
            $at = $this->djotContentStart($line);
            $content = substr($line, $at);
            $contentMask = substr($visible[$n], $at);
            $previous = $lines[$n - 1] ?? '';
            $previousAt = $this->djotContentStart($previous);
            $previousContent = trim(substr($previous, $previousAt));
            $item = preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', substr($line, 0, $at)) === 1;
            $boundary = $previousContent === '' || ($tableRows[$n - 1] ?? false) || $item || isset($definitionLines[$n - 1])
                || preg_match('/^(?:#{1,6}(?: |$)|:{3,}|[`~]{3,}|\{|\[[^\]]+\]:)/', $previousContent) === 1
                || preg_match('/^(?:[*-][ \t]*){3,}$/', $previousContent) === 1
                || ($previousAt > $at && preg_match('/(?:>|[-*+] |[0-9A-Za-z]+[.)] |\([0-9A-Za-z]+\) )/', substr($previous, 0, $previousAt)) === 1);
            if (preg_match('/^\[([^\[\]\n^]+)\]:[ \t]*(\S*)[ \t]*$/', $content, $definition) === 1 && str_starts_with($contentMask, '[') && $boundary) {
                $destination = $definition[2];
                for ($next = $n + 1; $next < $lineCount && $this->djotContentStart($lines[$next]) > $at && preg_match('/^\S+$/', substr($lines[$next], $this->djotContentStart($lines[$next]))) === 1; $next++) {
                    $destination .= substr($lines[$next], $this->djotContentStart($lines[$next]));
                    $definitionLines[$next] = true;
                }
                $definitions[$this->djotReferenceKey($definition[1])] = $destination;
                $definitionLines[$n] = true;
                $lineEnd = $offsets[$n] + strlen($line);
                for ($k = $offsets[$n]; $k < $lineEnd; $k++) {
                    $linkMask[$k] = ' ';
                }
            }
            if (preg_match('/^(#{1,6})(?:[ \t]+|$)/', $contentMask, $heading) === 1 && $boundary && !isset($headingLines[$n])) {
                $text = substr($content, strlen($heading[0]));
                $marker = '/^' . preg_quote($heading[1], '/') . '[ \t]+/';
                for ($next = $n + 1; $next < $lineCount; $next++) {
                    $following = $lines[$next];
                    $nextAt = $this->djotContentStart($following);
                    $prefix = substr($following, 0, $nextAt);
                    if (substr_count($prefix, '>') !== substr_count(substr($line, 0, $at), '>') || preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', $prefix) === 1) {
                        break;
                    }
                    $part = substr($following, $nextAt);
                    if (trim($part) === '' || preg_match('/(?:^|[^\\\\])(?:\\\\\\\\)*\\\\$/', $text) === 1) {
                        break;
                    }
                    if (preg_match($marker, $part) === 1) {
                        $text .= "\n" . preg_replace($marker, '', $part);
                    } else {
                        if (preg_match('/^(?:[#>|{]|[-*+][ \t]|[0-9A-Za-z]+[.)][ \t]|:[ \t]|:{2,}|\([0-9a-zA-Z]+\)[ \t]|[`~]{3,}|\[[^\]\n]*\]:|(?:[*-][ \t]*){3,}$)/', $part) === 1) {
                            break;
                        }
                        $text .= "\n" . $part;
                    }
                    $headingLines[$next] = true;
                }
                $key = $this->djotReferenceKey(rtrim($plain->convert($this->convert($text)), "\n"));
                $definitions[$key] ??= '#' . $key;
            }
            if (preg_match('/^#{1,6}[ \t]*$/', $contentMask) === 1 && $boundary && !isset($headingLines[$n])) {
                $next = trim($lines[$n + 1] ?? '');
                if ($next === '' || preg_match('/^(?:[#>|{]|[-*+] |:{3,}|`{3,}|~{3,})/', $next) === 1) {
                    $add($offsets[$n], 'An empty heading has no Carve spelling.');
                }
            }
            if (preg_match('/:[ \t]+$/', substr($visible[$n], 0, $at), $term, PREG_OFFSET_CAPTURE) === 1 && ($term[0][1] === 0 || str_contains(" \t", $visible[$n][$term[0][1] - 1])) && preg_match('/^\S/', $contentMask) === 1 && ($previousContent === '' || $n === 0 || $item)) {
                $quoteDepth = substr_count(substr($line, 0, $at), '>');
                $inContainer = fn (string $next): bool => substr_count(substr($next, 0, $this->djotContentStart($next)), '>') === $quoteDepth;
                $blank = fn (string $next): bool => trim(substr($next, $this->djotContentStart($next))) === '';
                $withoutQuotes = function (string $next): string {
                    $lastQuote = strrpos(substr($next, 0, $this->djotContentStart($next)), '>');

                    return $lastQuote === false ? $next : (preg_replace('/^[ \t]/', '', substr($next, $lastQuote + 1)) ?? $next);
                };
                $minimum = strlen($withoutQuotes(substr($line, 0, $term[0][1]))) + 2;
                $continuesTerm = static function (string $next) use ($withoutQuotes, $minimum): bool {
                    $content = $withoutQuotes($next);

                    return preg_match('/^[ \t]*(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\)|:)[ \t]+/', $content) !== 1 || strspn($content, " \t") > $minimum - 2;
                };
                $end = $n + 1;
                while ($end < $lineCount && $inContainer($lines[$end]) && !$blank($lines[$end]) && $continuesTerm($lines[$end])) {
                    $end++;
                }
                while ($end < $lineCount && $blank($lines[$end])) {
                    $end++;
                }
                if ($end === $lineCount || !$inContainer($lines[$end]) || strspn($withoutQuotes($lines[$end]), " \t") < $minimum) {
                    $add($offsets[$n], 'An empty definition description has no Carve spelling.');
                }
            }
        }
        $rows = [];
        $offset = 0;
        foreach ($lines as $n => $line) {
            $view = trim($visible[$n]);
            if ($tableRows[$n]) {
                $view = trim(substr($line, $this->djotContentStart($line)));
                $separator = preg_match('/^\|(?:[ \t]*:?-+:?[ \t]*\|)+[ \t]*$/', $view) === 1;
                if ($separator && count($rows) >= 2) {
                    $add($offset, 'A separator inside a table promotes the preceding row to a header; Carve cannot spell that structure.');
                }
                $rows[] = ['at' => $offset, 'separator' => $separator, 'blank' => trim($view, "| \t") === ''];
            } else {
                $this->reportDjotTableLosses($rows, $add);
                $rows = [];
            }
            $offset += strlen($line) + 1;
        }
        $this->reportDjotTableLosses($rows, $add);
        $mask = $linkMask;
        preg_match_all('/(?<!!)\[([^\[\]]*)\](?:\[([^\[\]]*)\]|\(([^()\n]*)\))/', $source, $links, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);
        foreach ($links as $link) {
            [$text, $at] = $link[0];
            if (($mask[$at] ?? '') !== '[' || $this->isDjotEscaped($source, $at) || str_starts_with($link[1][0], '^')) {
                continue;
            }
            if (isset($link[3]) && $link[3][1] >= 0) {
                continue;
            }
            $reference = $link[2][0] ?? '';
            $label = $reference === '' ? $link[1][0] : $reference;
            if ($reference === '') {
                $label = rtrim($plain->convert($this->convert($label)), "\n");
            }
            $label = $this->djotReferenceKey($label);
            if (!array_key_exists($label, $definitions)) {
                $add($at, 'An unresolved Djot reference renders a link without href; Carve has no spelling for it.');
            } elseif ($definitions[$label] === '') {
                $add($at, 'A link with an empty destination has no Carve spelling.');
            }
        }
        $starts = $images = $innerLinks = [];
        for ($at = 0, $length = strlen($source); $at < $length; $at++) {
            if ($mask[$at] === '\\') {
                $at++;

                continue;
            }
            if ($mask[$at] === '[') {
                $starts[] = $at;
                $images[] = $at > 0 && $source[$at - 1] === '!';
                $innerLinks[] = false;
            } elseif ($mask[$at] === ']' && $starts !== []) {
                $start = array_pop($starts);
                $image = array_pop($images);
                $innerLink = array_pop($innerLinks);
                $isLink = !$image && preg_match('/\G\([^()\n]+\)/', $source, offset: $at + 1) === 1;
                if ($isLink && $innerLink) {
                    $add($start, 'A link inside a link has no Carve spelling.');
                }
                if ($starts !== [] && $isLink) {
                    $innerLinks[count($starts) - 1] = true;
                }
            }
        }

        $losses = array_values($losses);
        usort($losses, static fn (MigrationDiagnostic $left, MigrationDiagnostic $right): int => (int)substr($left->path ?? '', 5) <=> (int)substr($right->path ?? '', 5));

        return $losses;
    }

    private function djotReferenceKey(string $label): string
    {
        $label = preg_replace('/\\\\([ \t!-\/:-@\[-`{-~])/', '$1', $label) ?? $label;

        return trim(preg_replace('/\s+/', ' ', $label) ?? $label);
    }

    /**
     * @param list<array{at: int, separator: bool, blank: bool}> $rows
     * @param callable(int, string): void $add
     */
    private function reportDjotTableLosses(array $rows, callable $add): void
    {
        if ($rows === []) {
            return;
        }
        if (count(array_filter($rows, static fn (array $row): bool => !$row['separator'])) === 0) {
            $add($rows[0]['at'], 'A table containing only separator rows has no Carve spelling.');
        } elseif (count($rows) === 1 && $rows[0]['blank']) {
            $add($rows[0]['at'], 'A table whose only row has blank cells has no Carve spelling.');
        }
    }
}

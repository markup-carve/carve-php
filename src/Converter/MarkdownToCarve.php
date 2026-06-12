<?php

declare(strict_types=1);

namespace Carve\Converter;

use RuntimeException;

/**
 * Converts Markdown syntax to Carve syntax.
 *
 * This performs a source-to-source transformation, not parsing. It rewrites
 * common Markdown into equivalent Carve while preserving protected regions.
 *
 * Key differences from Markdown that this converter handles:
 * - Blank lines are required around block elements (headings, code fences, lists)
 * - Emphasis uses / (not * or _), strong uses * (not **)
 * - _x_ is underline in Carve, so Markdown underscore emphasis becomes /x/
 */
class MarkdownToCarve
{
    /**
     * Convert Markdown text to Carve text.
     */
    public function convert(string $markdown): string
    {
        $lines = explode("\n", str_replace(["\r\n", "\r"], "\n", $markdown));
        $result = [];
        $inCodeBlock = false;
        $fenceChar = '';
        $fenceLength = 0;
        $prevLineType = 'blank';

        // Bullet-marker run tracking, so adjacent bullet lists stay distinct in
        // Carve. `$activeBulletMd` is the Markdown marker (-,*,+) of the current
        // run, `$activeBulletCarve` the `-`/`*` emitted for it, and
        // `$bulletRunBroken` is true once a non-list block separates this from
        // the previous bullet list.
        $activeBulletMd = null;
        $activeBulletCarve = null;
        $bulletRunBroken = true;

        $lineCount = count($lines);
        for ($i = 0; $i < $lineCount; $i++) {
            $line = $lines[$i];
            $trimmed = trim($line);

            if (!$inCodeBlock && preg_match('/^(\s{0,3})(`{3,}|~{3,})(.*)$/', $line, $matches)) {
                if ($prevLineType !== 'blank' && $result !== []) {
                    $result[] = '';
                }

                $inCodeBlock = true;
                $fenceChar = $matches[2][0];
                $fenceLength = strlen($matches[2]);
                // Canonical fence opener has no space between the fence and the
                // info string (```php, not ``` php). Carve accepts both (lenient
                // input; Markdown/Djot may write the space), but emits the
                // no-space form. The rest of the info is preserved (c++, js
                // title="x").
                $result[] = $matches[1] . $matches[2] . ltrim($matches[3]);
                $prevLineType = 'code_fence';
                $bulletRunBroken = true;

                continue;
            }

            if ($inCodeBlock) {
                $bulletRunBroken = true;
                $pattern = '/^\s{0,3}' . preg_quote($fenceChar, '/') . '{' . $fenceLength . ',}\s*$/';
                if (preg_match($pattern, $line)) {
                    $inCodeBlock = false;
                    $fenceChar = '';
                    $fenceLength = 0;
                    $result[] = $line;
                    if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                        $result[] = '';
                    }
                    $prevLineType = 'code_fence';
                } else {
                    $result[] = $line;
                    $prevLineType = 'code';
                }

                continue;
            }

            $isBlank = $trimmed === '';
            $isHeading = (bool)preg_match('/^#{1,6}\s/', $trimmed);
            $indent = strlen($line) - strlen(ltrim($line));
            $isBlockquote = str_starts_with($trimmed, '>');
            $ordered = preg_match('/^(\d+)[.)]\s/', $trimmed, $orderedMatches) === 1 ? $orderedMatches : null;
            $isList = ((bool)preg_match('/^[-*+]\s/', $trimmed) || $ordered !== null)
                && !($prevLineType === 'text' && $ordered !== null && (int)$ordered[1] !== 1);

            if ($isBlank) {
                $result[] = $line;
                $prevLineType = 'blank';

                continue;
            }

            // A GFM table header: a `|...|` row whose NEXT line is a delimiter
            // row (the table's second row). Emit the Carve-canonical `|=` header
            // with alignment markers and drop the separator; body rows pass
            // through unchanged. Native `|=` and separatorless tables are left
            // as-is (no following delimiter row triggers this).
            if (
                preg_match('/^\|.*\|$/', $trimmed)
                && $i + 1 < $lineCount
                && $this->isGfmDelimiterRow(trim($lines[$i + 1]))
            ) {
                if ($prevLineType !== 'blank' && $result !== []) {
                    $result[] = '';
                }
                $result[] = $this->gfmHeaderToCarve($trimmed, trim($lines[$i + 1]));
                $i++; // skip the delimiter row
                $prevLineType = 'text';
                $bulletRunBroken = true;

                continue;
            }

            if ($prevLineType === 'list' && $indent >= 1) {
                $result[] = $this->convertInlineFormatting($line);
                $prevLineType = 'list';

                continue;
            }

            $underline = $i + 1 < $lineCount ? trim($lines[$i + 1]) : '';
            if (
                !$isHeading
                && !$isBlockquote
                && !$isList
                && (preg_match('/^=+$/', $underline) || preg_match('/^-+$/', $underline))
            ) {
                if ($prevLineType !== 'blank' && $prevLineType !== 'heading') {
                    $result[] = '';
                }

                $marker = $underline[0] === '=' ? '#' : '##';
                $result[] = $this->convertInlineFormatting($marker . ' ' . $trimmed);
                $i++;
                if ($i + 1 < $lineCount && trim($lines[$i + 1]) !== '') {
                    $result[] = '';
                }
                $prevLineType = 'heading';
                $bulletRunBroken = true;

                continue;
            }

            if ($isHeading && $prevLineType !== 'blank' && $prevLineType !== 'heading') {
                $result[] = '';
            }
            if ($isBlockquote && $prevLineType !== 'blank' && $prevLineType !== 'blockquote') {
                $result[] = '';
            }
            if ($isList && $prevLineType !== 'list' && $prevLineType !== 'blank') {
                $result[] = '';
            }

            $dedent = $indent >= 1 && $indent <= 3 && ($isHeading || $isBlockquote);
            $body = $dedent ? substr($line, $indent) : $line;
            if ($isHeading) {
                $body = preg_replace('/[ \t]+#+[ \t]*$/', '', $body) ?? $body;
            }
            // Carve has only `-`/`*` bullets (no `+`, which is the
            // continuation marker), and two adjacent bullet lists must use
            // different markers or Carve merges them into one. Keep the
            // Markdown marker when it does not collide with an adjacent
            // preceding list; otherwise flip to the other marker.
            if ($isList && $ordered === null) {
                $mdMarker = $trimmed[0];
                if (!$bulletRunBroken && $mdMarker === $activeBulletMd) {
                    $carveMarker = (string)$activeBulletCarve;
                } else {
                    $preferred = $mdMarker === '+' ? '-' : $mdMarker;
                    $carveMarker = !$bulletRunBroken && $preferred === $activeBulletCarve
                        ? ($activeBulletCarve === '-' ? '*' : '-')
                        : $preferred;
                }
                $body = preg_replace('/^(\s*)[-*+](\s)/', '${1}' . $carveMarker . '$2', $body) ?? $body;
                $activeBulletMd = $mdMarker;
                $activeBulletCarve = $carveMarker;
                $bulletRunBroken = false;
            }

            $result[] = $this->convertInlineFormatting($body);

            if ($isHeading && $i + 1 < $lineCount) {
                $nextTrimmed = trim($lines[$i + 1]);
                if ($nextTrimmed !== '' && !preg_match('/^#{1,6}\s/', $nextTrimmed)) {
                    $result[] = '';
                }
            }

            if ($isHeading) {
                $prevLineType = 'heading';
                $bulletRunBroken = true;
            } elseif ($isList) {
                $prevLineType = 'list';
                if ($ordered !== null) {
                    // An ordered list between two bullet lists keeps them
                    // separate, so it breaks the bullet-marker run.
                    $bulletRunBroken = true;
                }
            } elseif ($isBlockquote) {
                $prevLineType = 'blockquote';
                $bulletRunBroken = true;
            } else {
                $prevLineType = 'text';
                $bulletRunBroken = true;
            }
        }

        return preg_replace('/\n{3,}/', "\n\n", implode("\n", $result)) ?? implode("\n", $result);
    }

    /**
     * A GFM delimiter row: `|`-delimited cells, each a run of dashes with
     * optional leading/trailing alignment colons, and nothing else.
     */
    protected function isGfmDelimiterRow(string $line): bool
    {
        if (!preg_match('/^\|.*\|$/', $line)) {
            return false;
        }
        $cells = $this->splitPipeCells($line);
        if ($cells === []) {
            return false;
        }
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-+:?$/', trim($cell))) {
                return false;
            }
        }

        return true;
    }

    /**
     * Convert a GFM header row + its delimiter row to the Carve `|=` header
     * form, carrying the column alignment from the delimiter colons into the
     * `|=<` / `|=>` / `|=~` markers.
     */
    protected function gfmHeaderToCarve(string $headerLine, string $delimiterLine): string
    {
        $headers = $this->splitPipeCells($headerLine);
        $delims = $this->splitPipeCells($delimiterLine);
        $cells = [];
        foreach ($headers as $idx => $header) {
            $d = isset($delims[$idx]) ? trim($delims[$idx]) : '';
            $left = str_starts_with($d, ':');
            $right = str_ends_with($d, ':');
            $marker = match (true) {
                $left && $right => '|=~ ',
                $right => '|=> ',
                $left => '|=< ',
                default => '|= ',
            };
            $cells[] = $marker . $this->convertInlineFormatting(trim($header));
        }

        return implode(' ', $cells) . ' |';
    }

    /**
     * Split a `|`-delimited table row into trimmed cell sources (outer pipes
     * removed; escaped `\|` is not a delimiter).
     *
     * @return array<int, string>
     */
    protected function splitPipeCells(string $line): array
    {
        $line = trim($line);
        $line = preg_replace('/^\|/', '', $line) ?? $line;
        $line = preg_replace('/\|$/', '', $line) ?? $line;
        $parts = preg_split('/(?<!\\\\)\|/', $line);

        return $parts === false ? [] : $parts;
    }

    /**
     * Convert inline Markdown formatting to Carve on one non-code-block line.
     */
    protected function convertInlineFormatting(string $line): string
    {
        $protected = [];
        $protect = function (string $span) use (&$protected): string {
            $protected[] = $span;

            return "\x00P" . (count($protected) - 1) . "\x00";
        };

        $line = $this->protectCodeSpans($line, $protect);

        $line = preg_replace_callback('/\\\\[^A-Za-z0-9\s]/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/<code>([^<]+)<\/code>/i', fn (array $match): string => $protect('`' . $match[1] . '`'), $line) ?? $line;

        $encodeDest = static function (string $paren): string {
            $inner = substr($paren, 1, -1);
            if (preg_match('/^(\S+)([\s\S]*)$/', $inner, $matches)) {
                $url = $matches[1];
                $rest = $matches[2];
            } else {
                $url = $inner;
                $rest = '';
            }

            return '(' . str_replace(['(', ')'], ['%28', '%29'], $url) . $rest . ')';
        };

        $destination = '\((?:[^()\n]|\([^()\n]*\))*\)';
        $line = preg_replace_callback(
            '/(!\[(?:[^[\]]|\[[^\]]*\])*\])(' . $destination . ')/',
            fn (array $match): string => $protect($match[1] . $encodeDest($match[2])),
            $line,
        ) ?? $line;
        $line = preg_replace_callback(
            '/(?<=\])(' . $destination . ')/',
            fn (array $match): string => $protect($encodeDest($match[1])),
            $line,
        ) ?? $line;
        $line = preg_replace_callback('/(?<=\])\[[^\]]*\]/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/<[A-Za-z][A-Za-z0-9+.-]*:[^>\s]+>/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/<[^>\s@]+@[^>\s]+>/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/\bhttps?:\/\/[^\s<>`]+/', fn (array $match): string => $protect($match[0]), $line) ?? $line;
        $line = preg_replace_callback('/^\s*\[[^^\]][^\]]*\]:\s*\S.*$/', fn (array $match): string => $protect($match[0]), $line) ?? $line;

        $line = preg_replace_callback('/\$\$([^$]+)\$\$/', fn (array $match): string => $protect('$$`' . $match[1] . '`'), $line) ?? $line;
        $line = preg_replace_callback('/\$([^$\s][^$]*[^$\s]|\S)\$(?!\d)/', function (array $match) use ($protect): string {
            return preg_match('/^[\d.,]+$/', $match[1])
                ? $match[0]
                : $protect('$`' . $match[1] . '`');
        }, $line) ?? $line;

        $stash = [];
        $hold = function (string $span) use (&$stash): string {
            $stash[] = $span;

            return "\x00S" . (count($stash) - 1) . "\x00";
        };

        $convertNestedEm = static function (string $inner): string {
            $inner = preg_replace('/(?<![A-Za-z0-9*])\*(?!\s)([^*]+?)(?<!\s)\*(?![A-Za-z0-9*])/', '/$1/', $inner) ?? $inner;

            return preg_replace('/(?<![A-Za-z0-9_])_(?!\s)([^_]+?)(?<!\s)_(?![A-Za-z0-9_])/', '/$1/', $inner) ?? $inner;
        };

        $line = preg_replace_callback('/\*{3}(?!\s)(.+?)(?<!\s)\*{3}/', fn (array $match): string => $hold('/*' . $convertNestedEm($match[1]) . '*/'), $line) ?? $line;
        $line = preg_replace_callback('/(?<![A-Za-z0-9])___(?!\s)(.+?)(?<!\s)___(?![A-Za-z0-9])/', fn (array $match): string => $hold('/*' . $convertNestedEm($match[1]) . '*/'), $line) ?? $line;
        $line = preg_replace_callback('/\*\*(?!\s)(.+?)(?<!\s)\*\*/', fn (array $match): string => $hold('*' . $convertNestedEm($match[1]) . '*'), $line) ?? $line;
        $line = preg_replace_callback('/(?<![A-Za-z0-9])__(?!\s)(.+?)(?<!\s)__(?![A-Za-z0-9])/', fn (array $match): string => $hold('*' . $convertNestedEm($match[1]) . '*'), $line) ?? $line;
        $line = preg_replace('/(?<![A-Za-z0-9*])\*(?!\s)([^*]+?)(?<!\s)\*(?![A-Za-z0-9*])/', '/$1/', $line) ?? $line;
        $line = preg_replace('/(?<![A-Za-z0-9_])_(?!\s)([^_]+?)(?<!\s)_(?![A-Za-z0-9_])/', '/$1/', $line) ?? $line;
        $line = preg_replace('/~~([^~]+)~~/', '~$1~', $line) ?? $line;

        // ==highlight== -> =highlight=. Carve highlight is a single `=`; a
        // doubled `==x==` is literal text in Carve, so a Markdown highlight
        // left unchanged would silently mis-render.
        $line = preg_replace('/==(?!\s)([^=]+?)(?<!\s)==/', '=$1=', $line) ?? $line;

        // Highlight/super/subscript use the forced brace forms: an HTML tag can
        // sit intraword (e.g. H<sub>2</sub>O), where a bare ,2, / ^2^ / =2= is
        // literal in Carve; the {,x,} / {^x^} / {=x=} forms render anywhere.
        $htmlRules = [
            '/<mark>([^<]+)<\/mark>/i' => '{=$1=}',
            '/<ins>([^<]+)<\/ins>/i' => '{+$1+}',
            '/<del>([^<]+)<\/del>/i' => '~$1~',
            '/<s>([^<]+)<\/s>/i' => '~$1~',
            '/<sup>([^<]+)<\/sup>/i' => '{^$1^}',
            '/<sub>([^<]+)<\/sub>/i' => '{,$1,}',
            '/<strong>([^<]+)<\/strong>/i' => '*$1*',
            '/<b>([^<]+)<\/b>/i' => '*$1*',
            '/<em>([^<]+)<\/em>/i' => '/$1/',
            '/<i>([^<]+)<\/i>/i' => '/$1/',
        ];
        foreach ($htmlRules as $pattern => $replacement) {
            $line = preg_replace($pattern, $replacement, $line) ?? $line;
        }

        // Restore stashes and protected spans until stable: a protected or
        // stashed span may itself contain placeholders (e.g. a reference
        // definition that wrapped an already-protected URL), so one pass is
        // not enough.
        do {
            $previous = $line;
            $line = preg_replace_callback('/\x00S(\d+)\x00/', fn (array $match): string => $stash[(int)$match[1]], $line) ?? $line;
            $line = preg_replace_callback('/\x00P(\d+)\x00/', fn (array $match): string => $protected[(int)$match[1]], $line) ?? $line;
        } while ($line !== $previous);

        return $line;
    }

    /**
     * Protect inline code spans, including multi-backtick spans.
     *
     * @param string $line
     * @param callable $replace
     */
    protected function protectCodeSpans(string $line, callable $replace): string
    {
        $out = '';
        $i = 0;
        $length = strlen($line);
        while ($i < $length) {
            if ($line[$i] !== '`') {
                $out .= $line[$i];
                $i++;

                continue;
            }

            $runLength = $this->backtickRunLength($line, $i);
            $j = $i + $runLength;
            $closed = -1;
            while ($j < $length) {
                if (
                    $line[$j] === '`'
                    && ($j === 0 || $line[$j - 1] !== '`')
                    && $this->backtickRunLength($line, $j) === $runLength
                ) {
                    $closed = $j;

                    break;
                }
                $j++;
            }

            if ($closed === -1) {
                $out .= substr($line, $i, $runLength);
                $i += $runLength;

                continue;
            }

            $out .= $replace(substr($line, $i, $closed - $i + $runLength));
            $i = $closed + $runLength;
        }

        return $out;
    }

    protected function backtickRunLength(string $line, int $index): int
    {
        $length = strlen($line);
        $runLength = 0;
        while ($index + $runLength < $length && $line[$index + $runLength] === '`') {
            $runLength++;
        }

        return $runLength;
    }

    /**
     * Convert a Markdown file to Carve.
     *
     * @throws \RuntimeException If file cannot be read
     */
    public function convertFile(string $inputPath): string
    {
        if (!is_file($inputPath)) {
            throw new RuntimeException("File not found: {$inputPath}");
        }

        $content = file_get_contents($inputPath);
        if ($content === false) {
            throw new RuntimeException("Failed to read file: {$inputPath}");
        }

        return $this->convert($content);
    }

    /**
     * Convert a Markdown file and save as Carve.
     *
     * @throws \RuntimeException If file cannot be read or written
     */
    public function convertFileAndSave(string $inputPath, ?string $outputPath = null): void
    {
        $carve = $this->convertFile($inputPath);

        if ($outputPath === null) {
            $outputPath = preg_replace('/\.md$/i', '.crv', $inputPath) ?? $inputPath;
            if ($outputPath === $inputPath) {
                $outputPath .= '.crv';
            }
        }

        $result = file_put_contents($outputPath, $carve);
        if ($result === false) {
            throw new RuntimeException("Failed to write file: {$outputPath}");
        }
    }
}

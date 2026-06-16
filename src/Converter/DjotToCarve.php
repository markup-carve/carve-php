<?php

declare(strict_types=1);

namespace Carve\Converter;

use Carve\CarveConverter;
use Carve\Converter\HeadingId\HeadingIdSource;
use Carve\Converter\HeadingId\HtmlHeadingIds;
use RuntimeException;
use function array_splice;
use function array_values;
use function count;
use function explode;
use function implode;
use function preg_match;
use function sprintf;

/**
 * Converts Djot markup to Carve markup.
 *
 * Several inline delimiters mean different things in Djot and Carve, so a Djot
 * document fed to a Carve processor renders wrong with no error. This converter
 * rewrites exactly those constructs to their Carve equivalents:
 *
 *   _x_ -> /x/ (Djot emphasis is underline in Carve)
 *   ~x~ -> {,x,} (Djot subscript is strikethrough in Carve; forced brace form)
 *   {=x=} -> {=x=} (highlight is the same braced form in Carve)
 *   **x** -> *x* (Markdown bold; Carve bold is a single *)
 *   ~~x~~ -> ~x~ (Markdown strikethrough; Carve strike is a single ~)
 *
 * Constructs that mean the same in both languages (^sup^, $math$, {+ins+},
 * {-del-}, reference links) are left untouched. Delimiters inside code (fenced
 * or inline) and link/image destinations are never rewritten. Only the
 * delimiters are replaced, never the inner text, so nested constructs of
 * different families compose correctly.
 *
 * Ported from the carve-js djot-migrate linter (the canonical list of
 * Djot/Carve delimiter collisions). Operates byte-wise so offsets from the
 * masked scan splice into the original UTF-8 string unchanged.
 */
class DjotToCarve
{
    /**
     * @var array<array{id: string, family: string, pattern: string, open: string, close: string}>
     */
    protected array $rules = [
        [
            'id' => 'markdown-strong-double-star',
            'family' => '*',
            'pattern' => '/\*\*(?!\s)((?:(?!\n[ \t]*\n)[^*])+?)(?<!\s)\*\*/',
            'open' => '*',
            'close' => '*',
        ],
        [
            'id' => 'markdown-strikethrough-double-tilde',
            'family' => '~',
            'pattern' => '/~~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~~/',
            'open' => '~',
            'close' => '~',
        ],
        [
            'id' => 'djot-subscript-tilde',
            'family' => '~',
            'pattern' => '/~(?!\s)((?:(?!\n[ \t]*\n)[^~])+?)(?<!\s)~/',
            'open' => '{,',
            'close' => ',}',
        ],
        [
            // Carve superscript is word-boundary-sensitive, so a bare `^2^`
            // after `c` (E=mc^2^) would be literal; emit the forced form.
            'id' => 'djot-superscript-caret',
            'family' => '^',
            'pattern' => '/\^(?!\s)((?:(?!\n[ \t]*\n)[^^])+?)(?<!\s)\^/',
            'open' => '{^',
            'close' => '^}',
        ],
        [
            'id' => 'djot-emphasis-underscore',
            'family' => '_',
            'pattern' => '/(?<![A-Za-z0-9_])_(?!\s)((?:(?!\n[ \t]*\n)[^_])+?)(?<!\s)_(?![A-Za-z0-9_])/',
            'open' => '/',
            'close' => '/',
        ],
        [
            'id' => 'djot-highlight-braces',
            'family' => '{',
            'pattern' => '/\{=(?!\s)((?:(?!\n[ \t]*\n)[\s\S])+?)(?<!\s)=\}/',
            'open' => '{=',
            'close' => '=}',
        ],
    ];

    protected ?HeadingIdSource $headingIdSource = null;

    /**
     * Preserve the published heading ids of the source document.
     *
     * Carve's auto id can differ from what a live Djot site already published
     * (case, a custom id transformer, the permalink extension, an older
     * renderer). Pass a source of the live ids and, for every heading whose
     * Carve id would differ, the converter injects an explicit `{#id}`
     * block-attribute line above it so inbound links keep resolving. Pass null
     * to disable (the default).
     *
     * @return $this
     */
    public function preserveHeadingIds(?HeadingIdSource $source)
    {
        $this->headingIdSource = $source;

        return $this;
    }

    /**
     * Convert Djot markup to Carve markup.
     */
    public function convert(string $djot): string
    {
        $source = str_replace(["\r\n", "\r"], "\n", $djot);
        $masked = $this->maskCode($source);

        /** @var array<array{0: int, 1: int, 2: string}> $taken */
        $taken = [];
        /** @var array<array{0: int, 1: int, 2: string}> $edits */
        $edits = [];

        foreach ($this->rules as $rule) {
            if (!preg_match_all($rule['pattern'], $masked, $found, PREG_OFFSET_CAPTURE | PREG_SET_ORDER)) {
                continue;
            }
            /** @var array<array{0: string, 1: int}> $match */
            foreach ($found as $match) {
                $start = $match[0][1];
                $end = $start + strlen($match[0][0]);

                $backslashes = 0;
                for ($k = $start - 1; $k >= 0 && $masked[$k] === '\\'; $k--) {
                    $backslashes++;
                }
                if ($backslashes % 2 === 1) {
                    continue;
                }

                if ($this->sameFamilyOverlap($taken, $start, $end, $rule['family'])) {
                    continue;
                }

                $contentStart = $match[1][1];
                $contentEnd = $contentStart + strlen($match[1][0]);

                $taken[] = [$start, $end, $rule['family']];
                // Replace only the delimiters; leave inner bytes untouched.
                $edits[] = [$start, $contentStart, $rule['open']];
                $edits[] = [$contentEnd, $end, $rule['close']];
            }
        }

        usort($edits, fn (array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$editStart, $editEnd, $replacement]) {
            $source = substr($source, 0, $editStart) . $replacement . substr($source, $editEnd);
        }

        $carve = $this->normalizePlusBullets($source, $masked);

        if ($this->headingIdSource !== null) {
            $carve = $this->injectPreservedHeadingIds($djot, $carve, $this->headingIdSource);
        }

        return $carve;
    }

    /**
     * Pin published heading ids that Carve would generate differently.
     *
     * For each heading in document order: compare the live id (from the
     * configured source) against the id Carve actually renders for the migrated
     * source (rendered + scraped, never a re-derived slug, so custom slugging
     * and extensions are honored). Where they differ - and the heading is not
     * already pinned - inject a `{#liveId}` block-attribute line above it.
     *
     * Headings are located by their start line. Adjacent or `#`-folded
     * multi-line headings (a Carve-specific construct a published Djot doc is
     * unlikely to contain) would desync the positional pairing, so a heading
     * count mismatch throws rather than mis-pair.
     *
     * @throws \RuntimeException on a heading-count mismatch
     */
    protected function injectPreservedHeadingIds(
        string $djot,
        string $carve,
        HeadingIdSource $source,
    ): string {
        $liveIds = array_values($source->idsInOrder($djot));
        $carveIds = HtmlHeadingIds::extract((new CarveConverter())->convert($carve));

        $lines = explode("\n", $carve);
        $maskedLines = explode("\n", $this->maskCode($carve));
        $headingLines = [];
        foreach ($maskedLines as $index => $line) {
            if (preg_match('/^[ ]{0,3}#{1,6} +.*\S.*$/', $line) === 1) {
                $headingLines[] = $index;
            }
        }

        $count = count($headingLines);
        if ($count !== count($carveIds) || $count !== count($liveIds)) {
            throw new RuntimeException(sprintf(
                'preserveHeadingIds: heading count mismatch (source lines %d, Carve render %d, '
                . 'live ids %d). Adjacent or multi-line `#` headings are not supported.',
                $count,
                count($carveIds),
                count($liveIds),
            ));
        }

        // Splice in reverse so earlier line indices stay valid.
        for ($k = $count - 1; $k >= 0; $k--) {
            $live = $liveIds[$k];
            if ($live === '' || $live === $carveIds[$k]) {
                continue;
            }
            $lineIndex = $headingLines[$k];
            // Already pinned by an explicit `{#...}` block-attribute line above?
            if ($lineIndex > 0 && preg_match('/^\s*\{#[^}\s][^}]*\}\s*$/', $lines[$lineIndex - 1]) === 1) {
                continue;
            }
            preg_match('/^(\s*)/', $lines[$lineIndex], $indent);
            array_splice($lines, $lineIndex, 0, [($indent[1] ?? '') . '{#' . $live . '}']);
        }

        return implode("\n", $lines);
    }

    /**
     * Rewrite Djot `+` bullet markers to `-`.
     *
     * Djot allows `-`, `*` and `+` as bullets; Carve does not have a `+` bullet
     * (it is the list-continuation marker), so a Djot `+` list would otherwise
     * convert to a plain paragraph. The code mask is used to skip lines inside
     * fenced blocks. Inline delimiter edits never cross newlines, so the masked
     * string stays line-aligned with the edited source.
     *
     * @param string $source The delimiter-converted source
     * @param string $masked The code-masked original (line-aligned)
     *
     * @return string
     */
    protected function normalizePlusBullets(string $source, string $masked): string
    {
        $lines = explode("\n", $source);
        $maskedLines = explode("\n", $masked);
        foreach ($lines as $i => $line) {
            if (!isset($maskedLines[$i]) || !preg_match('/^(\s*)\+(\s)/', $maskedLines[$i])) {
                continue;
            }
            $lines[$i] = preg_replace('/^(\s*)\+(\s)/', '$1-$2', $line) ?? $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<array{0: int, 1: int, 2: string}> $taken
     * @param string $family
     * @param int $end
     * @param int $start
     */
    protected function sameFamilyOverlap(array $taken, int $start, int $end, string $family): bool
    {
        foreach ($taken as [$takenStart, $takenEnd, $takenFamily]) {
            if ($takenFamily === $family && $start < $takenEnd && $takenStart < $end) {
                return true;
            }
        }

        return false;
    }

    /**
     * Replace every code character (fenced blocks, inline code spans) and
     * link/image destinations with spaces, preserving newlines so byte offsets
     * stay aligned with the original source.
     */
    protected function maskCode(string $source): string
    {
        // Stage 1: fenced blocks, line by line.
        $lines = explode("\n", $source);
        $fenceChar = null;
        $fenceLen = 0;
        foreach ($lines as $i => $line) {
            if ($fenceChar !== null) {
                if (
                    preg_match('/^ {0,3}([`~]{3,})[ \t]*$/', $line, $close)
                    && $close[1][0] === $fenceChar
                    && strlen($close[1]) >= $fenceLen
                ) {
                    $fenceChar = null;
                    $fenceLen = 0;
                }
                $lines[$i] = $this->blanks($line);

                continue;
            }
            if (preg_match('/^\s*(`{3,}|~{3,})\s*[a-zA-Z0-9_-]*\s*$/', $line, $open)) {
                $fenceChar = $open[1][0];
                $fenceLen = strlen($open[1]);
                $lines[$i] = $this->blanks($line);
            }
        }
        $masked = implode("\n", $lines);

        // Stage 2: inline code spans. A run of N backticks closes at the next run of exactly N.
        $length = strlen($masked);
        $i = 0;
        while ($i < $length) {
            if ($masked[$i] !== '`') {
                $i++;

                continue;
            }
            $run = $this->backtickRun($masked, $i);
            $j = $i + $run;
            $closed = -1;
            while ($j < $length) {
                if ($masked[$j] === '`' && $this->backtickRun($masked, $j) === $run) {
                    $closed = $j;

                    break;
                }
                $j++;
            }
            if ($closed === -1) {
                $i += $run;

                continue;
            }
            for ($k = $i; $k < $closed + $run; $k++) {
                if ($masked[$k] !== "\n") {
                    $masked[$k] = ' ';
                }
            }
            $i = $closed + $run;
        }

        // Stage 3: link/image destinations.
        $masked = preg_replace_callback(
            '/(?<=\])\([^()\n]*\)/',
            fn (array $group): string => $this->blanks($group[0]),
            $masked,
        );

        return $masked ?? $source;
    }

    protected function backtickRun(string $text, int $offset): int
    {
        $count = 0;
        $length = strlen($text);
        while ($offset + $count < $length && $text[$offset + $count] === '`') {
            $count++;
        }

        return $count;
    }

    protected function blanks(string $text): string
    {
        return preg_replace('/[^\n]/', ' ', $text) ?? $text;
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\HeadingId;

use MarkupCarve\Carve\CarveConverter;
use RuntimeException;
use function array_splice;
use function array_values;
use function count;
use function explode;
use function implode;
use function preg_match;
use function preg_quote;
use function sprintf;
use function strlen;

/**
 * Shared heading-id preservation for the source-to-source converters
 * (DjotToCarve, MarkdownToCarve).
 *
 * A document published live has auto-generated heading ids baked into inbound
 * links, TOC fragments, and bookmarks. Carve can generate a different id for
 * the same heading (case, a custom id transformer, the permalink extension, an
 * older or different renderer - GitHub/kramdown/pandoc each slug differently),
 * silently breaking those links on import.
 *
 * The logic is format-agnostic: it operates on the produced Carve output, so a
 * converter just calls applyHeadingIdPreservation() at the end of convert().
 * Carve's own ids are obtained by rendering the migrated output and scraping the
 * result - never a re-derived slug - so custom slugging and extensions are
 * honored regardless of which input format produced the headings.
 */
trait PreservesHeadingIds
{
    /**
     * @var string
     */
    private const LINE_PREFIX = '[ \t]{0,3}(?:>[ \t]?)*';

    /**
     * Fenced-code delimiters are column-exact after container prefixes. The
     * preservation scan only models blockquote prefixes; top-level leading
     * whitespace is residual indentation and must not open or close a fence.
     *
     * @var string
     */
    private const FENCE_LINE_PREFIX = '(?:>[ \t]?)*';

    protected ?HeadingIdSource $headingIdSource = null;

    /**
     * Preserve the published heading ids of the source document.
     *
     * For every heading whose Carve id would differ from the live one, an
     * explicit `{#id}` block-attribute line is injected above it so Carve
     * renders the published id verbatim. Pass null to disable (the default).
     *
     * @return $this
     */
    public function preserveHeadingIds(?HeadingIdSource $source)
    {
        $this->headingIdSource = $source;

        return $this;
    }

    /**
     * Inject `{#id}` above each heading whose Carve id differs from the live
     * one. A no-op when preservation is disabled.
     *
     * Headings are paired positionally with the live ids. Adjacent or
     * `#`-folded multi-line headings (a Carve-specific construct a published
     * document is unlikely to contain) would desync the pairing, so a heading
     * count mismatch throws rather than mis-pair.
     *
     * @throws \RuntimeException on a heading-count mismatch
     */
    protected function applyHeadingIdPreservation(string $carve, string $originalSource): string
    {
        $source = $this->headingIdSource;
        if ($source === null) {
            return $carve;
        }

        $liveIds = array_values($source->idsInOrder($originalSource));
        $carveIds = HtmlHeadingIds::extract((new CarveConverter())->convert($carve));

        $lines = explode("\n", $carve);
        $headingLines = $this->scanHeadingStartLines($lines);

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
            $pinned = '/^' . self::LINE_PREFIX . '\{#[^}\s][^}]*\}[ \t]*$/';
            if ($lineIndex > 0 && preg_match($pinned, $lines[$lineIndex - 1]) === 1) {
                continue;
            }
            // Carry the heading's blockquote / indent prefix onto the attr line.
            preg_match('/^(' . self::LINE_PREFIX . ')/', $lines[$lineIndex], $prefix);
            array_splice($lines, $lineIndex, 0, [($prefix[1] ?? '') . '{#' . $live . '}']);
        }

        return implode("\n", $lines);
    }

    /**
     * Line indices of ATX heading starts, skipping fenced code blocks so a `#`
     * inside code is not mistaken for a heading.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, int>
     */
    private function scanHeadingStartLines(array $lines): array
    {
        $headingLines = [];
        $inFence = false;
        $fenceChar = '';
        $fenceLen = 0;

        foreach ($lines as $index => $line) {
            if ($inFence) {
                $close = '/^' . self::FENCE_LINE_PREFIX . '(' . preg_quote($fenceChar, '/') . '{' . $fenceLen . ',})[ \t]*$/';
                if (preg_match($close, $line) === 1) {
                    $inFence = false;
                }

                continue;
            }
            if (preg_match('/^' . self::FENCE_LINE_PREFIX . '(`{3,}|~{3,})/', $line, $fence) === 1) {
                $inFence = true;
                $fenceChar = $fence[1][0];
                $fenceLen = strlen($fence[1]);

                continue;
            }
            if (preg_match('/^' . self::LINE_PREFIX . '#{1,6} +.*\S.*$/', $line) === 1) {
                $headingLines[] = $index;
            }
        }

        return $headingLines;
    }
}

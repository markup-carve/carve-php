<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Generated\ImportTables;
use SplObjectStorage;

/**
 * The state of one import: the option, the diagnostics record (C2-M-080) and what the first passes collect.
 */
final class ImportContext
{
    /**
     * @var array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    public array $diagnostics = [];

    /**
     * The notes of the footnote section by the id of their item (C2-H-089).
     *
     * @var array<string, array{item: \MarkupCarve\Carve\Import\HtmlNode, label: string|null, inline: bool}>
     */
    public array $notes = [];

    /**
     * @var array<array<string, mixed>>
     */
    public array $footnotes = [];

    /**
     * C2-H-187: the abbreviation definitions read from templates, by key.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $abbreviations = [];

    /**
     * The templates that stand where a render writes them, with the definitions each holds.
     *
     * @var \SplObjectStorage<\MarkupCarve\Carve\Import\HtmlNode, array<array<string, mixed>>>
     */
    public SplObjectStorage $templates;

    /**
     * C2-H-191: one rebuilt definition per reference key.
     *
     * @var array<string, array<string, mixed>>
     */
    public array $definitions = [];

    /**
     * C2-H-198: the keys whose first element is an `<a>` with an `href` of the form `#ID`.
     *
     * @var array<string, true>
     */
    public array $anchorKeys = [];

    public ?string $rootLang = null;

    public ?string $rootDir = null;

    /**
     * C2-M-112: a table with a cell of blocks becomes a list table.
     */
    public bool $listTable = false;

    /**
     * @param bool $rawHtml
     * @param array<string, string> $labels
     */
    public function __construct(public readonly bool $rawHtml, public readonly array $labels = [])
    {
        $this->templates = new SplObjectStorage();
    }

    /**
     * C2-M-081: the entry names the HTML node in `foreignType` and uses its source token where available.
     */
    public function report(HtmlNode $node, string $code, string $message): void
    {
        $severity = ImportTables::CODES[$code] ?? 'warning';
        $start = $code === 'import-whitespace-normalized' && $node->contentStart >= 0
            ? $node->contentStart : $node->sourceStart;
        $this->diagnostics[] = new Diagnostic(
            $code,
            $severity,
            'render-loss',
            $message,
            startOffset: $start,
            endOffset: $start < 0 ? -1 : max($start, $node->sourceEnd),
            foreignType: $node->tag(),
        );
    }

    /**
     * C2-M-009: a carrier that `html.md` ignores.
     */
    public function ignoredCarrier(HtmlNode $node, string $name, string $reason): void
    {
        $this->report($node, 'import-carrier-ignored', 'Carrier ' . $name . ' ignored: ' . $reason);
    }
}

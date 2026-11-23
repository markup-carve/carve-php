<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use Error;
use Exception;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Import\Hub\DjotHtml;
use MarkupCarve\Carve\Import\Hub\MarkdownHtml;
use MarkupCarve\Carve\Import\Hub\Sink;
use MarkupCarve\Carve\OptionError;

/**
 * Markdown, GFM and Djot import through the HTML hub (ruling of 17 November): a library renders HTML and the HTML
 * importer reads it. The hub only adds what the library HTML cannot carry on its own.
 */
final class HubImporter
{
    /**
     * @var array<string>
     */
    public const FORMATS = ['markdown', 'gfm', 'djot'];

    /**
     * The optional Composer package each format renders HTML with.
     *
     * @var array<string, array{0: string, 1: string}>
     */
    private const LIBRARIES = [
        'markdown' => ['league/commonmark', 'League\\CommonMark\\Environment\\Environment'],
        'gfm' => ['league/commonmark', 'League\\CommonMark\\Environment\\Environment'],
        'djot' => ['php-collective/djot', 'Djot\\DjotConverter'],
    ];

    /**
     * @var array<string, string>
     */
    private const ALERT_KINDS = ['NOTE' => 'note', 'TIP' => 'tip', 'IMPORTANT' => 'info', 'WARNING' => 'warning', 'CAUTION' => 'danger'];

    /**
     * @var array<string>
     */
    private const EMPTY_BODIES = ['list_item', 'definition_description'];

    /**
     * @param string $input
     * @param string $format `markdown`, `gfm` or `djot`.
     * @param array<string, mixed> $options `strictLosses` (C2-M-082), `allowLosses` and `maxLosses` (C2-H-204, C2-H-205).
     *
     * @throws \MarkupCarve\Carve\OptionError An editor adapter other than `generic` (C2-M-102).
     * @throws \MarkupCarve\Carve\Import\ImportInputError
     *
     * @return \MarkupCarve\Carve\Import\ImportResult
     */
    public static function import(string $input, string $format, array $options = []): ImportResult
    {
        // C2-M-102: an editor adapter reads HTML only.
        if (($options['adapter'] ?? 'generic') !== 'generic') {
            throw new OptionError('option adapter: HTML only');
        }
        self::requireLibrary($format);
        $sink = new Sink();
        try {
            $html = $format === 'djot' ? DjotHtml::html($input, $sink) : MarkdownHtml::html($input, $format === 'gfm', $sink);
        } catch (Exception|Error $e) {
            throw new ImportInputError($format . ': ' . $e->getMessage(), 0, $e);
        }
        // The library trees are cyclic; left to the collector they tax whatever the host runs next.
        gc_collect_cycles();
        $options = ['rawHtml' => true] + array_intersect_key($options, array_flip(['strictLosses', 'allowLosses', 'maxLosses']));

        return HtmlImporter::import(
            $html,
            $options,
            prepare: static function (HtmlNode $root) use ($format): void {
                self::markTightLists($root);
                if ($format === 'gfm') {
                    self::githubAlerts($root);
                }
            },
            finish: static function (array $children) use ($sink): array {
                $lead = $sink->frontmatterFormat === null ? []
                    : [['type' => 'frontmatter', 'format' => $sink->frontmatterFormat, 'value' => $sink->frontmatter]];
                $children = self::commentEmptyBodies($children);

                return array_merge($lead, $sink->raw === [] ? $children : self::restoreRaw($children, $sink->raw));
            },
            report: self::withoutHtmlRanges(...),
        );
    }

    /**
     * @param string $format `markdown`, `gfm` or `djot`.
     *
     * @throws \MarkupCarve\Carve\Import\ImportInputError When the library of the format is not installed.
     *
     * @return void
     */
    public static function requireLibrary(string $format): void
    {
        [$package, $class] = self::LIBRARIES[$format] ?? ['', ''];
        if ($class !== '' && !class_exists($class)) {
            throw new ImportInputError($format . ' import requires the ' . $package . ' package: composer require ' . $package);
        }
    }

    /**
     * Both libraries write a tight list's paragraphs bare and a loose list's in `<p>`; the HTML alone reads an item of
     * several blocks as loose (C2-M-033), so each list without an item paragraph gets `data-carve-tight`.
     */
    private static function markTightLists(HtmlNode $root): void
    {
        $pending = [$root];
        while ($pending !== []) {
            $node = array_pop($pending);
            foreach ($node->children as $child) {
                if ($child->isElement()) {
                    $pending[] = $child;
                }
            }
            if (!$node->isElement('ul') && !$node->isElement('ol')) {
                continue;
            }
            $loose = false;
            foreach ($node->children as $item) {
                if ($item->isElement('li') && $item->firstElement('p') !== null) {
                    $loose = true;

                    break;
                }
            }
            if (!$loose) {
                $node->attrs['data-carve-tight'] = '';
            }
        }
    }

    /**
     * A GitHub alert, a quote whose first line is `[!KIND]` alone, is the admonition the 0.1 importer made of it (C2-A-066).
     */
    private static function githubAlerts(HtmlNode $root): void
    {
        $pending = [$root];
        while ($pending !== []) {
            $node = array_pop($pending);
            foreach ($node->children as $child) {
                if ($child->isElement()) {
                    $pending[] = $child;
                }
            }
            if (!$node->isElement('blockquote') || $node->attrs !== []) {
                continue;
            }
            $first = null;
            foreach ($node->children as $child) {
                if (!($child->isText() && trim($child->name) === '')) {
                    $first = $child;

                    break;
                }
            }
            $lead = $first !== null && $first->isElement('p') ? ($first->children[0] ?? null) : null;
            if ($first === null || $lead === null || !$lead->isText() || preg_match('/^\[!([A-Z]+)\](?:\n|$)/', $lead->name, $m) !== 1) {
                continue;
            }
            $kind = self::ALERT_KINDS[$m[1]] ?? null;
            if ($kind === null) {
                continue;
            }
            $lead->name = substr($lead->name, strlen($m[0]));
            if ($lead->name === '') {
                array_shift($first->children);
            }
            $blank = true;
            foreach ($first->children as $child) {
                $blank = $blank && $child->isText() && trim($child->name) === '';
            }
            if ($blank) {
                $node->children = array_values(array_filter($node->children, static fn (HtmlNode $c): bool => $c !== $first));
            }
            $node->name = 'aside';
            $node->attrs['class'] = 'admonition ' . $kind;
        }
    }

    /**
     * C2-A-186, C2-W-057: a parsed item or description is never empty; its empty spelling is a marker plus a comment.
     * An item the library left empty (`-`, or one whose only content was dropped) takes that comment.
     *
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    private static function commentEmptyBodies(array $nodes): array
    {
        foreach ($nodes as $i => $node) {
            if (!is_array($node) || !is_array($node['children'] ?? null)) {
                continue;
            }
            if ($node['children'] === [] && in_array($node['type'] ?? null, self::EMPTY_BODIES, true)) {
                $node['children'] = [['type' => 'comment', 'form' => 'line', 'value' => '']];
            } else {
                $node['children'] = self::commentEmptyBodies($node['children']);
            }
            $nodes[$i] = $node;
        }

        return $nodes;
    }

    /**
     * C2-M-065: each placeholder comment back to the raw block it stands for, with its format. A raw block after text
     * in a tight list item reaches the tree as inline raw content, and stays inline.
     *
     * @param array<mixed> $nodes
     * @param array<array{format: string, value: string}> $raw
     *
     * @return array<mixed>
     */
    private static function restoreRaw(array $nodes, array $raw): array
    {
        foreach ($nodes as $i => $node) {
            if (!is_array($node)) {
                continue;
            }
            $value = $node['value'] ?? null;
            $type = $node['type'] ?? null;
            if (
                ($type === 'raw_block' || $type === 'raw_inline')
                && is_string($value)
                && preg_match(Sink::RAW_PLACEHOLDER, $type === 'raw_inline' ? $value . "\n" : $value, $m) === 1
            ) {
                $kept = $raw[(int)$m[1]] ?? null;
                if ($kept !== null) {
                    $text = str_ends_with($kept['value'], "\n") ? substr($kept['value'], 0, -1) : $kept['value'];
                    $node['format'] = $kept['format'];
                    $node['value'] = $type === 'raw_block' ? $text . "\n" : $text;
                }
            }
            if (isset($node['children']) && is_array($node['children'])) {
                $node['children'] = self::restoreRaw($node['children'], $raw);
            }
            $nodes[$i] = $node;
        }

        return $nodes;
    }

    /**
     * The libraries' own line breaks are no whitespace of the input, and the findings name no range of the
     * intermediate HTML.
     *
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     *
     * @return array<\MarkupCarve\Carve\Diagnostics\Diagnostic>
     */
    private static function withoutHtmlRanges(array $diagnostics): array
    {
        $out = [];
        foreach ($diagnostics as $d) {
            if ($d->code === 'import-whitespace-normalized') {
                continue;
            }
            $out[] = $d->startOffset < 0 || !str_starts_with($d->code, 'import-') ? $d
                : new Diagnostic($d->code, $d->severity, $d->channel, $d->message, $d->path, -1, -1, $d->file, $d->foreignType);
        }

        return $out;
    }
}

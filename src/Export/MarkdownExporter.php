<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Export;

use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Output\OutputWriter;

/**
 * The Markdown export of a 0.2 tree: CommonMark with the GFM tables, task lists, strikethrough and footnotes.
 * It is also the writer of the reserved target `markdown`.
 */
final class MarkdownExporter implements OutputWriter
{
    /**
     * The Markdown of `$tree` and the losses of the run.
     *
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree A tree of `Carve::parse()` or `Carve::decodeTree()`, or decoded AST JSON
     *   as `Carve::render()` takes it; it is not changed.
     * @param array<string, mixed> $options `smartTypography`, `strictLosses`, `urlPolicy`.
     *
     * @return \MarkupCarve\Carve\Export\ExportResult
     */
    public function export(Node|array $tree, array $options = []): ExportResult
    {
        $x = Exporter::start('markdown', $tree, $options);

        return $x->finish(Markdown::export($x), ($options['strictLosses'] ?? false) === true);
    }

    public function target(): string
    {
        return 'markdown';
    }

    /**
     * @param \MarkupCarve\Carve\Node|array<string, mixed> $tree
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public function write(Node|array $tree, array $options = []): string
    {
        return $this->export($tree, $options)->output;
    }
}

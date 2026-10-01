<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use LogicException;

// phpcs:disable SlevomatCodingStandard.Namespaces.FullyQualifiedClassNameInAnnotation -- These are PHPStan aliases, not class names.
/**
 * The tree and decisions produced by one completed HTML import.
 *
 * @internal
 *
 * @phpstan-type ImportedNode array{type: string, ...<string, mixed>}
 * @phpstan-type CodeBlockNode array{type: 'code_block', content: string, lang?: string, attrs?: Attrs}
 * @phpstan-type ContainerNode array{type: 'div'|'admonition'|'directive', children: list<ImportedNode>, kind?: string, title?: list<ImportedNode>, attrs?: Attrs}
 * @phpstan-type FigureGroupNode array{type: 'figure_group', children: list<ImportedNode>, caption?: list<ImportedNode>, attrs?: Attrs}
 * @phpstan-type DocumentTree array{type: 'document', srcByteLength: int, children: list<ImportedNode>}
 * @phpstan-type Attrs array{id?: string, classes?: list<string>, keyValues?: array<string, string>, order?: list<string>}
 * @phpstan-type FigureTargetNode array{type: 'image'|'block_quote'|'code_block', ...<string, mixed>}
 * @phpstan-type FigureNode array{type: 'figure', target: FigureTargetNode, caption: list<ImportedNode>, attrs?: Attrs}
 * @phpstan-type ParagraphNode array{type: 'paragraph', children: list<ImportedNode>, attrs?: Attrs}
 * @phpstan-type MathNode array{type: 'math', display: bool, content: string, attrs?: Attrs}
 * @phpstan-type TableCellNode array{type: 'table_cell', header: bool, children: list<ImportedNode>, span?: 'rowspan'|'colspan', align?: string, valign?: string, attrs?: Attrs}
 * @phpstan-type TableRowNode array{type: 'table_row', cells: list<TableCellNode>, attrs?: Attrs}
 */
// phpcs:enable SlevomatCodingStandard.Namespaces.FullyQualifiedClassNameInAnnotation
final class HtmlAstBuildResult
{
    /**
     * @phpstan-param DocumentTree $tree
     *
     * @param array $tree
     * @param \MarkupCarve\Carve\Converter\HtmlImportSession $session
     * @param bool $sourceSafe
     */
    public function __construct(
        public readonly array $tree,
        public readonly HtmlImportSession $session,
        private readonly bool $sourceSafe,
    ) {
    }

    /**
     * @throws \LogicException
     *
     * @return array<string, mixed>
     */
    public function publicTree(): array
    {
        if ($this->sourceSafe) {
            throw new LogicException('A source-writer tree cannot be exported as the public AST.');
        }
        $tree = $this->tree;
        foreach ($tree as $key => $value) {
            $tree[$key] = self::asPublished($value);
        }

        return $tree;
    }

    /**
     * One value of the encoded tree, with the writer's escapes undone.
     *
     * ON THE ENCODED TREE rather than the node model, and recursing over LISTS
     * rather than over a roster of container keys: every container spells its
     * children under its own name - `children`, `items`, `rows`, `cells` - and
     * a roster is what would rot. A table cell and a span are reached by the
     * same lines that reach a paragraph.
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private static function asPublished(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            $out = [];
            foreach ($value as $entry) {
                $entry = self::asPublished($entry);
                if (is_array($entry) && ($entry['type'] ?? null) === 'escaped_text') {
                    $escaped = $entry['value'] ?? '';
                    $entry = ['type' => 'text', 'value' => is_string($escaped) ? $escaped : ''];
                }
                $last = $out === [] ? null : array_key_last($out);
                $previous = $last === null ? null : $out[$last];
                if (
                    $last !== null
                    && is_array($entry)
                    && ($entry['type'] ?? null) === 'text'
                    && is_array($previous)
                    && ($previous['type'] ?? null) === 'text'
                ) {
                    $head = $previous['value'] ?? '';
                    $tail = $entry['value'] ?? '';
                    $out[$last] = [
                        'type' => 'text',
                        'value' => (is_string($head) ? $head : '') . (is_string($tail) ? $tail : ''),
                    ];

                    continue;
                }
                $out[] = $entry;
            }

            return $out;
        }
        foreach ($value as $key => $inner) {
            $value[$key] = self::asPublished($inner);
        }

        return $value;
    }
}

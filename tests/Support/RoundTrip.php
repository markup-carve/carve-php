<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Support;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Import\Alignments;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\Inline\Chars;
use MarkupCarve\Carve\Json\TreeEquality;

/**
 * The round trip of C2-H-188: render with `roundtrip`, import with `rawHtml` on (C2-M-090), compare with the parsed tree
 * under the equality of C2-A-162. The parsed tree first loses what the clauses say does not survive, each loss under the
 * name of its clause: the rows of C2-H-195, the definitions of C2-H-193 and C2-H-194, and the facts a render does not
 * write (C2-H-188). A difference that remains is a defect of the importer, of the renderer or of the list.
 */
final class RoundTrip
{
    /**
     * Facts the render does not write in a form HTML can give back (C2-H-188); a document that holds one is counted
     * under its class and not compared.
     *
     * @var array<string, string>
     */
    private const UNWRITTEN = [
        'include' => 'C2-H-188 include',
        'symbol' => 'C2-H-188 symbol',
        'citation' => 'C2-H-188 citation',
        'citation_group' => 'C2-H-188 citation',
        'block_extension' => 'C2-H-188 extension',
        'inline_extension' => 'C2-H-188 extension',
        'directive' => 'C2-H-188 directive',
    ];

    /**
     * C2-H-195 row 8: the directive kinds a plain div can name with its first class.
     *
     * @var array<string>
     */
    private const DIRECTIVE_KINDS = ['bibliography', 'footnotes', 'glossary', 'index', 'references', 'toc'];

    /**
     * The blocks whose children are inline content, with edges HTML does not keep.
     *
     * @var array<string>
     */
    private const INLINE_BLOCKS = ['paragraph', 'heading', 'cell_paragraph', 'definition_term'];

    /**
     * @var array<string>
     */
    public array $losses = [];

    private bool $rawHtml = false;

    /**
     * @param array<string, mixed> $options
     */
    private static function byOptions(array $options): ?string
    {
        if (($options['profile'] ?? 'full') !== 'full') {
            return 'C2-H-188 profile output';
        }

        return isset($options['social']) ? 'C2-H-188 social URLs' : null;
    }

    /**
     * @param string $source
     * @param array<string, mixed> $options Render options of the source.
     *
     * @return array{0: string, 1: array<mixed>, 2: array<mixed>, 3: array<string>} The verdict (`equal`, `excluded: X`
     *   or `differs`), the projected parsed children, the imported children and the losses applied.
     */
    public static function check(string $source, array $options = []): array
    {
        $parsed = json_decode(Carve::toJson($source, Corpus::parseOptions($options)), true, 4096, JSON_THROW_ON_ERROR);
        $html = Carve::toHtml($source, ['roundtrip' => true] + $options);
        $imported = HtmlImporter::import($html, ['rawHtml' => true])->document;
        $trip = new self();
        $trip->rawHtml = ($options['rawHtml'] ?? false) === true;
        $excluded = self::byOptions($options) ?? $trip->excluded($parsed, $html);
        if ($excluded !== null) {
            return ['excluded: ' . $excluded, [], [], []];
        }
        $expected = TreeEquality::normalize($trip->project($parsed));
        $got = TreeEquality::normalize($imported['children']);

        return [$expected === $got ? 'equal' : 'differs', $expected, $got, array_values(array_unique($trip->losses))];
    }

    /**
     * @param array<mixed> $doc
     * @param string $html
     *
     * @return string|null
     */
    private function excluded(array $doc, string $html): ?string
    {
        $found = null;
        $notes = [];
        self::walk($doc, static function (array $node) use (&$notes): void {
            if (($node['type'] ?? '') === 'footnote') {
                $notes[$node['key']] = true;
            }
        });
        self::walk($doc, static function (array $node) use (&$found, $notes): void {
            $found ??= self::UNWRITTEN[$node['type'] ?? ''] ?? self::unresolved($node, $notes) ?? self::restructured($node);
        });
        if ($found === null && (str_contains($html, ' rel="') || preg_match('/\s[a-z-]+=""/', $html) === 1)) {
            $found = 'C2-H-188 URL policy or profile output';
        }

        return $found;
    }

    /**
     * @param array<mixed> $node
     * @param array<string, true> $notes The keys of the footnotes of the document.
     */
    private static function unresolved(array $node, array $notes): ?string
    {
        $type = $node['type'] ?? '';
        if ($type === 'footnote_ref') {
            return isset($notes[$node['key']]) ? null : 'C2-H-188 unresolved reference';
        }
        if ($type === 'xref') {
            return isset($node['href']) ? null : 'C2-H-188 unresolved reference';
        }
        if (!in_array($type, ['link', 'image'], true) || !isset($node['key'])) {
            return null;
        }

        return isset($node[$type === 'link' ? 'href' : 'src']) ? null : 'C2-H-188 unresolved reference';
    }

    /**
     * @param array<mixed> $node
     */
    private static function restructured(array $node): ?string
    {
        $type = $node['type'] ?? '';
        if ($type === 'link' && self::interactive($node['children'] ?? [])) {
            return 'gap: interactive content in a link';
        }
        $keys = array_map(static fn (array $pair): string => strtolower($pair[0]), $node['attrs']['keyValues'] ?? []);
        if (in_array('style', $keys, true) && in_array('align', $keys, true)) {
            return 'gap: align key folded into style';
        }
        if (($type === 'raw_block' || $type === 'raw_inline') && ($node['format'] ?? '') !== 'html') {
            return 'C2-H-054 raw content of another format';
        }

        return null;
    }

    /**
     * @param array<mixed> $nodes
     */
    private static function interactive(array $nodes): bool
    {
        $found = false;
        self::walk($nodes, static function (array $node) use (&$found): void {
            $found = $found || in_array($node['type'] ?? '', ['link', 'autolink', 'xref', 'footnote_ref', 'inline_footnote'], true);
        });

        return $found;
    }

    /**
     * @param array<mixed> $value
     * @param \Closure(array<mixed>): void $visit
     */
    private static function walk(array $value, Closure $visit): void
    {
        if (isset($value['type'])) {
            $visit($value);
        }
        foreach ($value as $item) {
            if (is_array($item)) {
                self::walk($item, $visit);
            }
        }
    }

    /**
     * @param array<mixed> $doc
     *
     * @return array<mixed>
     */
    private function project(array $doc): array
    {
        $children = $this->definitions($doc['children']);

        return $this->nodes($children, false, false);
    }

    /**
     * C2-H-193, C2-H-194, C2-H-195 rows 13 and 14: the used definitions after the blocks, links first in the order of
     * first use, then the footnotes in the order of their numbers; the attributes of a link definition on its uses.
     *
     * @param array<mixed> $children
     *
     * @return array<mixed>
     */
    private function definitions(array $children): array
    {
        [$uses, $notes, $headings] = self::firstUses($children);
        [$blocks, $defs, $footnotes] = $this->partition($children, $uses, $notes);
        $merged = [];
        foreach ($defs as $key => $def) {
            if (isset($def['lang']) || isset($def['dir'])) {
                unset($defs[$key]['lang'], $defs[$key]['dir']);
                $this->losses[] = 'gap: lang or dir of a link definition';
            }
            if (isset($def['attrs'])) {
                $merged[$key] = $def['attrs'];
                unset($defs[$key]['attrs']);
                $this->losses[] = 'C2-H-195 row 14 attributes of a link definition';
            }
            if (self::toHeading((string)$key, $def, $headings)) {
                unset($defs[$key]);
                $this->losses[] = 'C2-H-195 row 13 definition to the heading its key finds';
            }
        }
        uksort($defs, static fn ($a, $b): int => $uses[$a] <=> $uses[$b]);
        uksort($footnotes, static fn ($a, $b): int => $notes[$a] <=> $notes[$b]);
        $out = array_merge($blocks, array_values($defs), array_values($footnotes));
        if (array_keys($defs) !== array_keys(array_filter($defs)) || $children !== $out) {
            $this->losses[] = 'C2-H-193 C2-H-194 place of a definition';
        }

        return $merged === [] ? $out : self::mergeAttrs($out, $merged);
    }

    /**
     * The order of first use of every link key and the lowest number of every footnote key, outside the later
     * definitions of a key, and the folded text and id of every heading.
     *
     * @param array<mixed> $children
     *
     * @return array{0: array<string, int>, 1: array<string, int>, 2: array<array{0: string, 1: string|null}>}
     */
    private static function firstUses(array $children): array
    {
        $uses = [];
        $notes = [];
        $headings = [];
        $first = [];
        $live = [];
        foreach ($children as $child) {
            $name = $child['type'] . ':' . ($child['key'] ?? '');
            if (in_array($child['type'], ['footnote', 'link_reference_definition'], true)) {
                if (isset($first[$name])) {
                    continue;
                }
                $first[$name] = true;
            }
            $live[] = $child;
        }
        self::walk($live, static function (array $node) use (&$uses, &$notes, &$headings): void {
            $type = $node['type'] ?? '';
            if (in_array($type, ['link', 'image'], true) && isset($node['key'])) {
                $uses[$node['key']] ??= count($uses);
            }
            if ($type === 'footnote_ref') {
                $notes[$node['key']] = min($notes[$node['key']] ?? PHP_INT_MAX, $node['number'] ?? PHP_INT_MAX);
            }
            if ($type === 'heading') {
                $id = $node['attrs']['id'] ?? $node['generatedId'] ?? null;
                $headings[] = [Chars::fold(self::plain($node['children'] ?? [])), $id];
            }
        });

        return [$uses, $notes, $headings];
    }

    /**
     * The blocks, and the first used definition of every key.
     *
     * @param array<mixed> $children
     * @param array<string, int> $uses
     * @param array<string, int> $notes
     *
     * @return array{0: array<mixed>, 1: array<string, array<mixed>>, 2: array<string, array<mixed>>}
     */
    private function partition(array $children, array $uses, array $notes): array
    {
        $blocks = [];
        $defs = [];
        $footnotes = [];
        foreach ($children as $child) {
            $type = $child['type'];
            if ($type !== 'link_reference_definition' && $type !== 'footnote') {
                $blocks[] = $child;

                continue;
            }
            $link = $type === 'link_reference_definition';
            $kept = $link ? $defs : $footnotes;
            if (!isset(($link ? $uses : $notes)[$child['key']]) || isset($kept[$child['key']])) {
                $this->losses[] = $link ? 'C2-H-193 unused or later definition' : 'C2-H-194 unused or later footnote';

                continue;
            }
            if ($link) {
                $defs[$child['key']] = $child;
            } else {
                $footnotes[$child['key']] = $child;
            }
        }

        return [$blocks, $defs, $footnotes];
    }

    /**
     * C2-H-198: the first heading whose text the key finds is the one the definition links to.
     *
     * @param string $key
     * @param array<mixed> $def
     * @param array<array{0: string, 1: string|null}> $headings
     */
    private static function toHeading(string $key, array $def, array $headings): bool
    {
        foreach ($headings as [$text, $id]) {
            if ($text === Chars::fold($key)) {
                return $id !== null && ($def['href'] ?? null) === '#' . $id;
            }
        }

        return false;
    }

    /**
     * @param array<mixed> $value
     * @param array<string, array<string, mixed>> $merged
     *
     * @return array<mixed>
     */
    private static function mergeAttrs(array $value, array $merged): array
    {
        $type = $value['type'] ?? '';
        if (in_array($type, ['link', 'image'], true) && isset($value['key'], $merged[$value['key']])) {
            $value['attrs'] = array_merge_recursive($merged[$value['key']], $value['attrs'] ?? []);
        }
        foreach ($value as $key => $item) {
            if (is_array($item)) {
                $value[$key] = self::mergeAttrs($item, $merged);
            }
        }

        return $value;
    }

    /**
     * @param array<mixed> $nodes
     */
    private static function plain(array $nodes): string
    {
        $out = '';
        foreach ($nodes as $node) {
            $out .= match ($node['type'] ?? '') {
                'text', 'code', 'math', 'smart_punctuation' => $node['value'] ?? $node['glyph'] ?? '',
                'soft_break', 'hard_break' => ' ',
                'non_breaking_space' => "\u{00A0}",
                default => self::plain($node['children'] ?? []),
            };
        }

        return $out;
    }

    /**
     * The losses that change a node list.
     *
     * @param array<mixed> $nodes
     * @param bool $cell The list is the inline content of a table cell (C2-H-195 rows 6 and 15).
     * @param bool $edge
     *
     * @return array<mixed>
     */
    private function nodes(array $nodes, bool $cell, bool $edge): array
    {
        $out = [];
        foreach ($nodes as $node) {
            array_push($out, ...$this->node($node, $cell));
        }

        return $this->edges($out, $cell, $edge);
    }

    /**
     * What a comment that renders nothing (C2-H-069) leaves: breaks in a row, a break at the edge of inline content
     * (C2-M-018), an empty paragraph or term (C2-M-069); and the text edges of a cell (C2-H-195 rows 6 and 15).
     *
     * @param array<mixed> $nodes
     * @param bool $cell
     * @param bool $edge
     *
     * @return array<mixed>
     */
    private function edges(array $nodes, bool $cell, bool $edge): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $type = $node['type'] ?? '';
            $previous = $out[count($out) - 1]['type'] ?? null;
            if ($type === 'hard_break' && $previous === 'soft_break') {
                $this->losses[] = 'C2-H-195 row 5 soft break before a hard break';
                $out[count($out) - 1] = $node;

                continue;
            }
            if ($type === 'soft_break' && $previous === 'soft_break') {
                $this->losses[] = 'C2-H-069 breaks around a comment';

                continue;
            }
            if (in_array($type, ['paragraph', 'definition_term'], true) && ($node['children'] ?? null) === [] && !isset($node['attrs'])) {
                $this->losses[] = 'C2-M-069 empty paragraph or term';

                continue;
            }
            $out[] = $node;
        }
        while ($edge && $out !== [] && in_array(end($out)['type'] ?? '', ['soft_break', 'hard_break'], true)) {
            array_pop($out);
            $this->losses[] = 'C2-M-018 break at the end of inline content';
        }
        if ($edge && ($out[0]['type'] ?? '') === 'soft_break') {
            array_shift($out);
            $this->losses[] = 'C2-M-018 break at the start of inline content';
        }

        return $cell ? $this->cellText($out) : $out;
    }

    /**
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    private function cellText(array $nodes): array
    {
        $out = self::joinText($nodes);
        foreach ($out as $i => $node) {
            if (($node['type'] ?? '') === 'text' && str_contains($node['value'], "\n")) {
                $out[$i]['value'] = str_replace("\n", ' ', $node['value']);
                $this->losses[] = 'C2-H-195 row 6 line break in a cell';
            }
        }
        $last = count($out) - 1;
        foreach ([0 => 'ltrim', $last => 'rtrim'] as $i => $trim) {
            if ($i >= 0 && ($out[$i]['type'] ?? '') === 'text' && $trim($out[$i]['value'], ' ') !== $out[$i]['value']) {
                $out[$i]['value'] = $trim($out[$i]['value'], ' ');
                $this->losses[] = 'C2-H-195 row 15 whitespace at the edge of a cell';
            }
        }

        return array_values(array_filter($out, static fn ($n): bool => ($n['type'] ?? '') !== 'text' || $n['value'] !== ''));
    }

    /**
     * @param array<mixed> $node
     * @param bool $cell
     *
     * @return array<mixed>
     */
    private function node(array $node, bool $cell): array
    {
        $type = $node['type'];
        $replaced = $this->replace($node, $cell);
        if ($replaced !== null) {
            return $replaced;
        }
        foreach ($node as $key => $value) {
            if (is_array($value) && isset($value['type'])) {
                $node[$key] = $this->node($value, false)[0] ?? $value;
            } elseif (is_array($value) && $value !== [] && array_is_list($value) && is_array($value[0]) && isset($value[0]['type'])) {
                $node[$key] = $this->nodes(
                    $value,
                    $type === 'cell_paragraph' || ($cell && $key === 'children'),
                    $key === 'children' && in_array($type, self::INLINE_BLOCKS, true),
                );
            }
        }

        return [$this->fields($node)];
    }

    /**
     * Nodes that come back as other nodes, or not at all.
     *
     * @param array<mixed> $node
     * @param bool $cell
     *
     * @return array<mixed>|null
     */
    private function replace(array $node, bool $cell): ?array
    {
        $type = $node['type'];
        if ($type === 'comment' || $type === 'frontmatter') {
            $this->losses[] = 'C2-H-069 C2-H-070 comment and frontmatter render nothing';

            return [];
        }
        if ($type === 'smart_punctuation') {
            $this->losses[] = 'C2-M-091 smart punctuation as glyphs';

            return [['type' => 'text', 'value' => $node['glyph']]];
        }
        if ($type === 'non_breaking_space') {
            $this->losses[] = 'C2-H-195 row 3 non-breaking space';

            return [['type' => 'text', 'value' => "\u{00A0}"]];
        }
        if ($type === 'table_cell' && isset($node['span'])) {
            $this->losses[] = 'C2-H-056 cell covered by a span';

            return [];
        }
        if ($type === 'literal_inline') {
            $this->losses[] = 'gap: literal inline as a span';
            $text = ['type' => 'text', 'value' => $node['value']];

            return [isset($node['attrs']) ? ['type' => 'span', 'attrs' => $node['attrs'], 'children' => [$text]] : $text];
        }
        if ($type === 'raw_block' && ($node['format'] ?? '') === 'html' && !$this->rawHtml) {
            $this->losses[] = 'C2-H-195 row 2 raw block with rawHtml off';

            return [['type' => 'code_block', 'value' => $node['value'], 'syntax' => 'html']];
        }
        if ($cell && ($type === 'soft_break' || $type === 'hard_break')) {
            $this->losses[] = 'C2-H-195 row 6 line break in a cell';

            return [['type' => 'text', 'value' => ' ']];
        }

        return null;
    }

    /**
     * Field losses of one node.
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function fields(array $node): array
    {
        $type = $node['type'];
        if ($type === 'list' && $node['spacing'] === 'loose' && count($node['children']) < 2) {
            $node['spacing'] = 'tight';
            $this->losses[] = 'C2-H-195 row 23 loose spacing of a list with one item';
        }
        if ($type === 'list' && $node['spacing'] === 'tight') {
            $node = $this->bareImages($node);
        }
        if (isset($node['attrs']['keyValues'])) {
            $node = $this->keys($node);
        }
        if ($type === 'table' && ($node['children'][0]['type'] ?? '') === 'table_head') {
            $node = $this->headAligns($node);
        }
        if ($type === 'table' && !str_contains((string)json_encode($node), 'span"')) {
            $node = $this->resolvedAligns($node);
        }
        if ($type === 'div' && in_array($node['attrs']['classes'][0] ?? '', self::DIRECTIVE_KINDS, true)) {
            $this->losses[] = 'C2-H-195 row 8 div with a directive class';
            $node['type'] = 'directive';
            $node['kind'] = array_shift($node['attrs']['classes']);
            $node = self::tidy($node);
        }
        $first = $node['attrs']['classes'][0] ?? '';
        if ($type === 'span' && str_starts_with($first, 'ext-') && strlen($first) > 4) {
            $this->losses[] = 'C2-H-195 row 9 span with an ext- class';
            $node['type'] = 'inline_extension';
            $node['name'] = substr(array_shift($node['attrs']['classes']), 4);
            $node = self::tidy($node);
        }
        if (
            $type === 'paragraph' && ($node['children'][0]['type'] ?? '') === 'math' && count($node['children']) === 1
            && $node['children'][0]['display'] && !isset($node['role'])
        ) {
            $node['role'] = 'math';
            $this->losses[] = 'C2-H-195 row 11 display math paragraph without a role';
        }

        return $node;
    }

    /**
     * C2-H-195 rows 7, 10, 12 and 16, and the table options a render reads (C2-H-152).
     *
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private function keys(array $node): array
    {
        $pairs = [];
        foreach ($node['attrs']['keyValues'] as [$key, $value]) {
            $lower = strtolower($key);
            if (str_starts_with($lower, 'data-carve-')) {
                $this->losses[] = 'C2-H-186 authored reserved name';

                continue;
            }
            if (isset($pairs[$lower])) {
                $this->losses[] = 'gap: repeated attribute key';
                unset($pairs[$lower]);
            }
            if ($lower !== $key) {
                $this->losses[] = 'C2-H-195 row 12 case of an attribute name';
            }
            if ($lower === 'lang' && !isset($node['lang'])) {
                $node['lang'] = $value;
                $this->losses[] = 'C2-H-195 row 10 authored key lang';

                continue;
            }
            if ($node['type'] === 'table' && in_array($lower, ['aligns', 'widths', 'header-rows', 'footer-rows'], true)) {
                $this->losses[] = 'gap: table option key the render reads';

                continue;
            }
            if ($node['type'] === 'link' && $lower === 'title' && !isset($node['tooltip'])) {
                $node['tooltip'] = $value;
                $this->losses[] = 'C2-H-195 row 16 structural attribute key';

                continue;
            }
            $pairs[$lower] = [$lower, $value];
        }
        $node['attrs']['keyValues'] = array_values($pairs);

        return self::tidy($node);
    }

    /**
     * C2-H-195 row 7: with a head row, the alignment of a column comes back as a mark on the header cell of the first
     * head row, which has no span in the inputs this gate reads.
     *
     * @param array<mixed> $table
     *
     * @return array<mixed>
     */
    private function headAligns(array $table): array
    {
        foreach ($table['columns'] as $c => $column) {
            if (!isset($column['align'])) {
                continue;
            }
            $this->losses[] = 'C2-H-195 row 7 column alignment with a head row';
            unset($table['columns'][$c]['align']);
            $table['children'][0]['children'][0]['children'][$c]['align'] ??= $column['align'];
        }

        return $table;
    }

    /**
     * A render writes on every cell the alignment C2-B-213 resolves (C2-H-057), and the import resolves it backward by
     * C2-M-040, so a mark equal to the one its column resolves does not survive. C2-H-195 does not list this loss.
     *
     * @param array<mixed> $table
     *
     * @return array<mixed>
     */
    private function resolvedAligns(array $table): array
    {
        $original = $table;
        $head = self::headMarks($table);
        foreach ($table['children'] as $g => $group) {
            $inHead = $group['type'] === 'table_head';
            foreach ($group['children'] as $r => $row) {
                foreach ($row['children'] as $c => $cell) {
                    $align = $cell['align'] ?? ($inHead ? null : $head[$c] ?? null) ?? $table['columns'][$c]['align'] ?? null;
                    if ($align !== null) {
                        $table['children'][$g]['children'][$r]['children'][$c]['align'] = $align;
                    }
                }
            }
        }
        foreach ($table['columns'] as $c => $column) {
            unset($table['columns'][$c]['align']);
        }
        $resolved = Alignments::resolve($table);
        if ($resolved !== $original) {
            $this->losses[] = 'gap: cell alignment equal to the one its column resolves';
        }

        return $resolved;
    }

    /**
     * The first mark of the head in every column.
     *
     * @param array<mixed> $table
     *
     * @return array<int, string|null>
     */
    private static function headMarks(array $table): array
    {
        $head = [];
        foreach ($table['children'] as $group) {
            foreach ($group['type'] === 'table_head' ? $group['children'] : [] as $row) {
                foreach ($row['children'] as $c => $cell) {
                    $head[$c] ??= $cell['align'] ?? null;
                }
            }
        }

        return $head;
    }

    /**
     * @param array<mixed> $node
     *
     * @return array<mixed>
     */
    private static function tidy(array $node): array
    {
        foreach (['keyValues', 'classes'] as $field) {
            if (($node['attrs'][$field] ?? null) === []) {
                unset($node['attrs'][$field]);
            }
        }
        if (($node['attrs'] ?? null) === []) {
            unset($node['attrs']);
        }

        return $node;
    }

    /**
     * A paragraph a tight list writes without `<p>` when it opens an item (C2-H-034, C2-H-018).
     *
     * @param array<mixed> $block
     */
    private static function bare(array $block): bool
    {
        $marked = isset($block['role']) || isset($block['attrs']) || isset($block['lang']) || isset($block['dir']);

        return ($block['type'] ?? '') === 'paragraph' && !$marked;
    }

    /**
     * C2-H-034 and C2-M-013: the bare first paragraph of a tight item that holds only images and whitespace comes back
     * as one image paragraph per image. C2-H-195 does not list this loss.
     *
     * @param array<mixed> $list
     *
     * @return array<mixed>
     */
    private function bareImages(array $list): array
    {
        foreach ($list['children'] as $i => $item) {
            $first = $item['children'][0] ?? [];
            if (!self::bare($first)) {
                continue;
            }
            $images = [];
            foreach ($first['children'] as $child) {
                $blank = $child['type'] === 'soft_break' || ($child['type'] === 'text' && trim($child['value']) === '');
                if ($child['type'] !== 'image' && !$blank) {
                    continue 2;
                }
                if ($child['type'] === 'image') {
                    $images[] = ['type' => 'paragraph', 'role' => 'image', 'children' => [$child]];
                }
            }
            if ($images !== []) {
                $this->losses[] = 'gap: bare paragraph of images in a tight item';
                array_splice($list['children'][$i]['children'], 0, 1, $images);
            }
        }

        return $list;
    }

    /**
     * C2-A-040: the cell text is trimmed as one value.
     *
     * @param array<mixed> $nodes
     *
     * @return array<mixed>
     */
    private static function joinText(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $node) {
            $last = count($out) - 1;
            if (is_array($node) && ($node['type'] ?? '') === 'text' && $last >= 0 && ($out[$last]['type'] ?? '') === 'text') {
                $out[$last]['value'] .= $node['value'];

                continue;
            }
            $out[] = $node;
        }

        return $out;
    }
}

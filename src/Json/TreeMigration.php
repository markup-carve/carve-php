<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Inline\Chars;

final class TreeMigration
{
    /**
     * @var array<string>
     */
    private const SLOTS = [
        'children', 'items', 'rows', 'cells', 'old', 'new', 'prefix', 'suffix', 'inline',
        'title', 'shortCaption', 'base', 'annotation', 'pairs', 'content', 'blocks',
    ];

    /**
     * @param array<string, mixed>|string $input
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     */
    public static function migrate(array|string $input, array $options = []): array
    {
        [$old, $envelope, $original] = self::input($input);
        $document = self::node($old)[0];
        $document['astVersion'] = '2.0';
        Carve::validateTree($document);
        $source = is_string($options['source'] ?? null) ? $options['source'] : '';
        $layout = is_string($options['source'] ?? null)
            ? Carve::parseWithLayout($source)->layout : self::layout($old, $document);
        $result = ['document' => $document, 'layout' => $layout, 'diagnostics' => []];
        if ($envelope) {
            $envelopeValue = ['astVersion' => '2.0', 'document' => $document];
            if (isset($original['vocabulary'])) {
                $envelopeValue['vocabulary'] = $original['vocabulary'];
            }
            if (isset($original['extensions'])) {
                $envelopeValue['extensions'] = $original['extensions'];
            }
            $result['envelope'] = $envelopeValue;
        }

        return $result;
    }

    /**
     * @param array<string, mixed>|string $input
     *
     * @throws \MarkupCarve\Carve\Json\TreeError
     *
     * @return array{0: array<string, mixed>, 1: bool, 2: array<string, mixed>}
     */
    private static function input(array|string $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true, 4096, JSON_THROW_ON_ERROR);
        }
        if (!is_array($input)) {
            throw new TreeError('schema', '');
        }
        $envelope = !isset($input['type']);
        $old = $envelope ? ($input['document'] ?? null) : $input;
        if (!is_array($old) || ($old['type'] ?? null) !== 'document') {
            throw new TreeError('schema', $envelope ? '/document' : '');
        }
        $version = $old['astVersion'] ?? $input['astVersion'] ?? null;
        if ($version !== null && !in_array($version, ['0.2', '1.0', '1.1', '1.2', '2.0'], true)) {
            throw new TreeError('version', $envelope ? '/document/astVersion' : '/astVersion');
        }

        return [$old, $envelope, $input];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $document
     *
     * @return array<string, mixed>
     */
    private static function layout(array $old, array $document): array
    {
        $nodes = [];
        self::layoutNode($old, $document, '', $nodes);

        return [

            'version' => 2,
            'encoding' => 'utf-8',
            'source' => '',
            'lineEndings' => 'none',
            'bom' => false,
            'nodes' => $nodes,
        ];
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param string $path
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function layoutNode(array $old, array $new, string $path, array &$nodes): void
    {
        $facts = self::layoutFacts($old);
        if ($facts !== []) {
            $nodes[] = ['path' => $path, 'startByte' => 0, 'endByte' => 0] + $facts;
        }
        if (($old['type'] ?? null) === 'table') {
            self::tableWraps($old, $new, $path, $nodes);
        }
        $before = self::nodeList($old['children'] ?? $old['items'] ?? null);
        $after = self::nodeList($new['children'] ?? null);
        foreach ($after as $index => $child) {
            if (isset($before[$index])) {
                self::layoutNode($before[$index], $child, $path . '/children/' . $index, $nodes);
            }
        }
    }

    /**
     * @param array<string, mixed> $old
     *
     * @return array<string, mixed>
     */
    private static function layoutFacts(array $old): array
    {
        $facts = [];
        $type = $old['type'] ?? null;
        $marker = self::object($old['marker'] ?? null);
        if ($type === 'list' && isset($marker['bare'])) {
            $facts['bare'] = $marker['bare'];
        }
        if ($type === 'block_quote' && isset($old['fenced'])) {
            $facts['fenced'] = $old['fenced'];
        }
        if ($type === 'table') {
            $facts += self::tableLayoutFacts($old);
        }
        if (in_array($type, ['link', 'image'], true) && is_string($old['rawRef'] ?? null)) {
            $facts['rawRef'] = $old['rawRef'];
        }
        if ($type === 'include' && is_array($old['options'] ?? null) && array_is_list($old['options'])) {
            $facts['optionSpelling'] = self::optionSpelling($old['options']);
        }

        return $facts;
    }

    /**
     * @param array<string, mixed> $old
     *
     * @return array<string, mixed>
     */
    private static function tableLayoutFacts(array $old): array
    {
        $facts = ['sourceFormat' => $old['sourceFormat'] ?? 'pipe'];
        $attrs = self::object($old['attrs'] ?? null);
        $pairs = $attrs['keyValues'] ?? null;
        if (is_array($pairs)) {
            foreach ($pairs as $pair) {
                if (
                    is_array($pair) && ($pair[0] ?? null) === 'header-rows'
                    && is_string($pair[1] ?? null) && ctype_digit($pair[1])
                ) {
                    $facts['headerRows'] = (int)$pair[1];
                }
            }
        }

        return $facts;
    }

    /**
     * @param array<mixed> $pairs
     *
     * @return array<string, string>
     */
    private static function optionSpelling(array $pairs): array
    {
        $spelling = [];
        foreach ($pairs as $pair) {
            if (is_array($pair) && is_string($pair[0] ?? null) && is_string($pair[1] ?? null)) {
                $spelling[$pair[0]] = $pair[1];
            }
        }

        return $spelling;
    }

    /**
     * @param array<string, mixed> $old
     * @param array<string, mixed> $new
     * @param string $path
     * @param array<int, array<string, mixed>> $nodes
     */
    private static function tableWraps(array $old, array $new, string $path, array &$nodes): void
    {
        $oldRows = self::nodeList($old['rows'] ?? null);
        $at = 0;
        foreach (self::nodeList($new['children'] ?? null) as $groupIndex => $group) {
            foreach (self::nodeList($group['children'] ?? null) as $rowIndex => $row) {
                $oldCells = self::nodeList($oldRows[$at]['cells'] ?? null);
                foreach (self::nodeList($row['children'] ?? null) as $cellIndex => $cell) {
                    $wraps = self::cellWraps(
                        $oldCells[$cellIndex] ?? [],
                        $path . '/children/' . $groupIndex . '/children/' . $rowIndex . '/children/' . $cellIndex,
                    );
                    if ($wraps !== []) {
                        $nodes[] = [

                            'path' => $path . '/children/' . $groupIndex . '/children/' . $rowIndex
                            . '/children/' . $cellIndex, 'startByte' => 0,
                            'endByte' => 0,
                            'wraps' => $wraps,
                        ];
                    }
                }
                $at++;
            }
        }
    }

    /**
     * @param array<string, mixed> $old
     * @param string $path
     *
     * @return array<int, array<string, mixed>>
     */
    private static function cellWraps(array $old, string $path): array
    {
        $wraps = [];
        $offset = 0;
        foreach (self::nodeList($old['children'] ?? null) as $child) {
            if (($child['type'] ?? null) === 'cell_wrap') {
                if ($offset > 0) {
                    $offset++;
                }
                $wraps[] = [

                    'line' => is_int($child['line'] ?? null) ? $child['line'] : count($wraps) + 1,
                    'path' => $path . '/children/0/children/0',
                    'offset' => $offset,
                ];
            } else {
                $offset += strlen(is_string($child['value'] ?? null) ? $child['value'] : '');
            }
        }

        return $wraps;
    }

    /**
     * @param array<string, mixed> $old
     *
     * @return array<int, array<string, mixed>>
     */
    private static function node(array $old): array
    {
        $type = $old['type'] ?? null;
        if ($type === 'critic_substitution' || $type === 'substitution') {
            return self::substitution($old);
        }
        if ($type === 'cell_wrap') {
            return [['type' => 'text', 'value' => ' '] + (isset($old['pos']) ? ['pos' => $old['pos']] : [])];
        }
        $node = $old;
        $node['type'] = match ($type) {
            'escaped_text' => 'text', 'rubyPair' => 'ruby_pair', 'heading_ref' => 'xref', default => $type,
        };
        self::slots($node, $old);
        self::fields($node, is_string($type) ? $type : '');
        self::shape($node, $old, is_string($type) ? $type : '');
        self::attrs($node);
        self::coalesce($node);

        return $type === 'figure' ? self::figure($node) : [$node];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function slots(array &$node, array $old): void
    {
        foreach (self::SLOTS as $slot) {
            if (isset($old[$slot]) && is_array($old[$slot]) && array_is_list($old[$slot])) {
                $node[$slot] = self::nodes($old[$slot]);
            }
        }
        foreach (['items', 'cells', 'inline'] as $slot) {
            if (isset($node[$slot]) && !isset($old['children'])) {
                $node['children'] = $node[$slot];
            }
            unset($node[$slot]);
        }
        $caption = self::object($old['caption'] ?? null);
        if ($caption !== null) {
            $node['caption'] = self::node($caption)[0];
        }
    }

    /**
     * @param array<string, mixed> $old
     *
     * @return array<int, array<string, mixed>>
     */
    private static function substitution(array $old): array
    {
        $previous = is_array($old['old'] ?? null) ? self::nodes($old['old'])
            : [['type' => 'text', 'value' => is_string($old['old'] ?? null) ? $old['old'] : '']];
        $next = is_array($old['new'] ?? null) ? self::nodes($old['new'])
            : [['type' => 'text', 'value' => is_string($old['new'] ?? null) ? $old['new'] : '']];
        $pos = isset($old['pos']) ? ['pos' => $old['pos']] : [];

        return [
            ['type' => 'delete', 'children' => $previous] + $pos,
            ['type' => 'insert', 'children' => $next] + $pos,
        ];
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     */
    private static function fields(array &$node, string $type): void
    {
        $map = match ($type) {
            'link', 'image' => ['form' => 'refForm'],
            'xref', 'heading_ref' => ['form' => 'display'],
            'link_reference_definition' => ['title' => 'tooltip', 'label' => 'key'],
            'code_block' => ['content' => 'value', 'lang' => 'syntax', 'header' => 'title', 'label' => 'groupLabel'],
            'raw_block', 'raw_inline', 'literal_inline', 'frontmatter', 'math' => ['content' => 'value'],
            'footnote', 'footnote_ref', 'citation_definition' => ['label' => 'key'],
            'abbreviation', 'abbreviation_def' => ['abbr' => 'key'],
            'mention' => ['user' => 'target'],
            'inline_extension' => ['content' => 'children'],
            default => [],
        };
        foreach ($map as $from => $to) {
            if (isset($node[$from]) && !isset($node[$to])) {
                $node[$to] = $node[$from];
            }
            unset($node[$from]);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     * @param string $type
     */
    private static function shape(array &$node, array $old, string $type): void
    {
        if ($type === 'document') {
            $node['astVersion'] = '2.0';
            if (is_array($node['stages'] ?? null)) {
                $node['stages'] = array_values(array_filter($node['stages'], static fn ($stage): bool => $stage !== 'blockImagePromotion'));
            }
        }
        self::containerShape($node, $old, $type);
        self::promoteImages($node);
        self::referenceShape($node, $old, $type);
        self::inlineShape($node, $old, $type);
        self::blockShape($node, $old, $type);
        self::admonition($node, $old, $type);
        self::dropOldFields($node, $type);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     * @param string $type
     */
    private static function containerShape(array &$node, array $old, string $type): void
    {
        if ($type === 'list') {
            self::listShape($node, $old);
        }
        if ($type === 'list_item') {
            self::itemShape($node, $old);
        }
        if ($type === 'line_block') {
            self::lineBlock($node);
        }
        if ($type === 'table') {
            self::table($node);
        }
        if ($type === 'table_cell') {
            self::cell($node);
        }
        if ($type === 'figure') {
            self::figureShape($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     * @param string $type
     */
    private static function admonition(array &$node, array $old, string $type): void
    {
        if (
            $type === 'admonition' && is_string($old['kind'] ?? null) && !in_array(
                $old['kind'],
                ['note', 'tip', 'warning', 'danger', 'info', 'success', 'example', 'quote'],
                true,
            )
        ) {
            $node['type'] = 'div';
            $attrs = is_array($node['attrs'] ?? null) ? $node['attrs'] : [];
            $classes = is_array($attrs['classes'] ?? null) ? $attrs['classes'] : [];
            $node['attrs'] = $attrs + ['classes' => [$old['kind'], ...$classes]];
            $node['attrs']['classes'] = [$old['kind'], ...$classes];
            unset($node['kind']);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param string $type
     */
    private static function dropOldFields(array &$node, string $type): void
    {
        foreach (['cellWraps', 'continuationLines', 'sourceContent', 'boldItalic', 'rawRef', 'status'] as $field) {
            unset($node[$field]);
        }
        if ($type === 'block_quote') {
            unset($node['fenced']);
        }
        if ($type === 'table') {
            unset($node['sourceFormat'], $node['separatorWidths']);
        }
        if ($type === 'definition_list') {
            unset($node['spacing'], $node['loose']);
        }
        if ($type === 'table_body') {
            unset($node['headRows']);
        }
        if ($type === 'citation_group') {
            unset($node['mode']);
        }
        if ($type === 'thematic_break') {
            unset($node['marker']);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     * @param string $type
     */
    private static function referenceShape(array &$node, array $old, string $type): void
    {
        if ($type === 'xref' || $type === 'heading_ref') {
            if (is_string($old['target'] ?? null)) {
                $node['key'] = Chars::fold($old['target']);
                unset($node['target']);
            }
            $node['namespace'] = 'heading';
        }
        if ($type === 'link' || $type === 'image') {
            self::linkReference($node, $old);
        }
        $namespace = match ($type) {
            'footnote', 'footnote_ref' => 'footnote',
            'citation', 'citation_definition' => 'citation',
            'abbreviation', 'abbreviation_def' => 'abbr',
            'link_reference_definition' => 'link',
            default => null,
        };
        if ($namespace !== null && is_string($node['key'] ?? null)) {
            $node['key'] = Chars::fold($node['key']);
            $node['namespace'] = $namespace;
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function linkReference(array &$node, array $old): void
    {
        $raw = is_string($old['rawRef'] ?? null) ? $old['rawRef'] : null;
        if ($raw !== null && str_ends_with($raw, '[]')) {
            $node['refForm'] ??= 'collapsed';
        }
        $key = is_string($node['key'] ?? null) ? $node['key'] : ($old['ref'] ?? null);
        if (is_string($key)) {
            $node['key'] = Chars::fold($key);
            $node['namespace'] = 'link';
            $node['refForm'] ??= 'full';
        }
        unset($node['ref'], $node['resolved']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     * @param string $type
     */
    private static function inlineShape(array &$node, array $old, string $type): void
    {
        if ($type === 'mention') {
            $node['kind'] ??= 'user';
        }
        if ($type === 'citation') {
            self::citation($node, $old);
        }
        if ($type === 'math') {
            $node['display'] = ($old['display'] ?? null) === true;
            if (is_string($old['label'] ?? null) && $node['display']) {
                $node['numberPrefix'] = $old['label'];
            }
            unset($node['label']);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function citation(array &$node, array $old): void
    {
        if (($old['suppressAuthor'] ?? null) === true) {
            $node['mode'] = 'suppress-author';
        }
        $locator = $old['locatorValue'] ?? null;
        if (isset($old['locatorLabel']) || $locator !== null) {
            $node['locator'] = [
                ...(is_string($old['locatorLabel'] ?? null) ? ['label' => $old['locatorLabel']] : []),
                'children' => is_array($locator) ? self::nodes($locator)
                    : (is_string($locator) ? [['type' => 'text', 'value' => $locator]] : []),
            ];
        }
        unset($node['suppressAuthor'], $node['locatorLabel'], $node['locatorValue']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     * @param string $type
     */
    private static function blockShape(array &$node, array $old, string $type): void
    {
        if ($type === 'paragraph') {
            self::paragraph($node, $old);
        }
        if ($type === 'comment') {
            self::comment($node, $old);
        }
        if (in_array($type, ['code_block', 'div', 'admonition', 'directive'], true) && is_string($node['title'] ?? null)) {
            $node['title'] = [['type' => 'text', 'value' => $node['title']]];
        }
        if ($type === 'include' && is_array($old['options'] ?? null) && array_is_list($old['options'])) {
            self::includeOptions($node, $old['options']);
        }
        if (
            $type === 'frontmatter' && is_string($node['value'] ?? null) && $node['value'] !== ''
            && !str_ends_with($node['value'], "\n")
        ) {
            $node['value'] .= "\n";
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function paragraph(array &$node, array $old): void
    {
        if (($old['blockImage'] ?? null) === true) {
            $node['role'] = 'image';
        }
        if (($old['blockMath'] ?? null) === true) {
            $node['role'] = 'math';
        }
        $children = self::nodeList($node['children'] ?? null);
        if (count($children) === 1 && !isset($node['role'])) {
            $child = $children[0];
            if (($child['type'] ?? null) === 'image') {
                $node['role'] = 'image';
            } elseif (($child['type'] ?? null) === 'math' && ($child['display'] ?? false) === true) {
                $node['role'] = 'math';
            }
        }
        unset($node['blockImage'], $node['blockMath']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function comment(array &$node, array $old): void
    {
        $node['value'] = $old['content'] ?? $old['value'] ?? '';
        $node['form'] = ($old['block'] ?? false) === true ? 'block' : ((($old['delimited'] ?? false) === true) ? 'braced' : 'line');
        unset($node['content'], $node['block'], $node['delimited']);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<mixed> $pairs
     */
    private static function includeOptions(array &$node, array $pairs): void
    {
        $options = [];
        foreach ($pairs as $pair) {
            if (!is_array($pair) || !is_string($pair[0] ?? null) || !is_string($pair[1] ?? null)) {
                continue;
            }
            [$name, $value] = $pair;
            $value = trim($value, "\"'");
            $options[$name] = match ($name) {
                'shift' => $value === 'auto' ? 'auto' : (int)$value,
                'code' => $value === '' ? true : $value,
                'cols' => str_contains($value, ',') ? array_map(trim(...), explode(',', $value)) : $value,
                default => $value,
            };
        }
        $node['options'] = $options;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function promoteImages(array &$node): void
    {
        if (
            !in_array($node['type'] ?? null, [
                'document', 'block_quote', 'div', 'admonition', 'directive',
                'section', 'figure', 'figure_group', 'list_item', 'definition_description', 'caption',
            ], true)
            || !is_array($node['children'] ?? null)
        ) {
            return;
        }
        foreach ($node['children'] as $at => $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'image') {
                $node['children'][$at] = ['type' => 'paragraph', 'role' => 'image', 'children' => [$child]]
                    + (isset($child['pos']) ? ['pos' => $child['pos']] : []);
            }
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function lineBlock(array &$node): void
    {
        unset($node['lines']);
        $lines = [];
        foreach (self::nodeList($node['children'] ?? null) as $block) {
            if (($block['type'] ?? null) !== 'paragraph') {
                continue;
            }
            if ($lines !== []) {
                $lines[] = ['type' => 'line', 'children' => []];
            }
            $content = [];
            foreach (self::nodeList($block['children'] ?? null) as $child) {
                if (in_array($child['type'] ?? null, ['hard_break', 'soft_break'], true)) {
                    $lines[] = ['type' => 'line', 'children' => $content];
                    $content = [];
                } else {
                    $content[] = $child;
                }
            }
            $lines[] = ['type' => 'line', 'children' => $content];
        }
        $node['children'] = $lines;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function cell(array &$node): void
    {
        $content = is_array($node['children'] ?? null) ? $node['children'] : [];
        $first = $content[0] ?? null;
        if ($content === [] || (is_array($first) && in_array($first['type'] ?? null, ['paragraph', 'cell_paragraph'], true))) {
            return;
        }
        $node['children'] = [['type' => 'cell_paragraph', 'children' => $content]];
        self::coalesce($node['children'][0]);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function table(array &$node): void
    {
        $rows = $node['rows'] ?? null;
        if (!is_array($rows)) {
            return;
        }
        self::tableColumns($node, $rows);
        $head = 0;
        foreach ($rows as $row) {
            $cells = is_array($row) ? self::nodeList($row['children'] ?? null) : [];
            if (
                $cells === [] || !array_reduce(
                    $cells,
                    static fn (bool $all, array $cell): bool => $all && ($cell['header'] ?? false) === true,
                    true,
                )
            ) {
                break;
            }
            $head++;
        }
        $groups = [];
        if ($head > 0) {
            $groups[] = ['type' => 'table_head', 'children' => array_slice($rows, 0, $head)];
        }
        if ($head < count($rows)) {
            $groups[] = ['type' => 'table_body', 'children' => array_slice($rows, $head)];
        }
        $node['children'] = $groups;
        unset($node['rows'], $node['rowGroups']);
        self::tableAttrs($node);
    }

    /**
     * @param array<string, mixed> $node
     * @param array<mixed> $rows
     */
    private static function tableColumns(array &$node, array $rows): void
    {
        $width = 0;
        foreach ($rows as $row) {
            if (is_array($row) && is_array($row['children'] ?? null)) {
                $width = max($width, count($row['children']));
            }
        }
        $columns = is_array($node['columns'] ?? null) ? $node['columns'] : [];
        for ($at = count($columns); $at < $width; $at++) {
            $columns[] = ['type' => 'table_column'];
        }
        $node['columns'] = $columns;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function tableAttrs(array &$node): void
    {
        $attrs = $node['attrs'] ?? null;
        if (!is_array($attrs) || !is_array($attrs['keyValues'] ?? null)) {
            return;
        }
        $names = ['header-rows', 'footer-rows', 'row-head-columns', 'aligns', 'widths', 'table'];
        $attrs['keyValues'] = array_values(array_filter(
            $attrs['keyValues'],
            static fn ($pair): bool => !is_array($pair) || !in_array($pair[0] ?? null, $names, true),
        ));
        if ($attrs['keyValues'] === []) {
            unset($attrs['keyValues']);
        }
        if ($attrs === []) {
            unset($node['attrs']);
        } else {
            $node['attrs'] = $attrs;
        }
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function figureShape(array &$node): void
    {
        if (is_array($node['shortCaption'] ?? null) && is_array($node['caption'] ?? null)) {
            $node['caption']['short'] = $node['shortCaption'];
        }
        unset($node['shortCaption']);
        $kind = self::object($node['kind'] ?? null);
        if ($kind !== null && isset($kind['type'])) {
            $node['children'] = [self::node($kind)[0]];
        }
        unset($node['kind'], $node['target']);
    }

    /**
     * @param array<string, mixed> $node
     *
     * @return array<int, array<string, mixed>>
     */
    private static function figure(array $node): array
    {
        $children = self::nodeList($node['children'] ?? null);
        $first = $children[0] ?? null;
        $rest = array_slice($children, 1);
        $captionable = is_array($first) && (in_array($first['type'] ?? null, ['code_block', 'block_quote'], true)
            || (($first['type'] ?? null) === 'paragraph' && in_array($first['role'] ?? null, ['image', 'math'], true)));
        if (!$captionable || array_filter($rest, static fn ($child): bool => ($child['type'] ?? null) !== 'comment') !== []) {
            $node['type'] = 'figure_group';

            return [$node];
        }
        foreach (['caption', 'attrs', 'lang', 'dir', 'pos'] as $field) {
            if (isset($node[$field])) {
                $first[$field] = $node[$field];
            }
        }
        if (($first['type'] ?? null) === 'code_block') {
            return [$first, ...$rest];
        }
        self::figureComments($first, $rest);

        return [$first];
    }

    /**
     * @param array<string, mixed> $first
     * @param array<int, array<string, mixed>> $rest
     */
    private static function figureComments(array &$first, array $rest): void
    {
        $inner = self::nodeList($first['children'] ?? null);
        if (($first['type'] ?? null) === 'paragraph') {
            foreach ($rest as $comment) {
                $inner[] = ['type' => 'soft_break'];
                $inner[] = $comment;
            }
        } else {
            array_push($inner, ...$rest);
        }
        $first['children'] = $inner;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function listShape(array &$node, array $old): void
    {
        $marker = is_array($node['marker'] ?? null) ? $node['marker'] : null;
        if ($marker === null) {
            $marker = ($old['ordered'] ?? false) === true
                ? ['kind' => 'ordered', 'style' => $old['olType'] ?? '1', 'delimiter' => $old['delim'] ?? '.']
                : ['kind' => 'bullet', 'char' => $old['bulletChar'] ?? '-'];
            $node['spacing'] = ($old['tight'] ?? true) === false ? 'loose' : 'tight';
        }
        if (
            ($marker['kind'] ?? null) === 'ordered' && is_int($old['start'] ?? null)
            && $old['start'] >= 0 && $old['start'] <= 999999999 && $old['start'] !== 1
        ) {
            $marker['start'] = $old['start'];
        }
        unset($marker['bare']);
        $node['marker'] = $marker;
        unset(
            $node['ordered'],
            $node['tight'],
            $node['olType'],
            $node['delim'],
            $node['bulletChar'],
            $node['start'],
            $node['bareMarker'],
        );
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function itemShape(array &$node, array $old): void
    {
        $task = is_array($node['task'] ?? null) ? $node['task'] : null;
        if ($task === null && array_key_exists('checked', $old)) {
            $task = ['mark' => $old['checked'] ? 'x' : ' '];
        }
        if (isset($old['taskState'])) {
            $task = ['mark' => $old['taskState']];
        }
        if ($task !== null) {
            unset($task['status']);
            $node['task'] = $task;
        }
        unset($node['checked'], $node['taskState']);
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function attrs(array &$node): void
    {
        $attrs = $node['attrs'] ?? null;
        if (!is_array($attrs) || !is_array($attrs['keyValues'] ?? null) || array_is_list($attrs['keyValues'])) {
            return;
        }
        $values = $attrs['keyValues'];
        $order = is_array($attrs['order'] ?? null) ? $attrs['order'] : [];
        $names = [];
        foreach ([...$order, ...array_keys($values)] as $name) {
            if (is_string($name) && !in_array($name, $names, true)) {
                $names[] = $name;
            }
        }
        $pairs = [];
        foreach ($names as $name) {
            if (!is_string($values[$name] ?? null)) {
                continue;
            }
            if ($name === 'id') {
                $attrs['id'] = $values[$name];
            } elseif ($name === 'class') {
                $attrs['classes'] = [$values[$name]];
            } else {
                $pairs[] = [$name, $values[$name]];
            }
        }
        if ($pairs === []) {
            unset($attrs['keyValues']);
        } else {
            $attrs['keyValues'] = $pairs;
        }
        unset($attrs['order']);
        $node['attrs'] = $attrs;
    }

    /**
     * @param array<mixed> $items
     *
     * @return array<int, array<string, mixed>>
     */
    private static function nodes(array $items): array
    {
        $out = [];
        foreach ($items as $item) {
            $node = self::object($item);
            if ($node !== null && is_string($node['type'] ?? null)) {
                array_push($out, ...self::node($node));
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function object(mixed $value): ?array
    {
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            return null;
        }
        $out = [];
        foreach ($value as $key => $item) {
            if (!is_string($key)) {
                return null;
            }
            $out[$key] = $item;
        }

        return $out;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function nodeList(mixed $value): array
    {
        if (!is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $node = self::object($item);
            if ($node !== null) {
                $out[] = $node;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $node
     */
    private static function coalesce(array &$node): void
    {
        if (!isset($node['children']) || !is_array($node['children'])) {
            return;
        }
        $out = [];
        foreach ($node['children'] as $child) {
            $last = count($out) - 1;
            $previous = $out[$last] ?? null;
            if (
                is_array($child) && is_array($previous) && ($child['type'] ?? null) === 'text'
                && ($previous['type'] ?? null) === 'text' && is_string($child['value'] ?? null)
                && is_string($previous['value'] ?? null)
            ) {
                $out[$last] = [...$previous, 'value' => $previous['value'] . $child['value']];
            } else {
                $out[] = $child;
            }
        }
        $node['children'] = $out;
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Json;

use JsonException;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Inline\Chars;
use Throwable;

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
        $current = ($old['astVersion'] ?? null) === '2.0' && self::validCurrent($old);
        $document = $current ? $old : self::node($old)[0];
        $diagnostics = [];
        if (!$current) {
            $document['astVersion'] = '2.0';
            $json = self::positions($document, is_string($options['source'] ?? null) ? $options['source'] : null, $diagnostics);
            $json ??= self::encoded($document);
            Carve::decodeTree($json);
        }
        $source = is_string($options['source'] ?? null) ? $options['source'] : '';
        $layout = self::layout($old, $document);
        if (is_string($options['source'] ?? null)) {
            $parsed = Carve::parseWithLayout($source);
            $parsedDocument = json_decode(Carve::encodeTree($parsed->tree), true, 4096, JSON_THROW_ON_ERROR);
            if (TreeEquality::equal($document, $parsedDocument)) {
                $layout = $parsed->layout;
            }
        }
        $result = ['document' => $document, 'layout' => $layout, 'diagnostics' => $diagnostics];
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
     * @param array<string, mixed> $document
     */
    private static function validCurrent(array $document): bool
    {
        try {
            Carve::validateTree($document);

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * @param array<mixed, mixed> $document
     * @param string|null $source
     * @param array<int, array<string, string>> $diagnostics
     */
    private static function positions(array &$document, ?string $source, array &$diagnostics): ?string
    {
        if ($source === null) {
            $encoded = self::encoded($document);
            if (!str_contains($encoded, '"pos"') || preg_match('/[^\x00-\x7f]/', $encoded) !== 1) {
                return $encoded;
            }
        }
        $offsets = null;
        if ($source !== null) {
            $offsets = [0];
            $bytes = 0;
            foreach (preg_split('//u', $source, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $character) {
                $bytes += strlen($character);
                $offsets[] = $bytes;
            }
        }
        self::visitPositions($document, '', $offsets, $diagnostics);

        return null;
    }

    /**
     * @param array<mixed, mixed> $document
     */
    private static function encoded(array $document): string
    {
        return json_encode($document, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
            | JSON_UNESCAPED_LINE_TERMINATORS | JSON_INVALID_UTF8_SUBSTITUTE) ?: '';
    }

    /**
     * @param array<mixed, mixed> $node
     * @param string $path
     * @param array<int, int>|null $offsets
     * @param array<int, array<string, string>> $diagnostics
     */
    private static function visitPositions(array &$node, string $path, ?array $offsets, array &$diagnostics): void
    {
        self::convertPosition($node, $path, $offsets, $diagnostics);
        foreach ($node as $key => &$child) {
            if (in_array($key, ['pos', 'data', 'payload', 'props'], true) || !is_array($child)) {
                continue;
            }
            if (array_is_list($child)) {
                foreach ($child as $index => &$entry) {
                    if (is_array($entry)) {
                        self::visitPositions($entry, $path . '/' . $key . '/' . $index, $offsets, $diagnostics);
                    }
                }
                unset($entry);
            } else {
                self::visitPositions($child, $path . '/' . $key, $offsets, $diagnostics);
            }
        }
        unset($child);
    }

    /**
     * @param array<mixed, mixed> $node
     * @param string $path
     * @param array<int, int>|null $offsets
     * @param array<int, array<string, string>> $diagnostics
     */
    private static function convertPosition(array &$node, string $path, ?array $offsets, array &$diagnostics): void
    {
        if (!isset($node['type']) || !is_array($node['pos'] ?? null)) {
            return;
        }
        $position = $node['pos'];
        $startOffset = $position['startOffset'] ?? null;
        $endOffset = $position['endOffset'] ?? null;
        $start = is_int($startOffset) ? ($offsets[$startOffset] ?? null) : null;
        $end = is_int($endOffset) ? ($offsets[$endOffset] ?? null) : null;
        if ($start !== null && $end !== null) {
            $node['pos']['startOffset'] = $start;
            $node['pos']['endOffset'] = $end;

            return;
        }
        unset($node['pos']);
        if ($diagnostics === []) {
            $diagnostics[] = [
                'code' => 'migration-loss',
                'severity' => 'warning',
                'channel' => 'render-loss',
                'path' => $path . '/pos',
                'message' => $offsets === null
                    ? 'positions dropped: 0.1 offsets count code points and no source was given to count bytes'
                    : 'positions dropped: an offset lies past the end of the source',
            ];
        }
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
            try {
                $input = json_decode($input, true, 4096, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                throw new TreeError('schema', '');
            }
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
        $simple = self::simpleNode($old);
        if ($simple !== null) {
            return [$simple];
        }
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
        self::attrs($node);
        self::shape($node, $old, is_string($type) ? $type : '');
        self::coalesce($node);

        return $type === 'figure' ? self::figure($node) : [$node];
    }

    /**
     * @param array<string, mixed> $old
     *
     * @return array<string, mixed>|null
     */
    private static function simpleNode(array $old): ?array
    {
        $type = $old['type'] ?? null;
        if (
            $type === 'text' && isset($old['value']) && !isset($old['attrs'])
            && count($old) === (isset($old['pos']) ? 3 : 2)
        ) {
            return $old;
        }
        if (
            $type === 'paragraph' && is_array($old['children'] ?? null)
            && count($old) === (isset($old['pos']) ? 3 : 2)
            && count($old['children']) === 1 && is_array($old['children'][0])
            && ($old['children'][0]['type'] ?? null) === 'text'
        ) {
            $old['children'] = self::node($old['children'][0]);

            return $old;
        }

        return null;
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
        } elseif (is_array($old['caption'] ?? null) && array_is_list($old['caption'])) {
            $node['caption'] = [

                'type' => 'caption',
                'children' => [
                    ['type' => 'paragraph', 'children' => self::nodes($old['caption'])],
                ],
            ];
        }
        $target = self::object($old['target'] ?? null);
        if (($old['type'] ?? null) === 'figure' && $target !== null) {
            $node['kind'] = self::node($target)[0];
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
            'link', 'image' => ['form' => 'refForm', 'title' => 'tooltip'],
            'xref', 'heading_ref' => ['form' => 'display'],
            'link_reference_definition' => ['title' => 'tooltip', 'label' => 'key'],
            'code_block' => ['content' => 'value', 'lang' => 'syntax', 'header' => 'title', 'label' => 'groupLabel'],
            'div', 'directive', 'admonition' => ['label' => 'groupLabel'],
            'raw_block', 'raw_inline', 'literal_inline', 'frontmatter', 'math' => ['content' => 'value'],
            'critic_comment' => ['text' => 'value'],
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
            if (is_array($node['children'] ?? null) && $node['children'] !== [] && self::allPositioned($node['children'])) {
                usort($node['children'], static fn ($a, $b): int => self::nodeStart($a) <=> self::nodeStart($b));
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
     * @param array<mixed> $children
     */
    private static function allPositioned(array $children): bool
    {
        foreach ($children as $child) {
            if (!is_array($child) || !is_array($child['pos'] ?? null)) {
                return false;
            }
        }

        return true;
    }

    private static function nodeStart(mixed $node): int
    {
        $position = is_array($node) ? ($node['pos'] ?? null) : null;

        return is_array($position) && is_int($position['startOffset'] ?? null) ? $position['startOffset'] : 0;
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
            self::table($node, $old);
        }
        if ($type === 'table_cell') {
            self::cell($node, $old);
        }
        if ($type === 'smart_punctuation') {
            self::punctuation($node, $old);
        }
        if ($type === 'figure') {
            self::figureShape($node);
        }
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function punctuation(array &$node, array $old): void
    {
        $kind = $node['kind'] ?? null;
        $node['source'] = is_string($old['value'] ?? null) ? $old['value'] : '';
        if (is_string($kind) && isset(Tables::SMART_SOURCES[$kind])) {
            $node['source'] = Tables::SMART_SOURCES[$kind];
        }
        if (is_string($kind) && isset(Tables::SMART_GLYPHS[$kind])) {
            $node['glyph'] = Tables::SMART_GLYPHS[$kind];
        } else {
            foreach (Tables::SMART_RUNS as [$name, $glyph]) {
                if ($name === $kind) {
                    $node['glyph'] = $glyph;

                    break;
                }
            }
        }
        unset($node['value']);
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
        if ($type === 'autolink' && ($node['text'] ?? null) === ($node['href'] ?? null)) {
            unset($node['text']);
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
        if (($old['type'] ?? null) === 'image' && $raw !== null && str_starts_with($raw, '!')) {
            $raw = substr($raw, 1);
        }
        $collapsed = self::collapsedKey($raw);
        if ($collapsed !== null) {
            $node['refForm'] ??= 'collapsed';
        }
        $key = $collapsed ?? (is_string($node['key'] ?? null) ? $node['key'] : ($old['ref'] ?? null));
        if (is_string($key)) {
            $node['key'] = Chars::fold($key);
            $node['namespace'] = 'link';
            $node['refForm'] ??= 'full';
            unset($node['tooltip']);
            self::emptyDestination($node, $old);
        }
        unset($node['ref'], $node['resolved']);
    }

    private static function collapsedKey(?string $raw): ?string
    {
        if ($raw === null || !str_ends_with($raw, '][]')) {
            return null;
        }
        $at = strrpos($raw, '][]');

        return $at !== false && $at > 0 ? substr($raw, 1, $at - 1) : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function emptyDestination(array &$node, array $old): void
    {
        if (($old['resolved'] ?? null) === true) {
            return;
        }
        if (($node['href'] ?? null) === '') {
            unset($node['href']);
        }
        if (($node['src'] ?? null) === '') {
            unset($node['src']);
        }
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
        if ($type === 'code_block' && is_array($node['title'] ?? null)) {
            self::codeTitle($node);
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
     */
    private static function codeTitle(array &$node): void
    {
        $attrs = self::object($node['attrs'] ?? null);
        if ($attrs === null || !is_array($attrs['keyValues'] ?? null)) {
            return;
        }
        if (array_is_list($attrs['keyValues'])) {
            $attrs['keyValues'] = array_values(array_filter(
                $attrs['keyValues'],
                static fn ($pair): bool => !is_array($pair) || ($pair[0] ?? null) !== 'title',
            ));
        } else {
            unset($attrs['keyValues']['title']);
        }
        if ($attrs['keyValues'] === []) {
            unset($attrs['keyValues']);
        }
        if ($attrs === [] || (array_keys($attrs) === ['order'])) {
            unset($node['attrs']);
        } else {
            $node['attrs'] = $attrs;
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
            $role = self::singleChildRole($children[0]);
            if ($role !== null) {
                $node['role'] = $role;
            }
        }
        unset($node['blockImage'], $node['blockMath']);
    }

    /**
     * @param array<string, mixed> $child
     */
    private static function singleChildRole(array $child): ?string
    {
        if (($child['type'] ?? null) === 'math' && ($child['display'] ?? false) === true && ($child['value'] ?? '') !== '') {
            return 'math';
        }
        if (($child['type'] ?? null) !== 'image') {
            return null;
        }
        $pos = self::object($child['pos'] ?? null);

        return $pos === null || ($pos['startLine'] ?? null) === ($pos['endLine'] ?? null) ? 'image' : null;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function comment(array &$node, array $old): void
    {
        $node['value'] = $old['content'] ?? $old['value'] ?? '';
        $node['form'] = $old['form'] ?? (($old['block'] ?? false) === true
            ? 'block' : ((($old['delimited'] ?? false) === true) ? 'braced' : 'line'));
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
        $children = self::nodeList($node['children'] ?? null);
        if (
            $children !== [] && array_reduce(
                $children,
                static fn (bool $all, array $child): bool => $all && ($child['type'] ?? null) === 'line',
                true,
            )
        ) {
            return;
        }
        $lines = [];
        foreach ($children as $block) {
            if (($block['type'] ?? null) === 'comment') {
                $lines[] = self::line([$block]);

                continue;
            }
            if (($block['type'] ?? null) !== 'paragraph') {
                continue;
            }
            if ($lines !== []) {
                $lines[] = ['type' => 'line', 'children' => []];
            }
            $content = [];
            $inline = self::nodeList($block['children'] ?? null);
            foreach ($inline as $at => $child) {
                if (in_array($child['type'] ?? null, ['hard_break', 'soft_break'], true) && $at < count($inline) - 1) {
                    $lines[] = self::line($content);
                    $content = [];
                } else {
                    $content[] = $child;
                }
            }
            $lines[] = self::line($content);
        }
        $node['children'] = $lines;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     *
     * @return array<string, mixed>
     */
    private static function line(array $children): array
    {
        $line = ['type' => 'line', 'children' => self::lineContents($children)];
        $first = self::object($children[0]['pos'] ?? null);
        $last = self::object($children[count($children) - 1]['pos'] ?? null);
        if ($first !== null && $last !== null) {
            $line['pos'] = [...$first, 'endLine' => $last['endLine'], 'endColumn' => $last['endColumn'], 'endOffset' => $last['endOffset']];
        }

        return $line;
    }

    /**
     * @param array<int, array<string, mixed>> $children
     *
     * @return array<int, array<string, mixed>>
     */
    private static function lineContents(array $children): array
    {
        $count = 0;
        while (($children[$count]['type'] ?? null) === 'non_breaking_space') {
            $count++;
        }
        if ($count === 0) {
            return $children;
        }
        $next = $children[$count] ?? null;
        $hasText = ($next['type'] ?? null) === 'text';
        $end = $count + ($hasText ? 1 : 0);
        $text = [

            'type' => 'text',
            'value' => str_repeat(' ', $count)
            . ($hasText && is_string($next['value'] ?? null) ? $next['value'] : ''),
        ];
        $beginPos = self::object($children[0]['pos'] ?? null);
        $endPos = self::object($children[$end - 1]['pos'] ?? null);
        if ($beginPos !== null && $endPos !== null) {
            $text['pos'] = [
                ...$beginPos,
                'endLine' => $endPos['endLine'],
                'endColumn' => $endPos['endColumn'],
                'endOffset' => $endPos['endOffset'],
            ];
        }

        return [$text, ...array_slice($children, $end)];
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function cell(array &$node, array $old): void
    {
        $content = self::nodeList($node['children'] ?? null);
        $leading = self::leadingWraps($old);
        if ($leading > 0) {
            $content = array_slice($content, $leading);
        }
        $holder = ['children' => $content];
        self::coalesce($holder);
        $content = self::nodeList($holder['children'] ?? null);
        $node['children'] = $content;
        if ($content !== [] && isset($node['span'])) {
            unset($node['span']);
        }
        $first = $content[0] ?? null;
        if (isset($node['span']) && $content === []) {
            return;
        }
        if (is_array($first) && in_array($first['type'] ?? null, ['paragraph', 'cell_paragraph'], true)) {
            return;
        }
        $wrapped = ['type' => 'cell_paragraph', 'children' => $content];
        $begin = self::object($content[0]['pos'] ?? null);
        $end = self::object($content[count($content) - 1]['pos'] ?? null);
        if ($begin !== null && $end !== null) {
            $wrapped['pos'] = [...$begin, 'endLine' => $end['endLine'], 'endColumn' => $end['endColumn'], 'endOffset' => $end['endOffset']];
        }
        $node['children'] = [$wrapped];
        self::coalesce($node['children'][0]);
    }

    /**
     * @param array<string, mixed> $old
     */
    private static function leadingWraps(array $old): int
    {
        $count = 0;
        foreach (self::nodeList($old['children'] ?? null) as $child) {
            if (($child['type'] ?? null) !== 'cell_wrap') {
                break;
            }
            $count++;
        }

        return $count;
    }

    /**
     * @param array<string, mixed> $node
     * @param array<string, mixed> $old
     */
    private static function table(array &$node, array $old): void
    {
        if (!is_array($node['rows'] ?? null)) {
            return;
        }
        $rows = self::nodeList($node['rows']);
        self::tableColumns($node, $rows);
        $head = 0;
        foreach ($rows as $row) {
            $cells = self::nodeList($row['children'] ?? null);
            $visible = array_values(array_filter($cells, static fn (array $cell): bool => !isset($cell['span'])));
            if (
                $visible === [] || !array_reduce(
                    $visible,
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
            $groups[] = self::tableGroup('table_head', array_slice($rows, 0, $head));
        }
        if ($head < count($rows)) {
            $groups[] = self::tableGroup('table_body', array_slice($rows, $head));
        }
        $explicit = self::explicitTableGroups($rows, $old);
        $node['children'] = $explicit ?? $groups;
        self::normalizeSpans($node['children']);
        unset($node['rows'], $node['rowGroups']);
        self::tableAttrs($node);
    }

    /**
     * @param array<int, array<string, mixed>> $groups
     */
    private static function normalizeSpans(array &$groups): void
    {
        foreach ($groups as $groupAt => $group) {
            $rows = self::nodeList($group['children'] ?? null);
            foreach ($rows as $rowAt => $row) {
                $cells = self::nodeList($row['children'] ?? null);
                foreach ($cells as $columnAt => $cell) {
                    $above = self::nodeList($rows[$rowAt - 1]['children'] ?? null)[$columnAt] ?? null;
                    $footRow = $rowAt === 0 && ($group['type'] ?? null) === 'table_foot';
                    if (!self::spanJoinsNothing($cell, $cells[$columnAt - 1] ?? null, $above, $footRow)) {
                        continue;
                    }
                    $mark = $footRow && ($cell['span'] ?? null) === 'rowspan'
                        ? [['type' => 'text', 'value' => '^']] : [];
                    unset($cell['span']);
                    $cell['children'] = [['type' => 'cell_paragraph', 'children' => $mark]];
                    $cells[$columnAt] = $cell;
                }
                $rows[$rowAt]['children'] = $cells;
            }
            self::recountRowspans($rows);
            $groups[$groupAt]['children'] = $rows;
        }
    }

    /**
     * @param array<string, mixed> $cell
     * @param array<string, mixed>|null $left
     * @param array<string, mixed>|null $above
     * @param bool $footRow
     */
    private static function spanJoinsNothing(array $cell, ?array $left, ?array $above, bool $footRow): bool
    {
        $span = $cell['span'] ?? null;
        if ($footRow && $span === 'rowspan') {
            return true;
        }
        if ($span === 'colspan') {
            return $left === null || ($left['span'] ?? null) === 'rowspan';
        }

        return $span === 'rowspan' && ($above === null || ($above['span'] ?? null) === 'colspan'
            || ($above['colspan'] ?? 1) > 1);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    private static function recountRowspans(array &$rows): void
    {
        foreach ($rows as $rowAt => $row) {
            $cells = self::nodeList($row['children'] ?? null);
            foreach ($cells as $columnAt => $cell) {
                if (!is_int($cell['rowspan'] ?? null)) {
                    continue;
                }
                $count = 1;
                while ((self::nodeList($rows[$rowAt + $count]['children'] ?? null)[$columnAt]['span'] ?? null) === 'rowspan') {
                    $count++;
                }
                if ($count > 1) {
                    $cell['rowspan'] = $count;
                } else {
                    unset($cell['rowspan']);
                }
                $cells[$columnAt] = $cell;
            }
            $rows[$rowAt]['children'] = $cells;
        }
    }

    /**
     * @param string $type
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, mixed>
     */
    private static function tableGroup(string $type, array $rows): array
    {
        return ['type' => $type, 'children' => $rows] + (isset($rows[0]['pos']) ? ['pos' => $rows[0]['pos']] : []);
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @param array<string, mixed> $old
     *
     * @return array<int, array<string, mixed>>|null
     */
    private static function explicitTableGroups(array $rows, array $old): ?array
    {
        $declared = self::object($old['rowGroups'] ?? null);
        if ($declared === null) {
            return null;
        }
        $groups = [];
        $at = 0;
        $add = static function (string $type, int $count, array $fields = []) use (&$groups, &$at, $rows): void {
            $taken = array_slice($rows, $at, $count);
            $at += $count;
            if ($taken !== []) {
                $group = self::tableGroup($type, $taken);
                if (isset($fields['attrs'])) {
                    $group['attrs'] = $fields['attrs'];
                    self::attrs($group);
                    unset($fields['attrs']);
                }
                $groups[] = $group + $fields;
            }
        };
        $add(
            'table_head',
            self::groupCount($declared['headRows'] ?? null),
            isset($declared['headAttrs']) ? ['attrs' => $declared['headAttrs']] : [],
        );
        foreach (is_array($declared['bodies'] ?? null) ? $declared['bodies'] : [] as $body) {
            if (!is_array($body) || array_is_list($body)) {
                continue;
            }
            $add(
                'table_body',
                self::groupCount($body['headRows'] ?? null) + self::groupCount($body['bodyRows'] ?? null),
                array_intersect_key($body, array_flip(['rowHeadColumns', 'attrs'])),
            );
        }
        $add(
            'table_foot',
            self::groupCount($declared['footRows'] ?? null),
            isset($declared['footAttrs']) ? ['attrs' => $declared['footAttrs']] : [],
        );
        $add('table_body', count($rows) - $at);

        return $groups;
    }

    private static function groupCount(mixed $value): int
    {
        return is_int($value) && $value > 0 ? $value : 0;
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
        $oldColumns = is_array($node['columns'] ?? null) ? $node['columns'] : [];
        $columns = [];
        $columnCount = max(count($oldColumns), $width);
        for ($at = 0; $at < $columnCount; $at++) {
            $column = ['type' => 'table_column'];
            $old = self::object($oldColumns[$at] ?? null);
            if ($old !== null) {
                $column += array_intersect_key($old, array_flip(['align', 'valign', 'width']));
            }
            $columns[] = $column;
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
        self::listStart($node, $marker, $old);
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
     * @param array<string, mixed> $marker
     * @param array<string, mixed> $old
     */
    private static function listStart(array &$node, array &$marker, array $old): void
    {
        if (($marker['kind'] ?? null) !== 'ordered') {
            return;
        }
        if (is_int($old['start'] ?? null) && $old['start'] >= 0 && $old['start'] <= 999999999 && $old['start'] !== 1) {
            $marker['start'] = $old['start'];
        }
        $attrs = self::object($node['attrs'] ?? null);
        if ($attrs === null || !is_array($attrs['keyValues'] ?? null)) {
            return;
        }
        $pairs = $attrs['keyValues'];
        foreach ($pairs as $at => $pair) {
            if (!is_array($pair) || ($pair[0] ?? null) !== 'start') {
                continue;
            }
            $value = $pair[1] ?? null;
            if (is_string($value) && ctype_digit($value) && (int)$value <= 999999999 && (int)$value !== 1) {
                $marker['start'] = (int)$value;
            }
            array_splice($pairs, $at, 1);
            if ($pairs === []) {
                unset($attrs['keyValues']);
            } else {
                $attrs['keyValues'] = $pairs;
            }
            if ($attrs === []) {
                unset($node['attrs']);
            } else {
                $node['attrs'] = $attrs;
            }

            return;
        }
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
        if (!is_array($attrs)) {
            return;
        }
        if (is_array($attrs['keyValues'] ?? null) && array_is_list($attrs['keyValues'])) {
            unset($attrs['order']);
            $node['attrs'] = $attrs;

            return;
        }
        if (!is_array($attrs['keyValues'] ?? null)) {
            unset($attrs['order']);
            if ($attrs === []) {
                unset($node['attrs']);
            } else {
                $node['attrs'] = $attrs;
            }

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
        $pairs = self::attributePairs($attrs, $values, $names);
        if ($pairs === []) {
            unset($attrs['keyValues']);
        } else {
            $attrs['keyValues'] = $pairs;
        }
        unset($attrs['order']);
        $node['attrs'] = $attrs;
    }

    /**
     * @param array<string, mixed> $attrs
     * @param array<mixed, mixed> $values
     * @param array<int, string> $names
     *
     * @return array<int, array<int, string>>
     */
    private static function attributePairs(array &$attrs, array $values, array $names): array
    {
        $pairs = [];
        $indices = [];
        foreach ($names as $name) {
            $value = $values[$name] ?? null;
            if (!is_string($value)) {
                continue;
            }
            $folded = strtolower($name);
            if ($folded === 'id') {
                $attrs['id'] = $value;
            } elseif ($folded === 'class') {
                $attrs['classes'] = [$value];
            } elseif (isset($indices[$folded])) {
                $pairs[$indices[$folded]][1] = $value;
            } else {
                $indices[$folded] = count($pairs);
                $pairs[] = [$name, $value];
            }
        }

        return $pairs;
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
                if (is_array($previous['pos'] ?? null) && is_array($child['pos'] ?? null)) {
                    $out[$last]['pos'] = [
                        ...$previous['pos'],
                        'endLine' => $child['pos']['endLine'],
                        'endColumn' => $child['pos']['endColumn'],
                        'endOffset' => $child['pos']['endOffset'],
                    ];
                }
            } else {
                $out[] = $child;
            }
        }
        $node['children'] = $out;
    }
}

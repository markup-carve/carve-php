<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Node;

/**
 * PART 0 S1 (carve#5012): a one-line `%%` comment or link/abbreviation
 * definition in ordinary block content is erased before layout. The ownership
 * walk classifies the lines, the visible stream is parsed, and positions,
 * erased definitions and erased comments are put back against the authored
 * source.
 */
trait TransparentLineErasure
{
    private const TRANSPARENT_QUOTE_PREFIX = '/^(?:[ \t]*>[ ]?)+/';

    private const TRANSPARENT_REGION_MARKER = '/^%%[ \t]*#(?:end)?region(?![A-Za-z0-9_])/';

    /**
     * Definitions written on erased lines, registered before the visible parse.
     *
     * @var array{references: array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>, abbreviations: array<int, array{0: string, 1: string}>}|null
     */
    private ?array $transparentInjection = null;

    /**
     * Authored line indexes the ownership walk leaves to an erasable leaf.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, array{owner: string, comment: bool}>
     */
    private function transparentErasedLines(array $lines, string $input): array
    {
        if (!str_contains($input, '%%') && !str_contains($input, ']:')) {
            return [];
        }
        $walk = $this->newOwnershipWalk();
        $walk->trace = [];
        $walk->own($lines, PlusLetter::for($input));
        $erased = [];
        foreach ($walk->trace as $record) {
            if ($record['form'] !== 'leaf' || $record['step'] !== 'S5') {
                continue;
            }
            $index = (int)$record['line'] - 1;
            $rest = rtrim((string)preg_replace('/^(?:[ \t]*>[ ]?)*[ \t]*/', '', $lines[$index] ?? ''), " \t");
            $comment = str_starts_with($rest, '%%');
            if ($comment) {
                if (str_starts_with($rest, '%%%') || preg_match(self::TRANSPARENT_REGION_MARKER, $rest) === 1) {
                    continue;
                }
            } elseif (
                preg_match(self::FOOTNOTE_DEFINITION_PATTERN, $rest) === 1
                || ($this->referenceDefinitionExtractor->matchDefinitionLine($rest) === null
                    && preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $rest) !== 1)
            ) {
                continue;
            }
            $erased[$index] = ['owner' => (string)$record['owner'], 'comment' => $comment];
        }
        ksort($erased);

        return $erased;
    }

    /**
     * @param array<int, array{owner: string, comment: bool}> $erased
     */
    private function parseTransparentDocument(string $input, array $erased, int $sourceLength): Document
    {
        $traced = $this->traceNext;
        $this->traceNext = false;
        $positions = $this->state->source->trackPositions;
        $this->state->source->trackPositions = true;
        try {
            $authored = $this->parseDocumentCore($input, false);
            $references = $this->state->session->references;
            $lines = new SourceLines($input, $input);

            $injection = ['references' => [], 'abbreviations' => []];
            foreach ($references as $key => $definition) {
                if (isset($erased[$definition->line])) {
                    $injection['references'][(string)$key] = new ReferenceDefinition(
                        $definition->url,
                        $definition->attributes,
                        -$definition->line - 1,
                        $definition->title,
                        false,
                        $definition->rawLabel,
                    );
                }
            }
            $restored = [];
            $definitionSpans = [];
            $this->collectTransparentNodes($authored, $erased, $restored, $definitionSpans);
            foreach ($restored as $node) {
                if ($node instanceof AbbreviationDefinition) {
                    $injection['abbreviations'][$node->getPos()->startLine - 1] = [$node->getAbbr(), $node->getExpansion()];
                }
            }
            foreach ($erased as $index => $_) {
                $rest = rtrim((string)preg_replace('/^(?:[ \t]*>[ ]?)*[ \t]*/', '', $lines->lines[$index]), " \t");
                if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $rest, $m) === 1 && !isset($injection['abbreviations'][$index])) {
                    $injection['abbreviations'][$index] = [$m[1], rtrim($m[2], " \t")];
                }
            }
            ksort($injection['abbreviations']);

            [$visible, $map] = $this->transparentVisibleSource($input, $lines, $erased);
            $this->transparentInjection = $injection;
            $this->traceNext = $traced;
            try {
                $document = $this->parseDocumentCore($visible, false, $map);
            } finally {
                $this->transparentInjection = null;
            }
            $remap = $this->transparentRemapper($input, $lines, $visible, $map);
            $this->remapTransparentNode($document, $remap);
            $this->remapTransparentState($remap, $map);
            if ($traced) {
                $this->remapTransparentTrace($map, $erased, $lines->lines);
            }
            foreach ($document->getChildren() as $child) {
                if ($child instanceof LinkReferenceDefinition) {
                    $key = LabelKey::normalize($child->getLabel());
                    $winner = $this->state->session->references[$key] ?? null;
                    if ($winner !== null && $winner->line < 0) {
                        $child->setPos($definitionSpans[-$winner->line - 1] ?? $child->getPos());
                    }
                }
            }
            foreach ($restored as $node) {
                // An abbreviation definition hoists to the document, as a container's does (carve#4865).
                $this->insertTransparentNode($document, $node, $node->getPos()->startLine, $node instanceof AbbreviationDefinition ? null : 0);
            }
            $this->orderCollectedDefinitions($document);
            if ($this->state->session->abbreviations !== []) {
                $document->setAbbreviationDefinitions($this->state->session->abbreviationDefinitionsInSourceOrder());
            }
        } finally {
            $this->state->source->trackPositions = $positions;
        }
        if (!$positions) {
            $this->dropTransparentPositions($document);
        }
        $document->setSourceLength($sourceLength);

        return $document;
    }

    /**
     * The visible stream: erased lines removed, an erased quoted line keeps its
     * bare `>` prefix so it still ends a quoted paragraph. Every visible line
     * keeps its authored bytes and line ending.
     *
     * @param array<int, array{owner: string, comment: bool}> $erased
     *
     * @return array{0: string, 1: array<int, int>}
     */
    private function transparentVisibleSource(string $input, SourceLines $lines, array $erased): array
    {
        $starts = $lines->byteLineStarts;
        $visible = '';
        $map = [];
        if (str_starts_with($input, "\u{FEFF}") && !isset($erased[0])) {
            $visible = "\u{FEFF}";
        }
        foreach ($lines->lines as $index => $line) {
            $end = $starts[$index + 1] ?? strlen($input);
            $segment = substr($input, $starts[$index], $end - $starts[$index]);
            if (isset($erased[$index])) {
                if (preg_match(self::TRANSPARENT_QUOTE_PREFIX, $line, $prefix) !== 1) {
                    continue;
                }
                $segment = rtrim($prefix[0]) . substr($segment, strlen($line));
            }
            $map[] = $index;
            $visible .= $segment;
        }

        return [$visible, $map];
    }

    /**
     * @param array<int, int> $map
     *
     * @return \Closure(\MarkupCarve\Carve\Ast\SourceSpan): \MarkupCarve\Carve\Ast\SourceSpan
     */
    private function transparentRemapper(string $input, SourceLines $lines, string $visible, array $map): \Closure
    {
        $originalStarts = self::codepointLineStarts($input, $lines->byteLineStarts);
        $visibleLines = new SourceLines($visible, $visible);
        $visibleStarts = self::codepointLineStarts($visible, $visibleLines->byteLineStarts);
        $originalEnd = mb_strlen($input, 'UTF-8');
        $visibleEnd = mb_strlen($visible, 'UTF-8');
        $point = static function (int $line, int $offset) use ($map, $originalStarts, $visibleStarts, $originalEnd, $visibleEnd): array {
            $index = $line - 1;
            if (!isset($map[$index], $visibleStarts[$index])) {
                return [count($originalStarts) + $index - count($map) + 1, $originalEnd - ($visibleEnd - $offset)];
            }

            return [$map[$index] + 1, $originalStarts[$map[$index]] + $offset - $visibleStarts[$index]];
        };

        return static function (SourceSpan $span) use ($point): SourceSpan {
            if ($span->file !== null) {
                return $span;
            }
            [$startLine, $startOffset] = $point($span->startLine, $span->startOffset);
            [$endLine, $endOffset] = $point($span->endLine, $span->endOffset);

            return new SourceSpan($startLine, $endLine, $span->startColumn, $span->endColumn, $startOffset, $endOffset);
        };
    }

    /**
     * @param array<int, int> $byteStarts
     *
     * @return array<int, int>
     */
    private static function codepointLineStarts(string $source, array $byteStarts): array
    {
        $starts = [];
        $previousByte = 0;
        $codepoints = 0;
        foreach ($byteStarts as $index => $byte) {
            $codepoints += mb_strlen(substr($source, $previousByte, $byte - $previousByte), 'UTF-8');
            $starts[$index] = $codepoints;
            $previousByte = $byte;
        }

        return $starts;
    }

    /**
     * @param \Closure(\MarkupCarve\Carve\Ast\SourceSpan): \MarkupCarve\Carve\Ast\SourceSpan $remap
     */
    private function remapTransparentNode(Node $node, \Closure $remap, int $depth = 0): void
    {
        $pos = $node->getPos();
        if ($pos !== null) {
            $node->setPos($remap($pos));
        }
        if ($node instanceof Document) {
            $spans = [];
            foreach ($node->getAbbreviationSpans() as $term => $span) {
                $parsed = SourceSpan::fromArray($span);
                $spans[$term] = $parsed === null ? $span : $remap($parsed)->toArray();
            }
            $node->setAbbreviationSpans($spans);
        }
        if ($depth > self::MAX_NESTING_DEPTH * 4) {
            return;
        }
        foreach ($node->getChildren() as $child) {
            $this->remapTransparentNode($child, $remap, $depth + 1);
        }
    }

    /**
     * @param \Closure(\MarkupCarve\Carve\Ast\SourceSpan): \MarkupCarve\Carve\Ast\SourceSpan $remap
     * @param array<int, int> $map
     */
    private function remapTransparentState(\Closure $remap, array $map): void
    {
        $line = static fn (int $oneBased): int => isset($map[$oneBased - 1]) ? $map[$oneBased - 1] + 1 : $oneBased;
        $session = $this->state->session;
        $session->unattachedBlockAttributes = array_map($remap, $session->unattachedBlockAttributes);
        foreach ($session->unclosedCodeFences as $k => $fence) {
            $session->unclosedCodeFences[$k]['line'] = $line($fence['line']);
            $session->unclosedCodeFences[$k]['endLine'] = $line($fence['endLine']);
        }
        $refused = [];
        foreach ($this->state->source->refusedPlus as $index => $finding) {
            if ($finding !== null) {
                $finding['line'] = $line($finding['line']);
            }
            $refused[$map[$index] ?? $index] = $finding;
        }
        $this->state->source->refusedPlus = $refused;
        $overindented = [];
        foreach ($this->state->source->overindentedOpeners as $index => $finding) {
            $finding['line'] = $line($finding['line']);
            $overindented[$map[$index] ?? $index] = $finding;
        }
        $this->state->source->overindentedOpeners = $overindented;
    }

    /**
     * @param array<int, int> $map
     * @param array<int, array{owner: string, comment: bool}> $erased
     * @param array<int, string> $lines
     */
    private function remapTransparentTrace(array $map, array $erased, array $lines): void
    {
        $line = static fn (int $oneBased): int => isset($map[$oneBased - 1]) ? $map[$oneBased - 1] + 1 : $oneBased;
        $path = static fn (string $value): string => (string)preg_replace_callback(
            '/:(\d+)/',
            static fn (array $m): string => ':' . $line((int)$m[1]),
            $value,
        );
        $records = [];
        foreach ($this->decisionTrace as $record) {
            $record['line'] = $line((int)$record['line']);
            $record['owner'] = $path((string)$record['owner']);
            if (isset($record['reached'])) {
                $record['reached'] = $path((string)$record['reached']);
            }
            if (isset($record['plus'])) {
                $record['plus'] = $line((int)$record['plus']);
            }
            $index = $record['line'] - 1;
            if (isset($erased[$index])) {
                $record['step'] = 'S1';
                $record['owner'] = $erased[$index]['owner'];
                $record['rule'] = 'CARVE-P0-005';
                $record['form'] = $erased[$index]['comment'] ? 'comment' : 'leaf';
            }
            $records[$index] = $record;
        }
        foreach ($erased as $index => $info) {
            $records[$index] ??= [
                'line' => $index + 1,
                'step' => 'S1',
                'owner' => $info['owner'],
                'rule' => 'CARVE-P0-005',
                'form' => $info['comment'] ? 'comment' : 'leaf',
            ];
        }
        ksort($records);
        $this->decisionTrace = array_values($records);
        unset($lines);
    }

    /**
     * The comment and abbreviation nodes the authored parse made for erased
     * lines, and the extents of erased link definitions.
     *
     * @param array<int, array{owner: string, comment: bool}> $erased
     * @param array<int, \MarkupCarve\Carve\Node\Node> $restored
     * @param array<int, \MarkupCarve\Carve\Ast\SourceSpan> $definitionSpans
     */
    private function collectTransparentNodes(Node $node, array $erased, array &$restored, array &$definitionSpans, int $depth = 0): void
    {
        foreach ($node->getChildren() as $child) {
            $pos = $child->getPos();
            if ($pos !== null && $pos->startLine === $pos->endLine && isset($erased[$pos->startLine - 1])) {
                if ($child instanceof Comment && $child->getFenceLength() === null && !$child->isDelimited()) {
                    $restored[$pos->startLine - 1] = $child;

                    continue;
                }
                if ($child instanceof AbbreviationDefinition) {
                    $restored[$pos->startLine - 1] = $child;

                    continue;
                }
                if ($child instanceof LinkReferenceDefinition) {
                    $definitionSpans[$pos->startLine - 1] = $pos;

                    continue;
                }
            }
            if ($depth < self::MAX_NESTING_DEPTH * 4) {
                $this->collectTransparentNodes($child, $erased, $restored, $definitionSpans, $depth + 1);
            }
        }
        if ($depth === 0) {
            ksort($restored);
        }
    }

    /**
     * Put a restored node in the deepest block container whose extent holds
     * its line, after the last child that starts before it.
     */
    private function insertTransparentNode(Node $container, Node $node, int $line, ?int $depth = 0): void
    {
        $children = $container->getChildren();
        $at = count($children);
        foreach ($children as $k => $child) {
            $pos = $child->getPos();
            if ($pos === null) {
                continue;
            }
            if ($pos->startLine > $line) {
                $at = $k;

                break;
            }
            if ($pos->endLine < $line || $depth === null || $depth >= self::MAX_NESTING_DEPTH * 4 || self::isTransparentLeaf($child)) {
                continue;
            }
            if ($child instanceof ListBlock || $child instanceof BlockQuote || $child->getChildren() !== []) {
                $this->insertTransparentNode($child, $node, $line, $depth + 1);

                return;
            }
        }
        if ($container instanceof ListBlock && $depth !== null) {
            $previous = $children[$at - 1] ?? $children[0] ?? null;
            if ($previous !== null) {
                $this->insertTransparentNode($previous, $node, $line, $depth + 1);

                return;
            }
        }
        array_splice($children, $at, 0, [$node]);
        $container->setChildren($children);
    }

    private static function isTransparentLeaf(Node $node): bool
    {
        return $node instanceof Paragraph || $node instanceof Heading || $node instanceof CodeBlock
            || $node instanceof Table || $node instanceof Comment;
    }

    private function dropTransparentPositions(Node $node, int $depth = 0): void
    {
        $node->setPos(null);
        if ($node instanceof Document) {
            $node->setAbbreviationSpans([]);
        }
        if ($depth > self::MAX_NESTING_DEPTH * 4) {
            return;
        }
        foreach ($node->getChildren() as $child) {
            $this->dropTransparentPositions($child, $depth + 1);
        }
    }

    /**
     * Register the definitions written on erased lines before the visible
     * parse walks its blocks. They carry `-(line + 1)` so last-wins compares
     * authored lines (see BlockParseSession::lineOrder).
     */
    private function injectTransparentDefinitions(): void
    {
        if ($this->transparentInjection === null) {
            return;
        }
        foreach ($this->transparentInjection['references'] as $key => $definition) {
            $this->state->session->references[$key] = $definition;
        }
        foreach ($this->transparentInjection['abbreviations'] as $index => [$term, $expansion]) {
            $this->state->session->defineAbbreviation($term, $expansion, -$index - 1);
        }
    }
}

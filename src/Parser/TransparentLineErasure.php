<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

use Closure;
use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Exception\ParseException;
use MarkupCarve\Carve\Exception\ParseWarning;
use MarkupCarve\Carve\Node\Block\AbbreviationDefinition;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\DefinitionTerm;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Figure;
use MarkupCarve\Carve\Node\Block\FigureGroup;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Block\LinkReferenceDefinition;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\ListItem;
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
    /**
     * @var string
     */
    private const TRANSPARENT_QUOTE_PREFIX = '/^(?:[ \t]*>[ ]?)+/';

    /**
     * @var string
     */
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
     * @param string $input
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
        /** @var array<int, array<string, int|string|null>> $trace */
        $trace = $walk->trace;
        $erased = [];
        foreach ($trace as $record) {
            if ($record['form'] !== 'leaf' || $record['step'] !== 'S5') {
                continue;
            }
            $index = (int)$record['line'] - 1;
            if (isset($walk->skippedCommentLines[$index])) {
                continue;
            }
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
     * @param string $input
     * @param array<int, array{owner: string, comment: bool}> $erased
     * @param int $sourceLength
     */
    private function parseTransparentDocument(string $input, array $erased, int $sourceLength): Document
    {
        $traced = $this->traceNext;
        $this->traceNext = false;
        $positions = $this->state->source->trackPositions;
        $this->state->source->trackPositions = true;
        try {
            $lines = new SourceLines(str_starts_with($input, "\u{FEFF}") ? substr($input, 3) : $input, $input);
            [$restored, $injection, $definitionSpans] = $this->erasedLineNodes($input, $lines, $erased);
            [$visible, $map] = $this->transparentVisibleSource($input, $lines, $erased);
            $this->transparentInjection = $injection;
            $this->traceNext = $traced;
            try {
                $document = $this->parseDocumentCore($visible, false, $map);
            } catch (ParseException $exception) {
                $authored = $map[$exception->getSourceLine() - $this->state->session->lineOffset - 1] ?? null;

                throw $authored === null ? $exception : $exception->atLine($authored + $this->state->session->lineOffset + 1);
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
            $nested = [];
            $hoisted = [];
            foreach ($restored as $index => $node) {
                // An abbreviation definition hoists to the document, as a container's does (carve#4865).
                if ($node instanceof AbbreviationDefinition) {
                    $hoisted[] = [$index + 1, $node];
                } else {
                    $nested[] = [$index + 1, $node];
                }
            }
            $this->insertTransparentNodes($document, $this->placeByOwner($document, $nested, $erased));
            $this->insertTransparentNodes($document, $hoisted, null);
            $this->coverChildSpans($document);
            $this->orderCollectedDefinitions($document);
            if ($positions) {
                $this->sourceMapper()->unplaceAcrossErasedLines($document);
            }
            if ($this->state->session->abbreviations !== []) {
                $notInTree = $document->getAbbreviationDefinitionsNotInTree();
                $document->setAbbreviationDefinitions($this->state->session->abbreviationDefinitionsInSourceOrder());
                $document->setInvisibleAbbreviationDefinitions($notInTree);
                $document->setAbbreviationsBeforeBody($this->erasedAbbreviationsBeforeBody($lines->lines, $injection['abbreviations'], $map));
            }
        } finally {
            $this->state->source->trackPositions = $positions;
        }
        if (!$positions) {
            $this->dropTransparentPositions($document);
            // Without positions the collected definitions keep their append order, notes first.
            $children = $document->getChildren();
            $slots = [];
            $collected = [];
            foreach ($children as $index => $child) {
                if ($child instanceof Footnote || $child instanceof LinkReferenceDefinition) {
                    $slots[] = $index;
                    $collected[] = [$child instanceof Footnote ? 0 : 1, count($collected), $child];
                }
            }
            sort($collected);
            foreach ($slots as $k => $index) {
                $children[$index] = $collected[$k][2];
            }
            $document->setChildren($children);
        }
        $document->setSourceLength($sourceLength);
        TransparentPlacement::markErased($document);

        return $document;
    }

    /**
     * The visible stream: erased lines removed, an erased quoted line keeps its
     * bare `>` prefix so it still ends a quoted paragraph. Every visible line
     * keeps its authored bytes and line ending.
     *
     * @param string $input
     * @param \MarkupCarve\Carve\Parser\SourceLines $lines
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
     * @param string $input
     * @param \MarkupCarve\Carve\Parser\SourceLines $lines
     * @param string $visible
     * @param array<int, int> $map
     *
     * @return \Closure(\MarkupCarve\Carve\Ast\SourceSpan): \MarkupCarve\Carve\Ast\SourceSpan
     */
    private function transparentRemapper(string $input, SourceLines $lines, string $visible, array $map): Closure
    {
        $originalStarts = self::codepointLineStarts($input, $lines->byteLineStarts);
        $visibleLines = new SourceLines(str_starts_with($visible, "\u{FEFF}") ? substr($visible, 3) : $visible, $visible);
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
     * @param string $source
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
     * @param \MarkupCarve\Carve\Node\Node $node
     * @param \Closure(\MarkupCarve\Carve\Ast\SourceSpan): \MarkupCarve\Carve\Ast\SourceSpan $remap
     * @param int $depth
     */
    private function remapTransparentNode(Node $node, Closure $remap, int $depth = 0): void
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
    private function remapTransparentState(Closure $remap, array $map): void
    {
        $line = static fn (int $oneBased): int => isset($map[$oneBased - 1]) ? $map[$oneBased - 1] + 1 : $oneBased;
        $session = $this->state->session;
        $session->unattachedBlockAttributes = array_map($remap, $session->unattachedBlockAttributes);
        // An erased definition reports its encoded line, `-(line + 1)` plus one.
        $session->warnings = array_map(static fn (ParseWarning $warning): ParseWarning => $warning->getFile() !== null ? $warning : new ParseWarning(
            $warning->getMessage(),
            $warning->getLine() <= 0 ? 1 - $warning->getLine() : $line($warning->getLine()),
            $warning->getColumn(),
            $warning->getCategory(),
            $warning->getSuggestion(),
            null,
            $warning->getDetail(),
            $warning->getRule(),
            $warning->getIncludedBy(),
        ), $session->warnings);
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
     * The comment and abbreviation nodes, the definitions to register and the
     * definition extents of the erased lines, built from the lines themselves.
     *
     * @param string $input
     * @param \MarkupCarve\Carve\Parser\SourceLines $lines
     * @param array<int, array{owner: string, comment: bool}> $erased
     *
     * @return array{0: array<int, \MarkupCarve\Carve\Node\Node>, 1: array{references: array<string, \MarkupCarve\Carve\Parser\ReferenceDefinition>, abbreviations: array<int, array{0: string, 1: string}>}, 2: array<int, \MarkupCarve\Carve\Ast\SourceSpan>}
     */
    private function erasedLineNodes(string $input, SourceLines $lines, array $erased): array
    {
        $starts = self::codepointLineStarts($input, $lines->byteLineStarts);
        $bom = str_starts_with($input, "\u{FEFF}") ? 1 : 0;
        $restored = [];
        $injection = ['references' => [], 'abbreviations' => []];
        $spans = [];
        foreach ($erased as $index => $info) {
            $line = $lines->lines[$index];
            $prefix = preg_match('/^(?:[ \t]*>[ ]?)*[ \t]*/', $line, $m) === 1 ? $m[0] : '';
            $rest = substr($line, strlen($prefix));
            $lead = $index === 0 ? $bom : 0;
            $startColumn = mb_strlen($prefix, 'UTF-8') + 1 + $lead;
            $endColumn = mb_strlen($line, 'UTF-8') + 1 + $lead;
            $base = $starts[$index] - $lead;
            $span = new SourceSpan($index + 1, $index + 1, $startColumn, $endColumn, $base + $startColumn - 1, $base + $endColumn - 1);
            if ($info['comment']) {
                $content = substr($rest, 2);
                if ($content !== '' && $content[0] === ' ') {
                    $content = substr($content, 1);
                }
                $node = new Comment(rtrim($content, " \t"));
                $node->setPos($span);
                $restored[$index] = $node;

                continue;
            }
            $rest = rtrim($rest, " \t");
            if (preg_match(self::ABBREVIATION_DEFINITION_PATTERN, $rest, $m) === 1) {
                $node = new AbbreviationDefinition($m[1], rtrim($m[2], " \t"));
                $node->setPos(new SourceSpan($index + 1, $index + 1, 1 + $lead, $endColumn, $base + $lead, $base + $endColumn - 1));
                $restored[$index] = $node;
                $injection['abbreviations'][$index] = [$m[1], rtrim($m[2], " \t")];

                continue;
            }
            $definition = $this->referenceDefinitionExtractor->matchDefinitionLine($rest);
            if ($definition !== null) {
                $injection['references'][LabelKey::normalize($definition['label'])] = new ReferenceDefinition(
                    $definition['url'],
                    $definition['attrs'],
                    -$index - 1,
                    $definition['title'],
                    false,
                    $definition['label'],
                );
                $spans[$index] = $span;
            }
        }

        return [$restored, $injection, $spans];
    }

    /**
     * PART 11 §10a: whether an abbreviation definition comes before the first body line.
     *
     * @param array<int, string> $lines
     * @param array<int, array{0: string, 1: string}> $erasedAbbreviations
     * @param array<int, int> $map
     */
    private function erasedAbbreviationsBeforeBody(array $lines, array $erasedAbbreviations, array $map): bool
    {
        $abbreviations = $erasedAbbreviations;
        foreach (array_keys($this->state->session->discoveredAbbreviationLines) as $visible) {
            $abbreviations[$map[$visible] ?? $visible] = true;
        }
        if ($abbreviations === []) {
            return false;
        }
        $first = min(array_keys($abbreviations));
        foreach ($lines as $index => $line) {
            if (trim($line, " \t") === '' || isset($abbreviations[$index])) {
                continue;
            }

            return $first < $index;
        }

        return true;
    }

    /**
     * Put restored nodes in the deepest block container whose extent holds
     * their line, after the last child that starts before it. One merge pass
     * per container, so the cost stays linear in the tree.
     *
     * @param \MarkupCarve\Carve\Node\Node $container
     * @param array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}> $nodes Line and node, in source order.
     * @param int|null $depth Null keeps every node at this container's level.
     */
    private function insertTransparentNodes(Node $container, array $nodes, ?int $depth = 0): void
    {
        if ($nodes === []) {
            return;
        }
        $children = $container->getChildren();
        $descends = $depth !== null && $depth < self::MAX_NESTING_DEPTH * 4;
        $list = ($container instanceof ListBlock || $container instanceof DefinitionList) && $descends;
        $out = [];
        $next = 0;
        $count = count($nodes);
        foreach ($children as $k => $child) {
            $pos = $child->getPos();
            if ($pos === null) {
                $out[] = $child;

                continue;
            }
            $before = [];
            while ($next < $count && $nodes[$next][0] < $pos->startLine) {
                $before[] = $nodes[$next++];
            }
            if ($before !== [] && $list) {
                // A line between two items stays in the item above it.
                $this->insertTransparentNodes(self::holderNear($children, $k - 1), $before, $depth + 1);
            } else {
                foreach ($before as [, $node]) {
                    $out[] = $node;
                }
            }
            $inside = [];
            while ($next < $count && $nodes[$next][0] <= $pos->endLine) {
                $inside[] = $nodes[$next++];
            }
            $out[] = $child;
            if ($inside === []) {
                continue;
            }
            if ($list && !$child instanceof ListItem && !$child instanceof DefinitionDescription) {
                $this->insertTransparentNodes(self::holderNear($children, $k), $inside, $depth + 1);

                continue;
            }
            if (
                $descends && !self::isTransparentLeaf($child)
                && ($child instanceof ListBlock || $child instanceof BlockQuote || $child->getChildren() !== [])
            ) {
                $this->insertTransparentNodes($child, $inside, $depth + 1);

                continue;
            }
            foreach ($inside as [, $node]) {
                $out[] = $node;
            }
        }
        $rest = array_slice($nodes, $next);
        if ($rest !== [] && $list && $children !== []) {
            $this->insertTransparentNodes(self::holderNear($children, count($children) - 1), $rest, $depth + 1);
        } else {
            foreach ($rest as [, $node]) {
                $out[] = $node;
            }
        }
        $container->setChildren($out);
        // A container holds the lines it took in (PART 12 §4: no span escapes its parent).
        $span = $container->getPos();
        $first = $nodes[0][1]->getPos();
        $last = $nodes[$count - 1][1]->getPos();
        if ($span !== null && $first !== null && $last !== null) {
            $start = $first->startOffset < $span->startOffset ? $first : $span;
            $end = $last->endOffset > $span->endOffset ? $last : $span;
            $container->setPos(new SourceSpan($start->startLine, $end->endLine, $start->startColumn, $end->endColumn, $start->startOffset, $end->endOffset, $span->file));
        }
    }

    /**
     * Put each node whose walk owner is a container in that container, the one
     * the ownership walk named; return the rest. A quoted line whose content was
     * erased can lie past the quote's visible extent and still be the quote's.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}> $nodes
     * @param array<int, array{owner: string, comment: bool}> $erased
     *
     * @return array<int, array{0: int, 1: \MarkupCarve\Carve\Node\Node}>
     */
    private function placeByOwner(Document $document, array $nodes, array $erased): array
    {
        $kinds = ['quote' => BlockQuote::class, 'item' => ListItem::class, 'footnote' => Footnote::class, 'div' => Div::class, 'dd' => DefinitionDescription::class];
        $index = [];
        $stack = [[$document, 0]];
        $order = [];
        while ($stack !== []) {
            [$node, $depth] = array_pop($stack);
            $order[] = $node;
            if ($depth < self::MAX_NESTING_DEPTH * 4) {
                foreach (array_reverse($node->getChildren()) as $child) {
                    $stack[] = [$child, $depth + 1];
                }
            }
        }
        foreach ($order as $node) {
            $pos = $node->getPos();
            foreach ($kinds as $kind => $class) {
                if ($pos !== null && $node instanceof $class) {
                    $index[$kind . ':' . $pos->startLine][] = $node;
                }
            }
        }
        $rest = [];
        $targets = [];
        foreach ($nodes as $entry) {
            $segments = explode('/', $erased[$entry[0] - 1]['owner'] ?? 'doc');
            $last = (string)end($segments);
            $nth = count(array_keys($segments, $last, true));
            $target = $index[$last][$nth - 1] ?? null;
            if ($target === null) {
                $rest[] = $entry;

                continue;
            }
            $targets[spl_object_id($target)] ??= [$target, []];
            $targets[spl_object_id($target)][1][] = $entry;
        }
        foreach ($targets as [$target, $entries]) {
            $this->insertTransparentNodes($target, $entries, 1);
        }

        return $rest;
    }

    /**
     * The item or description at or above `$k`, else the first one below it.
     *
     * @param array<int, \MarkupCarve\Carve\Node\Node> $children
     * @param int $k
     */
    private static function holderNear(array $children, int $k): Node
    {
        $holds = static fn (Node $node): bool => $node instanceof ListItem || $node instanceof DefinitionDescription;
        for ($i = min($k, count($children) - 1); $i >= 0; $i--) {
            if ($holds($children[$i])) {
                return $children[$i];
            }
        }
        foreach ($children as $child) {
            if ($holds($child)) {
                return $child;
            }
        }

        return $children[max(0, $k)];
    }

    /**
     * Grow each container's span over its children, so a restored line never
     * leaves an ancestor's extent (PART 12 §4).
     */
    private function coverChildSpans(Node $node, int $depth = 0): ?SourceSpan
    {
        $span = $node->getPos();
        if ($depth >= self::MAX_NESTING_DEPTH * 4) {
            return $span;
        }
        $first = $span;
        $last = $span;
        foreach ($node->getChildren() as $child) {
            $inner = $this->coverChildSpans($child, $depth + 1);
            if ($inner === null || $inner->file !== null) {
                continue;
            }
            $first = $first === null || $inner->startOffset < $first->startOffset ? $inner : $first;
            $last = $last === null || $inner->endOffset > $last->endOffset ? $inner : $last;
        }
        if ($span === null || $node instanceof Document || $first === null || $last === null || ($first === $span && $last === $span)) {
            return $span;
        }
        $covered = new SourceSpan($first->startLine, $last->endLine, $first->startColumn, $last->endColumn, $first->startOffset, $last->endOffset, $span->file);
        $node->setPos($covered);

        return $covered;
    }

    private static function isTransparentLeaf(Node $node): bool
    {
        return $node instanceof Paragraph || $node instanceof Heading || $node instanceof CodeBlock
            || $node instanceof Table || $node instanceof Comment || $node instanceof Figure
            || $node instanceof FigureGroup || $node instanceof LineBlock || $node instanceof DefinitionTerm;
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

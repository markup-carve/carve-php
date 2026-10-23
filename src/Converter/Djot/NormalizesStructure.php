<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Djot;

use Closure;
use LogicException;
use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;

trait NormalizesStructure
{
    private function genericDjotDivClasses(string $source): string
    {
        if (!str_contains($source, ':::')) {
            return $source;
        }
        $lines = explode("\n", $source);
        $masks = explode("\n", $this->maskCodeAndDestinations($source));
        $safe = $lines;
        $classes = [];
        foreach ($lines as $line => $text) {
            $at = $this->djotContentStart($text);
            if (($masks[$line][$at] ?? '') !== ':' || preg_match('/^(:{3,})[ \t]+((?:[A-Za-z0-9_-]|\\\\[_-])+)[ \t]*\r?$/', substr($text, $at), $fence) !== 1) {
                continue;
            }
            $class = preg_replace('/\\\\([_-])/', '$1', $fence[2]) ?? $fence[2];
            if ($class !== 'figure' && !str_starts_with($class, '-') && $class === $fence[2] && !in_array($class, Div::ADMONITION_TYPES, true) && !in_array($class, Div::GENERATED_CONTENT_KINDS, true)) {
                continue;
            }
            $classes[$line] = $class;
            $safe[$line] = substr($text, 0, $at) . $fence[1] . ' carve-djot-div';
        }
        if ($classes === []) {
            return $source;
        }
        $document = (new BlockParser(trackPositions: true))->parse(implode("\n", $safe));
        $pending = [];
        foreach ($document->getChildren() as $node) {
            $pending[] = [$node, null];
        }
        /** @var list<array{source: int, target: int, parent: int|null, bare: bool}> $frames */
        $frames = [];
        $sites = $mapped = [];
        while ($pending !== []) {
            [$node, $parent] = array_pop($pending);
            $owner = $parent;
            $pos = $node->getPos();
            if ($node instanceof Div && $pos !== null) {
                $line = $pos->startLine - 1;
                $text = $lines[$line] ?? '';
                $at = $this->djotContentStart($text);
                if (preg_match('/^:{3,}/', substr($text, $at), $fence) === 1) {
                    $class = $classes[$line] ?? null;
                    $owner = count($frames);
                    $width = strlen($fence[0]);
                    $frames[] = ['source' => $width, 'target' => $width, 'parent' => $parent, 'bare' => $class !== null || preg_match('/^:{3,}[ \t]*\r?$/', substr($text, $at)) === 1];
                    $sites[$line] = ['at' => $at, 'frame' => $owner, 'class' => $class];
                    if ($class !== null) {
                        $mapped[$line] = true;
                    }
                    $closeLine = $pos->endLine - 1;
                    $closer = $lines[$closeLine] ?? '';
                    $closeAt = $this->djotContentStart($closer);
                    if (($masks[$closeLine][$closeAt] ?? '') === ':' && preg_match('/^:{' . $width . '}[ \t]*\r?$/', substr($closer, $closeAt)) === 1) {
                        $sites[$closeLine] = ['at' => $closeAt, 'frame' => $owner, 'class' => null];
                    }
                }
            }
            if ($node instanceof BlockQuote || $node instanceof Footnote) {
                $owner = null;
            }
            foreach ($node->getChildren() as $child) {
                $pending[] = [$child, $owner];
            }
        }
        if (count($mapped) !== count($classes)) {
            return $source;
        }
        for ($index = count($frames) - 1; $index >= 0; $index--) {
            $frame = $frames[$index];
            if ($frame['bare'] && $frame['parent'] !== null && $frames[$frame['parent']]['target'] <= $frame['target']) {
                $frames[$frame['parent']]['target'] = $frame['target'] + 1;
            }
        }
        foreach ($sites as $line => $site) {
            $prefix = substr($lines[$line], 0, $site['at']);
            $frame = $frames[$site['frame']];
            $fence = str_repeat(':', $frame['target']);
            if ($site['class'] !== null) {
                $continuation = preg_replace('/\[\^[^\]\n]+\]:[ \t]*/', '  ', $prefix) ?? $prefix;
                $continuation = preg_replace('/\[[ xX]\][ \t]+/', '', $continuation) ?? $continuation;
                $continuation = preg_replace('/[^> \t]/', ' ', $continuation) ?? $continuation;
                $lines[$line] = $prefix . '{class="' . $site['class'] . '"}' . "\n" . $continuation . $fence;
            } else {
                $lines[$line] = $prefix . $fence . substr($lines[$line], $site['at'] + $frame['source']);
            }
        }

        return implode("\n", $lines);
    }

    private function hasDjotCodeLayoutCandidates(string $source): bool
    {
        return (str_contains($source, '`') && preg_match('/(?:^|\n)[ \t]*:(?:[ \t]|$)/', $source) === 1) || str_contains($source, '\\|') || (str_contains($source, '`') && preg_match('/(?:^|\n)(?:[ \t]*>(?:[ \t]|$))*[ \t]*\[\^[^\]\n]+\]:/', $source) === 1) || (str_contains($source, '`') && preg_match('/(?:^|\n)[ \t]*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]+)[.)]|\((?:[0-9]+|[A-Za-z]+)\))[ \t]+(?:\[[ xX]\][ \t]+)?|\[\^[^\]\n]+\]:[ \t]*)*>/', $source) === 1)
            || (str_contains($source, '```') && preg_match('/(?:^|\n)(?:[ \t]*>(?:[ \t]|$))*[ \t]+`{3,}/', $source) === 1);
    }

    private function djotListKind(string $marker): string
    {
        if (preg_match('/[0-9A-Za-z]+/', $marker, $match) !== 1) {
            return $marker;
        }
        $label = $match[0];
        $mask = ctype_digit($label) ? 1 : ($label === strtolower($label) ? 2 : 8);
        if (preg_match('/^[ivxlcdm]+$/i', $label) === 1) {
            $mask = (strlen($label) === 1 ? $mask : 0) | ($label === strtolower($label) ? 4 : 16);
        }

        return $mask . ':' . (preg_replace('/[0-9A-Za-z]+/', '', $marker) ?? $marker);
    }

    private function sharedDjotListKind(?string $prior, string $current): ?string
    {
        if ($prior === $current) {
            return $current;
        }
        if (preg_match('/^(\d+):(.*)$/', $prior ?? '', $first) !== 1 || preg_match('/^(\d+):(.*)$/', $current, $second) !== 1 || $first[2] !== $second[2]) {
            return null;
        }
        $common = (int)$first[1] & (int)$second[1];

        return $common !== 0 ? $common . ':' . $first[2] : null;
    }

    private function djotDestinationLines(string $source, int $depth): string
    {
        if (!str_contains($source, "\n")) {
            return $source;
        }
        $rawDestination = $source;
        $rawDestination = preg_replace_callback('/[ \t]*\\\\([ \t]*)(\r?\n)|\\\\[^\r\n]/', static fn (array $match): string => isset($match[2]) ? $match[1] . $match[2] : $match[0], $rawDestination) ?? $rawDestination;
        $destination = preg_replace_callback('/\n([ \t]*[^\n]*)/', static function (array $match) use ($depth): string {
            $rest = ltrim($match[1], " \t");
            for ($n = 0; $n < $depth && preg_match('/^>(?:[ \t]|$)/', $rest); $n++) {
                $rest = ltrim(substr($rest, 1), " \t");
            }

            return $rest;
        }, $rawDestination) ?? $rawDestination;

        return $destination;
    }

    private function preserveDjotBacktickDestinationSlashes(string $destination): string
    {
        if (!str_contains($destination, '`')) {
            return $destination;
        }

        preg_match_all('/`+/', $destination, $matches, PREG_OFFSET_CAPTURE);
        $runs = $matches[0];
        $parts = [];
        $cursor = 0;
        for ($i = 0, $count = count($runs); $i < $count; $i++) {
            [$open, $start] = $runs[$i];
            if ($this->isDjotEscaped($destination, $start)) {
                $start++;
                $open = substr($open, 1);
                if ($open === '') {
                    continue;
                }
            }
            for ($j = $i + 1; $j < $count; $j++) {
                [$close, $end] = $runs[$j];
                if ($close !== $open) {
                    continue;
                }
                $from = $start + strlen($open);
                $parts[] = substr($destination, $cursor, $from - $cursor);
                $parts[] = str_replace('\\', '\\\\', substr($destination, $from, $end - $from));
                $cursor = $end;
                $i = $j;

                break;
            }
        }

        return implode('', $parts) . substr($destination, $cursor);
    }

    private function removeDjotDestinationTabs(string $destination): string
    {
        if (!str_contains($destination, "\t")) {
            return $destination;
        }

        // URL resolution discards tabs. A backslash before a tab is literal
        // in Djot, so pair an odd run before removing the tab.
        $destination = preg_replace_callback('/(\\\\+)\t/', static fn (array $match): string => $match[1] . (strlen($match[1]) % 2 === 1 ? '\\' : ''), $destination) ?? $destination;

        return str_replace("\t", '', $destination);
    }

    /**
     * @param list<string> $out
     * @param string $replacement
     * @param int $row
     *
     * @throws \LogicException
     */
    private function replaceDjotLayoutLine(array &$out, int $row, string $replacement): void
    {
        if (!array_key_exists($row, $out)) {
            throw new LogicException('Missing Djot layout row');
        }
        $out[$row] = $replacement;
    }

    private function deepQuotedItemAttributes(string $source, Closure $hold): string
    {
        if (!str_contains($source, '{') || !str_contains($source, '>')) {
            return $source;
        }
        $lines = explode("\n", $source);
        $maskedSource = $this->maskCodeAndDestinations($source);
        $masks = explode("\n", $maskedSource);
        $tableRows = str_contains($source, '|') ? $this->djotTableRows($source, $maskedSource) : [];
        $frames = [];
        $before = [];
        $offset = 0;
        $removed = [];
        $previousDepth = 0;
        $previousParagraph = false;
        $literalDepth = null;
        foreach ($lines as $row => $line) {
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $line, $quotes);
            $prefix = $quotes[0] ?? '';
            $depth = substr_count($prefix, '>');
            $body = substr($line, strlen($prefix));
            $indent = strspn($body, " \t");
            $text = substr($body, $indent);
            $visible = substr($masks[$row], strlen($prefix) + $indent);
            $at = $offset + strlen($prefix) + $indent;
            $offset += strlen($line) + 1;
            $openingColumn = 0;
            if ($depth > $previousDepth) {
                $prefixEnd = 0;
                for ($level = 0; $level < $previousDepth; $level++) {
                    preg_match('/^[ \t]*>(?:[ \t]|$)/', substr($prefix, $prefixEnd), $part);
                    $prefixEnd += strlen($part[0] ?? '');
                }
                $openingColumn = strspn($prefix, " \t", $prefixEnd);
            }
            $endsItem = false;
            if (($masks[$row][strrpos($prefix, '>') ?: 0] ?? '') === '>') {
                foreach ($frames[$previousDepth] ?? [] as $owner) {
                    $endsItem = $endsItem || $owner['column'] >= $openingColumn;
                }
            }
            if ($literalDepth !== null && $depth <= $literalDepth) {
                $literalDepth = null;
            }
            if ($depth > $previousDepth && $previousParagraph && !$endsItem) {
                $literalDepth ??= $previousDepth;
            }
            foreach (array_keys($frames) as $level) {
                if ($level > $depth) {
                    unset($frames[$level]);
                }
            }
            if ($depth > $previousDepth && ($endsItem || !$previousParagraph) && ($masks[$row][strrpos($prefix, '>') ?: 0] ?? '') === '>') {
                $frames[$previousDepth] = array_values(array_filter($frames[$previousDepth] ?? [], static fn (array $owner): bool => $owner['column'] < $openingColumn));
            }
            if ($depth > $previousDepth && isset($frames[$previousDepth]) && $frames[$previousDepth] !== []) {
                $ownerIndex = array_key_last($frames[$previousDepth]);
                $frames[$previousDepth][$ownerIndex]['attributes'] = [];
            }
            $priorDepth = $previousDepth;
            $priorParagraph = $previousParagraph;
            $previousDepth = $depth;
            $wholeAttributes = str_starts_with($visible, '{') && ($this->readDjotWordAttributes($body, $indent)['end'] ?? -1) === strlen(rtrim($body));
            $previousParagraph = $text !== '' && !($tableRows[$row] ?? false) && preg_match('/^(?:`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/', $text) !== 1 && !$wholeAttributes && preg_match('/^(?:[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|#{1,6}(?:[ \t]|$)|:{3,}|[`~]{3,}|\[(?:\^)?[^\]\n]*\]:)/', $visible) !== 1;
            if ($literalDepth !== null && $depth > $literalDepth) {
                continue;
            }
            if ($depth < 1 || $text === '') {
                continue;
            }
            $stack = $frames[$depth] ?? [];
            while ($stack !== [] && $stack[array_key_last($stack)]['column'] > $indent) {
                array_pop($stack);
            }
            $frames[$depth] = $stack;
            $frame = $stack !== [] ? $stack[array_key_last($stack)] : null;
            if (preg_match('/^([-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $visible, $item) === 1) {
                if ($depth === $priorDepth && $priorParagraph && ($frame === null || $indent > $frame['column'])) {
                    $previousParagraph = true;
                    if ($stack !== []) {
                        $frames[$depth] = $stack;
                    }

                    continue;
                }
                $itemText = ltrim((string)preg_replace('/^\[[ xX]\][ \t]+/', '', substr($text, strlen($item[0]))), " \t");
                $previousParagraph = $itemText !== '' && preg_match('/^(?:[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|#{1,6}(?:[ \t]|$)|:{3,}|[`~]{3,}|[>|]|\[(?:\^)?[^\]\n]*\]:)/', $itemText) !== 1;
                $kind = $this->djotListKind($item[1]);
                if (str_contains('-*+', $item[1]) && preg_match('/^\[[ xX]\](?:[ \t]+|$)/', substr($text, strlen($item[0]))) === 1) {
                    $kind .= ':task';
                }
                $same = $frame !== null && $indent === $frame['column'] ? $this->sharedDjotListKind($frame['kind'], $kind) : null;
                if ($depth >= 2 && $frame !== null && $same !== null && $frame['attributes'] !== []) {
                    $parts = [];
                    foreach ($frame['attributes'] as $attribute) {
                        $parts[] = substr($attribute['wire'], 1, -1);
                        $removed[$attribute['row']] = true;
                    }
                    $loose = false;
                    for ($at = max(0, $frame['attributes'][0]['row'] - 1); $at < $row; $at++) {
                        if (preg_match('/^(?:[ \t]*>(?:[ \t]|$))*[ \t]*$/', $lines[$at]) === 1) {
                            $loose = true;

                            break;
                        }
                    }
                    $before[$row] = [$prefix . str_repeat(' ', $indent) . $hold('{' . implode(' ', $parts) . '}')];
                    if ($loose) {
                        $before[$row][] = rtrim($prefix);
                    }
                    $prefixEnds = [0];
                    for ($level = 0; $level < $depth; $level++) {
                        preg_match('/^[ \t]*>(?:[ \t]|$)/', substr($prefix, $prefixEnds[$level]), $part);
                        $prefixEnds[] = $prefixEnds[$level] + strlen($part[0] ?? '');
                    }
                    foreach ($frames as $ownerDepth => $owners) {
                        foreach ($owners as $owner) {
                            if (!$loose || $ownerDepth > $depth) {
                                continue;
                            }
                            $ownerIndent = strspn(substr($prefix, $prefixEnds[$ownerDepth]), " \t");
                            if ($ownerDepth < $depth && $ownerIndent <= $owner['column']) {
                                continue;
                            }
                            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $lines[$owner['start']], $startPrefix);
                            if (!isset($before[$owner['start']])) {
                                $before[$owner['start']] = [($startPrefix[0] ?? '') . str_repeat(' ', $owner['column']) . $hold('{loose}', true), rtrim($startPrefix[0] ?? '')];
                            }
                        }
                    }
                }
                if ($frame !== null && $indent > $frame['column']) {
                    $stack[array_key_last($stack)]['attributes'] = [];
                }
                if ($frame !== null && $indent === $frame['column']) {
                    array_pop($stack);
                }
                $stack[] = ['column' => $indent, 'start' => $same !== null ? $frame['start'] : $row, 'kind' => $same ?? $kind, 'attributes' => []];
                $frames[$depth] = $stack;
            } elseif (str_starts_with($visible, '{')) {
                $attrs = $this->readDjotWordAttributes($body, $indent);
                if ($depth >= 2 && $frame !== null && $indent > $frame['column'] && $attrs !== null && $attrs['end'] === strlen(rtrim($body)) && !$this->djotCommentContinuesParagraph($source, $at)) {
                    $stack[array_key_last($stack)]['attributes'][] = ['row' => $row, 'wire' => $attrs['source']];
                    $frames[$depth] = $stack;
                } elseif ($frame !== null && $indent <= $frame['column']) {
                    unset($frames[$depth]);
                } elseif ($frame !== null && ($attrs['end'] ?? -1) !== strlen(rtrim($body))) {
                    $stack[array_key_last($stack)]['attributes'] = [];
                    $frames[$depth] = $stack;
                }
            } elseif ($frame !== null) {
                $stack[array_key_last($stack)]['attributes'] = [];
                $frames[$depth] = $stack;
                if ($indent <= $frame['column']) {
                    unset($frames[$depth]);
                }
            }
        }
        $output = [];
        foreach ($lines as $row => $line) {
            if (isset($removed[$row])) {
                continue;
            }
            foreach ($before[$row] ?? [] as $inserted) {
                $output[] = $inserted;
            }
            $output[] = $line;
        }

        return implode("\n", $output);
    }

    private function normalizeDjotStructure(
        string $source,
        ?Closure $isBoundary = null,
        string $emptyNote = '%%%%',
        ?Closure $holdItemAttributes = null,
        ?Closure $onLiteralQuote = null,
    ): string {
        if ($holdItemAttributes !== null) {
            $source = $this->deepQuotedItemAttributes($source, $holdItemAttributes);
        }
        $lines = explode("\n", $source);
        $maskedSource = $this->maskCodeAndDestinations($source);
        $hasDefinitionSyntax = preg_match('/(?:^|\n)(?:[ \t]*>(?:[ \t]|$))*[ \t]*:[ \t]/', $maskedSource) === 1;
        $mask = explode("\n", $maskedSource);
        $divClosers = [];
        if (str_contains($source, ':::')) {
            $this->djotInlineBoundaries($source, $maskedSource, true, $divClosers);
        }
        $lineOffsets = [];
        $lineOffset = 0;
        foreach ($lines as $line) {
            $lineOffsets[] = $lineOffset;
            $lineOffset += strlen($line) + 1;
        }
        $fences = explode("\n", $this->maskDjotFences($source));
        $rows = $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false));
        $divs = $lists = $out = [];
        $pendingAttributes = [];
        $returnedNoteItemAttributes = [];
        $returnedNoteDestinationDepth = null;
        $paragraph = false;
        $blank = true;
        $depth = 0;
        $embeddedOwners = new EmbeddedListStack();
        $embeddedListOwner = null;
        $opaqueQuoteOwner = null;
        $quoteBoundary = '';
        $headingMarker = '';
        $definitionIndent = -1;
        $definitionStack = [];
        $noteOwner = null;
        $notes = new NoteStack();
        $completedNotes = [];
        /**
         * @param list<array{owner: int, depth: int, row: int, attributes: string}> $pendingAttributes
         */
        $endNote = function (NoteStack $notes, array $pendingAttributes) use (&$out, &$completedNotes, &$lists, &$divs, &$depth, &$quoteBoundary, &$embeddedListOwner, &$embeddedOwners, &$opaqueQuoteOwner, &$definitionIndent, &$paragraph, &$headingMarker): array {
            $ended = $notes->pop();
            $returnedAttributes = array_column($pendingAttributes, 'attributes');
            foreach ($pendingAttributes as $attribute) {
                if (array_key_exists($attribute['row'], $out)) {
                    $out[$attribute['row']] = '';
                }
            }
            $pendingAttributes = [];
            if ($divs !== []) {
                $this->dropOrphanDjotAttributeLine($out);
            }
            while ($divs !== []) {
                $div = array_pop($divs);
                if (!str_contains($div['prefix'], '>')) {
                    $out[] = $div['prefix'] . str_repeat(':', $div['width']);
                }
            }
            $completedNotes[] = ['body' => $out, 'placement' => $ended->placement];
            $out = $ended->parentOut;
            $lists = $ended->parentLists;
            $divs = $ended->parentDivs;
            $depth = $ended->parentDepth;
            $quoteBoundary = $ended->parentBoundary;
            $embeddedListOwner = $ended->parentEmbedded;
            $embeddedOwners = $ended->parentEmbeddedOwners;
            $opaqueQuoteOwner = $ended->parentOpaque;
            $definitionIndent = $ended->parentDefinition;
            $paragraph = false;
            $headingMarker = '';
            $parentList = $lists === [] ? null : $lists[array_key_last($lists)];
            if ($ended->pendingAttributes !== []) {
                $out[] = rtrim($ended->pendingParentPrefix);
            }
            foreach ($ended->pendingAttributes as $attributes) {
                $out[] = $ended->pendingParentPrefix . str_repeat(' ', $parentList !== null ? $parentList->target + $parentList->content : 0) . $attributes;
            }

            return ['owner' => $notes->last(), 'attributes' => $returnedAttributes, 'depth' => $ended->depth];
        };
        $table = false;
        $fenceParser = new FencedBlockParser();
        $emptyItemAt = null;
        foreach ($lines as $n => $original) {
            $emptyItem = false;
            $emptyItemColumn = null;
            $masked = $mask[$n];
            preg_match('/^(?:[ \t]*>(?:[ \t]|$))*/', $original, $quoteMatch);
            $physicalQuote = $quoteMatch[0] ?? '';
            $physicalQuoteDepth = substr_count($physicalQuote, '>');
            $quotedNoteItem = null;
            $physicalList = $lists !== [] ? $lists[array_key_last($lists)] : null;
            $physicalIndent = strspn(substr($original, strlen($physicalQuote)), " \t");
            if ($noteOwner === null && ($blank || !$paragraph || $physicalQuoteDepth < $depth || ($physicalList !== null && $physicalList->depth === $physicalQuoteDepth && $physicalIndent <= $physicalList->column)) && $fences[$n] === $original && preg_match('/^([ \t]*)((?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)(>[ \t](?:[ \t]*>[ \t])*)[ \t]*(?=\[\^[^\]\n]+\]:(?:[ \t]|$))/', substr($original, strlen($physicalQuote)), $quotedItem) === 1) {
                $quotedNoteItem = $quotedItem;
            }
            $quote = $quotedNoteItem !== null ? $physicalQuote . $quotedNoteItem[1] . $quotedNoteItem[2] . $quotedNoteItem[3] : $physicalQuote;
            $quoteDepth = substr_count($quote, '>');
            if ($quoteDepth > 0 && ($masked[strpos($quote, '>') ?: 0] ?? '') !== '>' && $fences[$n] === $original) {
                $out[] = $original;
                $blank = false;

                continue;
            }
            $raw = substr($original, strlen($quote));
            $view = substr($masked, strlen($quote));
            $rawIndent = strspn($raw, " \t");
            $previousList = $lists !== [] ? $lists[array_key_last($lists)] : null;
            $sourcePrefix = $quotedNoteItem === null && $previousList !== null && $previousList->depth === $quoteDepth ? ($previousList->sourcePrefix ?? strlen($quote)) : strlen($quote);
            $indent = max(0, $rawIndent + strlen($quote) - $sourcePrefix);
            $text = substr($raw, $rawIndent);
            $visible = substr($view, $rawIndent);
            $quoteBases = [];
            $quoteView = $quotedNoteItem !== null ? substr($original, 0, strlen($physicalQuote) + strlen($quotedNoteItem[1])) . str_repeat(' ', strlen($quotedNoteItem[2])) . substr($original, strlen($physicalQuote) + strlen($quotedNoteItem[1]) + strlen($quotedNoteItem[2])) : $original;
            $quoteColumns = $quoteDepth > 0 ? $this->djotIndentColumnsAfterQuotes($quoteView, $quoteDepth, $quoteBases) : [];
            $listColumn = static fn (ListContext $owner): int => $quoteDepth > $owner->depth ? ($quoteColumns[$owner->depth] ?? $rawIndent) + ($owner->sourcePrefix === null ? 0 : ($quoteBases[$owner->depth] ?? 0) - $owner->sourcePrefix) : ($owner->sourcePrefix === null ? $indent : strlen($quote) + $rawIndent - $owner->sourcePrefix);
            $inlineNoteItem = null;
            if (($blank || !$paragraph || $quoteDepth < $depth || ($previousList !== null && $previousList->depth === $quoteDepth && $indent <= $previousList->column)) && preg_match('/^((?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)(?=\[\^[^\]\n]+\]:(?:[ \t]|$))/', $visible, $inlineItem) === 1) {
                $inlineNoteItem = $inlineItem;
            }
            $noteText = $inlineNoteItem !== null ? substr($text, strlen($inlineNoteItem[0])) : $text;
            $noteHead = null;
            if (($inlineNoteItem !== null || str_starts_with($visible, '[^')) && preg_match('/^(\[\^[^\]\n]+\]:[ \t]*)(.*)$/', $noteText, $noteMatch) === 1 && ($inlineNoteItem !== null || $quotedNoteItem !== null || $blank || !$paragraph || $quoteDepth < $depth || ($noteOwner !== null && $indent <= $noteOwner->column) || ($n > 0 && $isBoundary !== null && $isBoundary($lines[$n - 1])))) {
                $noteHead = $noteMatch;
            }
            while ($noteOwner !== null && $fences[$n] === $original && ($text !== '' || $quoteDepth > $noteOwner->depth)) {
                $ownerColumn = $quoteDepth > $noteOwner->depth ? ($quoteColumns[$noteOwner->depth] ?? 0) : $indent;
                $outside = $quoteDepth < $noteOwner->depth || $ownerColumn <= $noteOwner->column;
                $block = ($isBoundary !== null && $isBoundary($original)) || $noteHead !== null || $quoteDepth > $noteOwner->depth || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[|]|[`~]{3,}|[-*+](?:[ \t]|$)|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)](?:[ \t]|$)|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)(?:[ \t]|$)|\[[^\]\n]+\]:)/', $visible) === 1
                    || (str_starts_with($visible, '{') && ($this->readDjotWordAttributes($visible, 0)['end'] ?? -1) === strlen($visible));
                if ($outside && !($paragraph && !$blank && !$block && $quoteDepth <= $noteOwner->depth)) {
                    $ended = $endNote($notes, $pendingAttributes);
                    $noteOwner = $ended['owner'];
                    $returnedNoteItemAttributes = array_merge($returnedNoteItemAttributes, $ended['attributes']);
                    if ($returnedNoteItemAttributes !== []) {
                        $returnedNoteDestinationDepth = min($returnedNoteDestinationDepth ?? $ended['depth'], $ended['depth']);
                    }
                    $pendingAttributes = [];
                    if ($noteOwner === null && str_starts_with($visible, '{') && ($this->readDjotWordAttributes($visible, 0)['end'] ?? -1) === strlen($visible) && $out !== [] && str_contains(end($out), $emptyNote)) {
                        $out[] = $quote . str_repeat(' ', $indent);
                    }
                } else {
                    break;
                }
            }
            if ($noteOwner !== null && $text !== '') {
                $attributes = str_starts_with($text, '{') ? $this->readDjotWordAttributes($text, 0) : null;
                if ($attributes !== null && $attributes['end'] === strlen($text) && $noteOwner->pendingAttributes !== []) {
                    $noteOwner->pendingAttributes[] = $attributes['source'];
                    $paragraph = false;
                    $blank = true;

                    continue;
                }
                if ($noteOwner->pendingAttributes !== []) {
                    $out[] = rtrim($noteOwner->pendingPrefix);
                    foreach ($noteOwner->pendingAttributes as $attributes) {
                        $out[] = $noteOwner->pendingPrefix . $attributes;
                    }
                    $noteOwner->pendingAttributes = [];
                }
            }
            if ($noteOwner !== null && $fences[$n] !== $original) {
                $host = $noteOwner->depth > 0 ? strlen($this->djotClosedQuoteBoundary($original, $noteOwner->depth - 1)) : 0;
                $payload = substr($original, $host);
                if ($noteOwner->depth > 0) {
                    $payload = preg_replace('/^[ \t]/', '', $payload) ?? $payload;
                }
                $payloadIndent = strspn($payload, " \t");
                if (!isset($noteOwner->fenceColumn) && $payloadIndent <= $noteOwner->column && preg_match('/^(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)*(`{3,}|~{3,})/', substr($payload, $payloadIndent)) === 1) {
                    while ($noteOwner !== null && $payloadIndent <= $noteOwner->column) {
                        $ended = $endNote($notes, $pendingAttributes);
                        $noteOwner = $ended['owner'];
                        $returnedNoteItemAttributes = array_merge($returnedNoteItemAttributes, $ended['attributes']);
                        if ($returnedNoteItemAttributes !== []) {
                            $returnedNoteDestinationDepth = min($returnedNoteDestinationDepth ?? $ended['depth'], $ended['depth']);
                        }
                        $pendingAttributes = [];
                    }
                }
                if ($noteOwner !== null) {
                    $noteOwner->fenceColumn ??= $payloadIndent;
                    foreach ($returnedNoteItemAttributes as $attributes) {
                        $out[] = '  ' . $attributes;
                    }
                    $returnedNoteItemAttributes = [];
                    $returnedNoteDestinationDepth = null;
                    $out[] = '  ' . substr($payload, min($payloadIndent, $noteOwner->fenceColumn));
                    $paragraph = false;
                    $headingMarker = '';
                    $blank = false;

                    continue;
                }
            } elseif ($noteOwner !== null) {
                $noteOwner->fenceColumn = null;
            }
            if ($opaqueQuoteOwner !== null && $text !== '' && ($quoteDepth < $opaqueQuoteOwner['depth'] || ($quoteDepth === $opaqueQuoteOwner['depth'] ? $indent : ($quoteColumns[$opaqueQuoteOwner['depth']] ?? 0)) < $opaqueQuoteOwner['column'] + 1)) {
                $opaqueQuoteOwner = null;
            }
            $quoteForCarve = function () use ($quoteDepth, &$noteOwner, &$definitionIndent, $hasDefinitionSyntax, &$lists, &$opaqueQuoteOwner, $quoteColumns, $quoteBases, $embeddedOwners, $fences, $n, $original, $masked, $quote): string {
                $baseDepth = $noteOwner->depth ?? 0;
                $retainedQuote = $baseDepth > 0 ? (preg_replace('/^[ \t]/', '', substr($quote, strlen($this->djotClosedQuoteBoundary($quote, $baseDepth - 1)))) ?? '') : $quote;
                if ($quoteDepth === 0 || $definitionIndent >= 0 || $hasDefinitionSyntax || $fences[$n] !== $original || ($masked[strpos($quote, '>') ?: 0] ?? '') !== '>') {
                    return $baseDepth > 0 ? $retainedQuote : $quote;
                }
                $contexts = [];
                $owner = $lists !== [] ? $lists[count($lists) - 1] : null;
                if ($owner !== null) {
                    $contexts[] = ['depth' => $owner->depth, 'column' => $owner->column, 'content' => $owner->content, 'target' => $owner->target, 'sourcePrefix' => $owner->sourcePrefix];
                }
                foreach ($embeddedOwners->all() as $candidate) {
                    if ($candidate->depth >= $quoteDepth) {
                        break;
                    }
                    $contexts[] = ['depth' => $candidate->depth, 'column' => $candidate->column, 'content' => $candidate->content, 'target' => $candidate->target, 'sourcePrefix' => $candidate->sourcePrefix];
                }
                if ($noteOwner !== null) {
                    $contexts[] = ['depth' => $noteOwner->depth, 'column' => $noteOwner->column, 'content' => 2, 'target' => 0];
                }
                if ($opaqueQuoteOwner !== null) {
                    $contexts[] = $opaqueQuoteOwner + ['target' => $opaqueQuoteOwner['column']];
                }
                $firstOwned = $quoteDepth;
                $lastOwned = -1;
                foreach ($contexts as $context) {
                    $firstOwned = min($firstOwned, $context['depth']);
                    $lastOwned = max($lastOwned, $context['depth']);
                }
                $byDepth = [];
                foreach ($contexts as $candidate) {
                    $level = $candidate['depth'];
                    if (($quoteColumns[$level] ?? 0) + (isset($candidate['sourcePrefix']) ? ($quoteBases[$level] ?? 0) - $candidate['sourcePrefix'] : 0) > $candidate['column'] && (!isset($byDepth[$level]) || $candidate['column'] >= $byDepth[$level]['column'])) {
                        $byDepth[$level] = $candidate;
                    }
                }
                $parts = [];
                for ($level = $baseDepth; $level < $quoteDepth; ++$level) {
                    $held = $byDepth[$level] ?? null;
                    $column = $held !== null ? $held['target'] + $held['content'] : ($level > $firstOwned && $level < $lastOwned ? ($quoteColumns[$level] ?? 0) : 0);
                    $parts[] = str_repeat(' ', $column) . '>';
                    if ($level + 1 < $quoteDepth || !str_ends_with($quote, '>')) {
                        $parts[] = ' ';
                    }
                }

                return implode('', $parts);
            };
            $nativeRemainder = $rawIndent !== $indent && $fences[$n] === $original ? str_repeat(' ', $indent) . $text : $raw;
            $originalForCarve = static function (string $remainder) use ($quoteForCarve, &$lists, $noteOwner, $text, $quoteDepth, $noteHead, $fences, $n, $original, $indent): string {
                $ownedList = $lists !== [] ? $lists[array_key_last($lists)] : null;
                $noteColumn = $ownedList !== null && $ownedList->depth === $quoteDepth && $indent > $ownedList->column ? $ownedList->target + $ownedList->content : 2;

                return $quoteForCarve() . ($noteOwner !== null && $text !== '' && $quoteDepth === $noteOwner->depth && $noteHead === null ? ($fences[$n] === $original ? str_repeat(' ', $noteColumn) . $text : substr($remainder, min($indent, $noteOwner->column))) : $remainder);
            };
            if (preg_match('/^\[(?!\^)[^\]\n]*\]:/', $text) === 1 && $out !== [] && str_contains(end($out), $emptyNote)) {
                $out[] = $quoteForCarve() . str_repeat(' ', max(0, $indent));
            }
            $embeddedParent = $lists !== [] ? $lists[array_key_last($lists)] : null;
            if ($text !== '' && $fences[$n] === $original && $embeddedOwners->last() !== null && $embeddedParent !== null && $quoteDepth > $embeddedParent->depth && $listColumn($embeddedParent) <= $embeddedParent->column) {
                if ($paragraph && !$blank) {
                    $out[] = $this->djotClosedQuoteBoundary($quoteBoundary, max(0, $embeddedParent->depth - ($noteOwner->depth ?? 0)));
                }
                $embeddedOwners->clear();
                $embeddedListOwner = null;
                $paragraph = false;
                $headingMarker = '';
            }
            if ($text !== '' && $fences[$n] === $original) {
                $startsBlock = preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[|]|[`~]{3,}|[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)[ \t]|\[[^\]\n]+\]:)/', $visible) === 1;
                foreach ($embeddedOwners->all() as $i => $owner) {
                    $outside = $quoteDepth < $owner->depth || $listColumn($owner) <= $owner->column;
                    if ($outside && ($blank || !$paragraph || $quoteDepth > $owner->depth || $startsBlock)) {
                        $siblingItem = $quoteDepth === $owner->depth && preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $visible) === 1;
                        if ($paragraph && !$blank) {
                            $out[] = $this->djotClosedQuoteBoundary($quoteBoundary, max(0, $owner->depth - ($noteOwner->depth ?? 0) - 1 + ($siblingItem ? 1 : 0)));
                        }
                        $embeddedOwners->truncate($i);
                        $embeddedListOwner = $embeddedOwners->last();
                        $paragraph = false;
                        $headingMarker = '';

                        break;
                    }
                    if ($owner->depth >= $quoteDepth && !$blank && $paragraph && !$startsBlock) {
                        break;
                    }
                }
            }
            if ($returnedNoteItemAttributes !== []) {
                $retainedDepth = max(0, ($returnedNoteDestinationDepth ?? 0) - ($noteOwner->depth ?? 0));
                $returnPrefix = $retainedDepth > 0 ? $this->djotClosedQuoteBoundary($quoteForCarve(), $retainedDepth - 1) . ' ' : ($notes->isEmpty() ? '' : '  ');
                foreach ($returnedNoteItemAttributes as $attributes) {
                    $out[] = $returnPrefix . $attributes;
                }
                $returnedNoteItemAttributes = [];
                $returnedNoteDestinationDepth = null;
            }
            $blockIndent = $quoteDepth > $depth ? ($quoteColumns[$depth] ?? $indent) : $indent;
            $quoteList = $lists === [] ? null : $lists[array_key_last($lists)];
            if ($embeddedListOwner !== null && $embeddedListOwner->depth < $quoteDepth && ($quoteList === null || $embeddedListOwner->depth > $quoteList->depth)) {
                $quoteList = $embeddedListOwner;
            }
            $outsideQuoteItem = $quoteList !== null && $quoteDepth > $quoteList->depth && $listColumn($quoteList) < $quoteList->column + 1;
            if ($noteHead !== null) {
                foreach ($pendingAttributes as $attribute) {
                    if (array_key_exists($attribute['row'], $out)) {
                        $out[$attribute['row']] = '';
                    }
                }
                $pendingAttributes = [];
                if ($noteOwner === null && ($quotedNoteItem !== null || $inlineNoteItem !== null) && $physicalQuoteDepth < $depth) {
                    $out[] = $quoteBoundary;
                    $depth = $physicalQuoteDepth;
                    $quoteBoundary = rtrim($physicalQuote);
                }
                if ($quotedNoteItem !== null) {
                    $marker = $quotedNoteItem[2];
                    $column = strlen($quotedNoteItem[1]);
                    $context = new ListContext($column, strlen($this->compactDjotListPrefix($marker)), $column, $this->djotListKind(trim($marker)), $marker[0] === '*' ? '*' : '-', count($out), $physicalQuoteDepth, strlen($physicalQuote));
                    $lists[] = $context;
                    $out[] = $physicalQuote . $quotedNoteItem[1] . $this->compactDjotListPrefix($marker) . $quotedNoteItem[3] . $emptyNote;
                    $depth = $quoteDepth;
                    $quoteBoundary = rtrim($physicalQuote . str_repeat(' ', $context->target + $context->content) . $quotedNoteItem[3]);
                }
                if ($inlineNoteItem !== null) {
                    $parent = $lists !== [] ? $lists[array_key_last($lists)] : null;
                    $target = $parent !== null && $parent->depth === $quoteDepth && $parent->column === $indent ? $parent->target : $rawIndent;
                    $out[] = $quoteForCarve() . str_repeat(' ', $target) . $this->compactDjotListPrefix($inlineNoteItem[0]) . $emptyNote;
                }
                $noteIndent = $indent + ($inlineNoteItem !== null ? strlen($inlineNoteItem[0]) : 0);
                while ($lists !== [] && ($lists[array_key_last($lists)]->depth > $quoteDepth || ($lists[array_key_last($lists)]->depth === $quoteDepth && ($indent < $lists[array_key_last($lists)]->column || $inlineNoteItem === null && $indent === $lists[array_key_last($lists)]->column)))) {
                    array_pop($lists);
                }
                if ($inlineNoteItem === null && $quotedNoteItem === null && ($quoteDepth > 0 || ($lists === [] && $out !== [] && trim($out[array_key_last($out)]) !== ''))) {
                    $out[] = rtrim($quoteForCarve());
                }
                for ($pending = count($out) - 1; $pending >= 0; $pending--) {
                    $pendingText = trim($out[$pending]);
                    if ($pendingText === '') {
                        continue;
                    }
                    $attributes = str_starts_with($pendingText, '{') ? $this->readDjotWordAttributes($pendingText, 0) : null;
                    if ($attributes === null || $attributes['end'] !== strlen($pendingText)) {
                        break;
                    }
                    array_splice($out, $pending, 1);
                }
                $noteOwner = new NoteContext($quoteDepth, $noteIndent, $out, $lists, $divs, $depth, $quoteBoundary, $embeddedListOwner, $opaqueQuoteOwner, $definitionIndent, $noteOwner === null && $quoteDepth === 0 && $noteIndent === 0 && $lists === [] ? count($out) : null, $embeddedOwners);
                $notes->push($noteOwner);
                $out = $lists = $divs = [];
                $embeddedOwners = new EmbeddedListStack();
                $embeddedListOwner = $opaqueQuoteOwner = null;
                $definitionIndent = -1;
                $noteBodySourceAt = strlen($quote) + $rawIndent + ($inlineNoteItem !== null ? strlen($inlineNoteItem[0]) : 0);
                $nestedFirstBody = false;
                while (true) {
                    $body = $noteHead[2];
                    if (preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]*$/', $body) === 1) {
                        $body = rtrim($body, " \t") . ' +';
                    }
                    $childBody = $body;
                    $child = preg_match('/^((?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)(?=[^ \t])/', $body, $childMatch) === 1;
                    if ($child) {
                        $childKind = $this->djotListKind(trim($childMatch[1]));
                        if (preg_match('/^[-*+] $/', $childMatch[1]) === 1 && preg_match('/^\[[ xX]\](?:[ \t]+|$)/', substr($body, strlen($childMatch[1]))) === 1) {
                            $childKind .= ':task';
                        }
                        $lists[] = new ListContext($noteIndent + intdiv(strlen(mb_convert_encoding($noteHead[1], 'UTF-16LE', 'UTF-8')), 2), strlen($this->compactDjotListPrefix($childMatch[1])), 2, $childKind, $childMatch[1][0] === '*' ? '*' : '-', 0, $quoteDepth, strlen($quote));
                        $childBody = substr($body, strlen($childMatch[1]));
                        if (preg_match('/^[-*+] $/', $childMatch[1]) === 1) {
                            $childBody = preg_replace('/^\[[ xX]\](?:[ \t]+|$)/', '', $childBody) ?? $childBody;
                            if ($this->emptyDjotTaskTailEnd($body, !isset($lines[$n + 1]) || trim($lines[$n + 1]) === '') !== null && str_starts_with($childBody, '{')) {
                                $attributes = $this->readDjotWordAttributes($childBody, 0);
                                if ($attributes !== null && trim(substr($childBody, $attributes['end'])) === '') {
                                    $childBody = '';
                                }
                            }
                        }
                    }
                    $nestedHead = null;
                    if ($child && preg_match('/^(\[\^[^\]\n]+\]:[ \t]*)(.*)$/', $childBody, $nestedMatch) === 1) {
                        $nestedHead = $nestedMatch;
                    }
                    if (!$nestedFirstBody && $nestedHead !== null && isset($childMatch[1]) && $lists !== []) {
                        $out[] = $noteHead[1] . $this->compactDjotListPrefix($childMatch[1]) . $emptyNote;
                        $column = $lists[array_key_last($lists)]->column + strlen($childMatch[1]);
                        $noteOwner = new NoteContext($quoteDepth, $column, $out, $lists, $divs, $depth, $quoteBoundary, $embeddedListOwner, $opaqueQuoteOwner, $definitionIndent, null, $embeddedOwners);
                        $notes->push($noteOwner);
                        $out = [];
                        $lists = $divs = [];
                        $embeddedOwners = new EmbeddedListStack();
                        $embeddedListOwner = $opaqueQuoteOwner = null;
                        $definitionIndent = -1;
                        $noteBodySourceAt += intdiv(strlen(mb_convert_encoding($noteHead[1], 'UTF-16LE', 'UTF-8')), 2) + strlen($childMatch[1]);
                        $noteHead = $nestedHead;
                        $noteIndent = $column;
                        $nestedFirstBody = true;

                        continue;
                    }
                    if (preg_match('/^(`{3,}|~{3,})[ \t]*[a-zA-Z0-9_+#.-]*$/', $childBody) === 1) {
                        $noteOwner->fenceColumn = $noteOwner->column + 2;
                    }
                    $inlineQuote = $this->djotItemQuotePrefix($childBody);
                    $noteBodyStart = $noteBodySourceAt + intdiv(strlen(mb_convert_encoding($noteHead[1], 'UTF-16LE', 'UTF-8')), 2) + strlen($body) - strlen($childBody);
                    foreach ($inlineQuote['owners'] as $owner) {
                        $embeddedListOwner = new ListContext($owner['column'], $owner['content'], $owner['target'], '', '', 0, $quoteDepth + $owner['depth'], $noteBodyStart + $owner['sourceOffset'], $owner['sourceContent']);
                        $embeddedOwners->push($embeddedListOwner);
                    }
                    $depth = $quoteDepth + $inlineQuote['depth'];
                    $context = $child ? $lists[array_key_last($lists)] : null;
                    $contentColumn = $context !== null ? $context->target + $context->content : 2;
                    $quoteBoundary = rtrim($quoteForCarve() . str_repeat(' ', $contentColumn) . $inlineQuote['prefix']);
                    $content = $inlineQuote['body'];
                    $attributes = str_starts_with($content, '{') ? $this->readDjotWordAttributes($content, 0) : null;
                    if ($attributes !== null && $attributes['end'] === strlen($content)) {
                        $noteOwner->pendingAttributes[] = $attributes['source'];
                        $noteOwner->pendingPrefix = '  ' . ($child ? '' : $inlineQuote['prefix']);
                        $noteOwner->pendingParentPrefix = $quote;
                        $out[] = $noteHead[1] . substr($body, 0, strlen($body) - strlen($content)) . $emptyNote;
                    } else {
                        $emptyTaskEnd = $this->emptyDjotTaskTailEnd($body, !isset($lines[$n + 1]) || trim($lines[$n + 1]) === '');
                        $writtenBody = $this->compactDjotListPrefix($emptyTaskEnd === null ? $body : rtrim(substr($body, 0, $emptyTaskEnd), " \t") . ' ' . $emptyNote, $emptyTaskEnd !== null);
                        if ($emptyTaskEnd !== null && str_starts_with($body, '* ')) {
                            $writtenBody = '*' . substr($writtenBody, 1);
                            $out[] = $noteHead[1] . $emptyNote . "\n  " . $writtenBody;
                        } else {
                            $out[] = $noteHead[1] . ($child && $childBody === '+' ? $emptyNote . "\n  " : '') . $writtenBody;
                        }
                    }
                    if (preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $content, $inlineDiv) === 1) {
                        $divs[] = ['width' => strlen($inlineDiv[1]), 'prefix' => str_repeat(' ', $contentColumn) . $inlineQuote['prefix'], 'depth' => $depth, 'start' => $lineOffsets[$n], 'invalid' => $fenceParser->parseDivFenceOpener($content)['invalidMetadata'] ?? false] + ($context !== null ? ['itemColumn' => $context->column + 1] : []);
                    }
                    $paragraph = !($rows[$n] ?? false) && $content !== '' && !($child && $content === '+') && !(str_starts_with($content, '{') && ($this->readDjotWordAttributes($content, 0)['end'] ?? -1) === strlen($content)) && preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]*$/', $content) !== 1 && preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|\[[^\]\n]*\]:|(?:[*-][ \t]*){3,}$)/', $content) !== 1 && preg_match('/^(`{3,}|~{3,})[ \t]*[a-zA-Z0-9_+#.-]*$/', $content) !== 1;
                    preg_match('/^(#{1,6})(?:[ \t]|$)/', $content, $noteHeading);
                    $headingMarker = $noteHeading[1] ?? '';
                    $blank = !$paragraph;

                    break;
                }

                continue;
            }
            if ($text === '') {
                if ($noteOwner !== null && $quoteDepth <= $noteOwner->depth) {
                    $noteOwner->pendingPrefix = '  ';
                }
                while ($lists !== [] && $lists[array_key_last($lists)]->depth > $quoteDepth) {
                    array_pop($lists);
                }
                if ($embeddedListOwner !== null && $embeddedListOwner->depth > $quoteDepth) {
                    $embeddedOwners->trim($quoteDepth);
                    $embeddedListOwner = $embeddedOwners->last();
                }
                if ($outsideQuoteItem) {
                    while ($lists !== [] && $quoteDepth > $lists[array_key_last($lists)]->depth && $listColumn($lists[array_key_last($lists)]) < $lists[array_key_last($lists)]->column + 1) {
                        array_pop($lists);
                    }
                    if ($embeddedListOwner !== null && $quoteDepth > $embeddedListOwner->depth && $listColumn($embeddedListOwner) < $embeddedListOwner->column + 1) {
                        $embeddedOwners->clear();
                        $embeddedListOwner = null;
                    }
                    $depth = $quoteDepth;
                    $quoteBoundary = rtrim($quoteForCarve());
                }
                if ($paragraph && $quoteDepth > $depth && !$outsideQuoteItem) {
                    $onLiteralQuote?->__invoke($n, $depth);
                    $written = $originalForCarve($nativeRemainder);
                    $position = 0;
                    $heldDepth = max(0, $depth - ($notes->last()->depth ?? 0));
                    for ($level = 0; $level < $heldDepth; $level++) {
                        preg_match('/\G[ \t]*>[ \t]?/', $written, $heldQuote, offset: $position);
                        $position += strlen($heldQuote[0] ?? '');
                    }
                    $position += strspn($written, " \t", $position);
                    $out[] = substr($written, 0, $position) . '\\' . substr($written, $position);
                    $blank = false;

                    continue;
                }
                if ($emptyItemAt !== null && $quoteDepth > $emptyItemAt[0] && strspn($original, " \t") < $emptyItemAt[1] + 2) {
                    $out[] = $emptyItemAt[2];
                    $original = ltrim($original, " \t");
                    $paragraph = false;
                    $nativeRemainder = ltrim($nativeRemainder, " \t");
                    $lists = [];
                }
                while ($divs !== [] && $quoteDepth < $divs[array_key_last($divs)]['depth']) {
                    array_pop($divs);
                }
                $out[] = $originalForCarve($nativeRemainder);
                $paragraph = false;
                $headingMarker = '';
                $blank = true;
                $table = false;
                $emptyItemAt = null;

                continue;
            }
            if ($definitionIndent >= 0 && $quoteDepth < $depth && preg_match('/^(?:[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)[ \t]|:(?:[ \t]|$))/', $visible) === 1) {
                $definitionStack = [];
                $definitionIndent = -1;
                $paragraph = false;
                $blank = true;
                $depth = $quoteDepth;
            }
            if (($definitionStack !== [] ? $definitionStack[array_key_last($definitionStack)] : -1) !== $definitionIndent) {
                $definitionStack = $definitionIndent >= 0 ? [$definitionIndent] : [];
            }
            if (preg_match('/^:(?:[ \t]|$)/', $visible) === 1 && ($blank || $definitionIndent >= 0) && ($blank || $indent <= $definitionIndent)) {
                while ($definitionStack !== [] && $definitionStack[array_key_last($definitionStack)] >= $indent) {
                    array_pop($definitionStack);
                }
                $definitionStack[] = $indent;
                $definitionIndent = $indent;
            } elseif ($definitionIndent >= 0 && $blank && $indent <= $definitionIndent) {
                while ($definitionStack !== [] && $definitionStack[array_key_last($definitionStack)] >= $indent) {
                    array_pop($definitionStack);
                }
                $definitionIndent = $definitionStack !== [] ? $definitionStack[array_key_last($definitionStack)] : -1;
            }
            if ($definitionIndent >= 0 && $indent <= $definitionIndent && $this->rejectedDjotCommentStartsBlock($visible)) {
                $out[] = $quoteForCarve() . str_repeat(' ', $indent);
                $definitionIndent = -1;
                $paragraph = false;
                $blank = true;
            }
            if ($definitionIndent >= 0 && $indent <= $definitionIndent && isset($divClosers[$lineOffsets[$n]])) {
                $definitionStack = [];
                $definitionIndent = -1;
                $paragraph = false;
                $blank = true;
            }
            if ($definitionIndent >= 0) {
                $out[] = $originalForCarve($nativeRemainder);
                $depth = max($depth, $quoteDepth);
                $quoteBoundary = rtrim($quoteForCarve());
                $headingMarker = '';
                $paragraph = true;
                $blank = false;

                continue;
            }
            $rawFencedItem = preg_match('/^(?:[-*+][ \t]+(?:\[[ xX]\][ \t]+)?|(?:(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)(?:`{3,}|~{3,})[ \t]*=?[A-Za-z0-9_+#.-]*[ \t]*$/', $text) === 1;
            $rawReferenceItem = preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+\[(?!\^)[^\]\n]+\]:/', $text) === 1;
            $fencedItemOpener = $rawFencedItem && preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $visible) === 1;
            $wholeAttributes = str_starts_with($visible, '{') && ($this->readDjotWordAttributes($raw, $indent)['end'] ?? -1) === strlen($raw);
            $rawItem = preg_match('/^(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+[^ \t]/', $visible) === 1;
            $changesAttributeDepth = false;
            foreach ($pendingAttributes as $attribute) {
                $changesAttributeDepth = $changesAttributeDepth || $attribute['depth'] !== $quoteDepth;
            }
            $attributeOwner = false;
            foreach ($lists as $list) {
                $attributeOwner = $attributeOwner || $indent > $list->column;
            }
            if ($pendingAttributes !== [] && (!$wholeAttributes || !$attributeOwner) && ((!$rawItem && !$rawFencedItem && !$rawReferenceItem) || $changesAttributeDepth)) {
                foreach ($pendingAttributes as $attribute) {
                    $destinationDepth = min($attribute['depth'], $quoteDepth);
                    $column = $indent;
                    if ($quoteDepth > $attribute['depth']) {
                        $remainder = $original;
                        for ($level = 0; $level < $attribute['depth']; $level++) {
                            $remainder = substr(ltrim($remainder, " \t"), 1);
                            if (str_starts_with($remainder, ' ') || str_starts_with($remainder, "\t")) {
                                $remainder = substr($remainder, 1);
                            }
                        }
                        $column = strspn($remainder, " \t");
                    }
                    $owner = null;
                    for ($key = count($lists) - 1; $key >= 0; $key--) {
                        if ($column > $lists[$key]->column) {
                            $owner = $lists[$key];

                            break;
                        }
                    }
                    $target = $owner !== null ? $owner->target + $owner->content + max(0, $column - $owner->column - $owner->content) : $column;
                    $retainedDepth = max(0, $destinationDepth - ($noteOwner->depth ?? 0));
                    $attributePrefix = $retainedDepth === $quoteDepth - ($noteOwner->depth ?? 0) ? $quoteForCarve() : $this->djotClosedQuoteBoundary($quoteForCarve(), $retainedDepth - 1) . ($retainedDepth > 0 ? ' ' : '');
                    $this->replaceDjotLayoutLine($out, $attribute['row'], $attributePrefix . str_repeat(' ', $target) . $attribute['attributes']);
                }
                $pendingAttributes = [];
            }
            $row = ($rows[$n] ?? false) && !($noteOwner !== null && $paragraph && $quoteDepth >= $depth && !($lists !== [] && $indent <= $lists[array_key_last($lists)]->column));
            $blockStart = $outsideQuoteItem || ($quoteDepth > $depth && !$paragraph) || $row || $fences[$n] !== $original
                || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|\|)/', $visible) === 1
                || (preg_match('/^(?:[*-][ \t]*){3,}$/', $visible) === 1 && preg_match('/^(?:[*-][ \t]*){3,}$/', $text) === 1);
            if (
                ($blank || !$paragraph || ($lists !== [] && $quoteDepth === $depth))
                && preg_match('/^(?:[-*]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)])[ \t]*$/', $text) === 1
                && $visible === $text
                && $fences[$n] === $original
            ) {
                // Carve spells an empty item with a continuation marker. A lone
                // `+` already is that marker and stays as written.
                $text = rtrim($text, " \t") . ' +';
                $visible = $text;
                $paragraph = false;
                $emptyItem = true;
            }
            if (
                ($blank || !$paragraph || ($lists !== [] && $quoteDepth === $depth)) && $visible === $text && $fences[$n] === $original
                && preg_match('/^(?:[*-][ \t]*){3,}$/', $text) !== 1
                && preg_match('/^((?:(?:[-*]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)])[ \t]+)+)(?:[-*]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)])[ \t]*$/', $text, $emptyNested) === 1
            ) {
                $text = rtrim($text, " \t") . ' +';
                $visible = $text;
                $paragraph = false;
                $emptyItem = true;
                [$nestedWidth] = $this->leadingIndent((string)preg_replace('/[^ \t]/', ' ', $emptyNested[1]));
                $emptyItemColumn = $indent + $nestedWidth;
            }
            $item = preg_match('/^(?:([-*+])|((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)))[ \t]+[^ \t]/', $fencedItemOpener || $pendingAttributes !== [] && ($rawFencedItem || $rawReferenceItem) ? $text : $visible, $itemMatch) === 1;
            // The marker would attach a flush-left block; a blank keeps it out.
            if (
                $emptyItemAt !== null && !$emptyItem
                && (
                    ($quoteDepth === $emptyItemAt[0] && !$item && $indent <= $emptyItemAt[1])
                    || ($quoteDepth > $emptyItemAt[0] && $emptyItemAt[0] === $depth && strspn($original, " \t") <= $emptyItemAt[1])
                )
            ) {
                $out[] = $emptyItemAt[2];
            }
            $emptyItemAt = $emptyItem ? [$quoteDepth, $emptyItemColumn ?? $indent, rtrim($quote)] : null;
            if ($outsideQuoteItem) {
                $paragraph = false;
                $quoteBoundary = rtrim($quoteForCarve());
            }
            if ($embeddedListOwner !== null && $quoteDepth > $embeddedListOwner->depth && $quoteDepth >= $depth && $listColumn($embeddedListOwner) < $embeddedListOwner->column + 1) {
                $embeddedOwners->clear();
                $embeddedListOwner = null;
            }
            if ($quoteDepth < $depth && ($blockStart || $item || $this->rejectedDjotCommentStartsBlock($visible))) {
                while ($lists !== [] && $lists[array_key_last($lists)]->depth > $quoteDepth) {
                    array_pop($lists);
                }
                if ($embeddedListOwner !== null && $embeddedListOwner->depth > $quoteDepth) {
                    $embeddedOwners->trim($quoteDepth);
                    $embeddedListOwner = $embeddedOwners->last();
                }
                $owner = $lists === [] ? null : $lists[array_key_last($lists)];
                if ($item && !$blank && $paragraph && ($owner === null || $owner->depth !== $quoteDepth || $indent > $owner->column)) {
                    $out[] = $this->djotClosedQuoteBoundary($quoteBoundary, max(0, $quoteDepth - ($noteOwner->depth ?? 0)));
                } elseif ($this->rejectedDjotCommentStartsBlock($visible) && !$blank) {
                    $out[] = rtrim($quoteForCarve());
                }
                $paragraph = false;
                $headingMarker = '';
                $depth = $quoteDepth;
                $quoteBoundary = rtrim($quoteForCarve());
            }
            while ($divs !== [] && isset($divs[array_key_last($divs)]['itemColumn'])) {
                $owned = $divs[array_key_last($divs)];
                if ($quoteDepth >= $owned['depth'] && !($quoteDepth === $owned['depth'] && $indent < $owned['itemColumn'] && ($item || $blockStart || $blank))) {
                    break;
                }
                array_pop($divs);
                $before = count($out);
                while ($before > 0 && trim($out[$before - 1]) === '') {
                    $before--;
                }
                array_splice($out, $before, 0, [$owned['prefix'] . str_repeat(':', $owned['width'])]);
                $paragraph = false;
            }
            if ($blockStart && !(($pendingAttributes !== [] || $fencedItemOpener || $row) && $item)) {
                $listCount = count($lists);
                while ($lists !== [] && (($quoteDepth > $lists[array_key_last($lists)]->depth ? $listColumn($lists[array_key_last($lists)]) : $blockIndent) <= $lists[array_key_last($lists)]->column || ($listCount > 1 && ($quoteDepth > $lists[array_key_last($lists)]->depth ? $listColumn($lists[array_key_last($lists)]) : $blockIndent) < $lists[array_key_last($lists)]->column + 1))) {
                    array_pop($lists);
                    $listCount--;
                    $paragraph = false;
                    $headingMarker = '';
                    $parentKey = array_key_last($lists);
                    if ($parentKey !== null) {
                        $lists[$parentKey]->loose = true;
                    }
                }
                if ($blank) {
                    foreach ($lists as $list) {
                        if ($blockIndent > $list->column) {
                            $list->loose = true;
                        }
                    }
                }
            }
            if ($blockStart && $lists === []) {
                $embeddedOwners->clear();
                $embeddedListOwner = null;
            }
            $top = $lists === [] ? null : $lists[array_key_last($lists)];
            if (
                $top !== null && $quoteDepth > $top->depth
                && $listColumn($top) >= $top->column + 1
                && (!$paragraph || $quoteDepth === $depth)
            ) {
                $quoteBoundary = rtrim($quoteForCarve());
                $depth = $quoteDepth;
                if ($item && (!$paragraph || ($embeddedListOwner !== null && $embeddedListOwner->depth === $quoteDepth && $indent <= $embeddedListOwner->column))) {
                    $nativeRemainder = substr($raw, 0, $indent) . $this->compactDjotListPrefix($text);
                    $embeddedListOwner = new ListContext($indent, strlen($this->compactDjotListPrefix(substr($itemMatch[0], 0, -1))), 0, '', '', 0, $quoteDepth, strlen($quote), strlen($itemMatch[0]) - 1);
                    $embeddedOwners->trim($quoteDepth - 1);
                    $embeddedOwners->push($embeddedListOwner);
                }
                if ($noteOwner !== null && $paragraph && !$row && str_starts_with($visible, '|') && $fences[$n] === $original) {
                    $at = strspn($nativeRemainder, " \t");
                    $nativeRemainder = substr($nativeRemainder, 0, $at) . '\\' . substr($nativeRemainder, $at);
                }
                $out[] = $originalForCarve($nativeRemainder);
                $paragraph = !($row || $fences[$n] !== $original
                    || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,})/', $visible) === 1
                    || (preg_match('/^(?:[*-][ \t]*){3,}$/', $visible) === 1 && preg_match('/^(?:[*-][ \t]*){3,}$/', $text) === 1) || preg_match('/^\[[^\]\n]*\]:/', $visible) === 1 || (!$paragraph && str_starts_with($visible, '{') && ($this->readDjotWordAttributes($visible, 0)['end'] ?? null) === strlen($visible)));
                $blank = false;

                continue;
            }
            if (($fences[$n] !== $original || $row) && !(($pendingAttributes !== [] || $fencedItemOpener || $row) && $item)) {
                if (!$row || ($rows[$n - 1] ?? false) || !($rows[$n + 1] ?? false) || preg_match('/^\|(?:[ \t]*-+[ \t]*\|)+[ \t]*$/', $original) !== 1) {
                    $out[] = $row && !$item && $noteOwner === null && $lists === [] && $embeddedListOwner === null && $opaqueQuoteOwner === null ? $quoteForCarve() . $text : $originalForCarve($nativeRemainder);
                }
                $paragraph = false;
                $headingMarker = '';
                $blank = false;
                $table = $row;

                continue;
            }
            if ($table && preg_match('/^\+[ \t].*\|/', $text) === 1) {
                $out[] = $originalForCarve($nativeRemainder);

                continue;
            }
            $table = false;
            if (!$item && (preg_match('/\x00DJOTNOTEATTR\x00\d+\x00/', $text) === 1 || trim($view) === '' && str_starts_with($text, '{%'))) {
                $out[] = $originalForCarve($nativeRemainder);
                $headingMarker = '';
                $depth = $quoteDepth;
                $blank = !$paragraph;

                continue;
            }
            if ($paragraph && $quoteDepth > $depth) {
                $onLiteralQuote?->__invoke($n, $depth);
                $markerAt = strlen($this->djotClosedQuoteBoundary($quoteForCarve(), max(0, $depth - ($noteOwner->depth ?? 0)))) - 1;
                $written = $originalForCarve($nativeRemainder);
                $out[] = substr($written, 0, $markerAt) . '\\' . substr($written, $markerAt);
                $blank = false;

                continue;
            }
            if ($quoteDepth > $depth && !$paragraph) {
                $lists = [];
                $embeddedOwners->clear();
                $embeddedListOwner = null;
                $depth = $quoteDepth;
                $quoteBoundary = rtrim($quoteForCarve());
            } elseif ($quoteDepth < $depth && !$paragraph) {
                if ($blank) {
                    $out[] = rtrim($quoteForCarve());
                }
                while ($lists !== [] && $lists[array_key_last($lists)]->depth > $quoteDepth) {
                    array_pop($lists);
                }
                if ($embeddedListOwner !== null && $embeddedListOwner->depth > $quoteDepth) {
                    $embeddedOwners->trim($quoteDepth);
                    $embeddedListOwner = $embeddedOwners->last();
                }
                $depth = $quoteDepth;
                $quoteBoundary = rtrim($quoteForCarve());
            }
            if (trim($view) === '') {
                $out[] = $originalForCarve($nativeRemainder);
                $paragraph = $headingMarker === '' && preg_match('/^(?:(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+)?\[[^\]\n]+\]:/', $text) !== 1;
                $blank = false;

                continue;
            }
            $div = preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $visible, $divMatch) === 1;
            $topDiv = $divs === [] ? null : $divs[array_key_last($divs)];
            $closeStarts = $divClosers[$lineOffsets[$n]] ?? null;
            if ($div && $topDiv !== null && $closeStarts !== null && $closeStarts !== []) {
                $this->dropOrphanDjotAttributeLine($out);
                $matched = 0;
                $outerStart = $closeStarts[array_key_last($closeStarts)];
                while ($divs !== [] && $divs[array_key_last($divs)]['start'] >= $outerStart) {
                    $closed = array_pop($divs);
                    while (($closeStarts[$matched] ?? -1) > $closed['start']) {
                        $matched++;
                    }
                    if ($closed['start'] === ($closeStarts[$matched] ?? null)) {
                        $out[] = $closed['prefix'] . str_repeat(':', $closed['width']);
                        $matched++;
                    }
                }
                $paragraph = false;
                $headingMarker = '';
                $blank = true;

                continue;
            }
            if ($div && preg_match('/^:{3,}[ \t]*$/', $text) === 1 && $topDiv !== null && (strlen($divMatch[1]) >= $topDiv['width'] || $paragraph)) {
                if (strlen($divMatch[1]) >= $topDiv['width']) {
                    $width = strlen($divMatch[1]);
                    $this->dropOrphanDjotAttributeLine($out);
                    while ($divs !== [] && $width >= $divs[array_key_last($divs)]['width']) {
                        $closed = array_pop($divs);
                        $out[] = $closed['prefix'] . str_repeat(':', $closed['width']);
                        if ($closed['invalid'] || ($divs !== [] && $divs[array_key_last($divs)]['invalid'])) {
                            break;
                        }
                    }
                    $paragraph = false;
                    $headingMarker = '';
                    $blank = true;
                } else {
                    $out[] = $quoteForCarve() . str_repeat(' ', $noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : $indent) . '\\' . $text;
                    $paragraph = true;
                    $blank = false;
                }

                continue;
            }
            $rule = (preg_match('/^(?:[*-][ \t]*){3,}$/', $visible) === 1 && preg_match('/^(?:[*-][ \t]*){3,}$/', $text) === 1);
            if ($item && !$rule) {
                $carriedAttributes = $pendingAttributes;
                $pendingAttributes = [];
                foreach ($carriedAttributes as $attribute) {
                    preg_match('/^(?:[ \t]*>(?:[ \t]|$))*[ \t]*/', $out[$attribute['row']], $attributePrefix);
                    $this->replaceDjotLayoutLine($out, $attribute['row'], rtrim($attributePrefix[0] ?? ''));
                }
                $previousList = $lists === [] ? null : $lists[array_key_last($lists)];
                $endsNestedList = $previousList !== null && $indent < $previousList->column;
                while ($lists !== [] && $indent < $lists[array_key_last($lists)]->column) {
                    array_pop($lists);
                    $paragraph = false;
                    $headingMarker = '';
                }
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                $priorKind = $parent?->kind;
                if ($parent !== null && $blank && $indent === $parent->column && $endsNestedList && !$parent->loose) {
                    while ($out !== [] && trim(str_replace('>', '', $out[array_key_last($out)])) === '' && substr_count($out[array_key_last($out)], '>') <= $quoteDepth) {
                        array_pop($out);
                    }
                    if (!$parent->continuation && trim(str_replace('>', '', $out[$previousList->start - 1] ?? "\0")) === '') {
                        array_splice($out, $previousList->start - 1, 1);
                    }
                } elseif ($parent !== null && $blank && $indent === $parent->column) {
                    $parent->loose = true;
                }
                if (($parent === null || $indent > $parent->column) && $paragraph && !$blank) {
                    $target = $parent !== null ? $parent->target + $parent->content : ($noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : $indent);
                    $literal = $itemMatch[1] !== '' ? '\\' . $text : ($parent !== null ? (preg_replace('/[.)]/', '\\\\$0', $text, 1) ?? $text) : $text);
                    $out[] = $quoteForCarve() . str_repeat(' ', $target) . $literal;
                    if ($parent !== null) {
                        $parent->continuation = true;
                    }
                    $blank = false;

                    continue;
                }
                $kind = ($itemMatch[1] !== '' ? $itemMatch[1] : $this->djotListKind($itemMatch[2])) . ($itemMatch[1] !== '' && strlen($itemMatch[0]) === 3 && $itemMatch[0][1] === ' ' && preg_match('/^\[[ xX]\](?:[ \t]+|$)/', substr($text, strlen($itemMatch[0]) - 1)) === 1 ? ':task' : '');
                $nested = $parent !== null && $indent > $parent->column;
                $sharedKind = $this->sharedDjotListKind($priorKind, $kind);
                $sameKind = $sharedKind !== null;
                if ($parent !== null && !$nested && $sharedKind !== null) {
                    $kind = $sharedKind;
                    $parent->kind = $kind;
                }
                if ($parent === null || $nested) {
                    $definitionTarget = null;
                    if ($parent !== null && $blank) {
                        $previous = $n - 1;
                        while ($previous >= 0 && preg_match('/^[ \t>]*$/', $lines[$previous]) === 1 && substr_count($lines[$previous], '>') === $quoteDepth) {
                            $previous--;
                        }
                        if ($previous >= 0 && preg_match('/^((?:[ \t]*>(?:[ \t]|$))*)([ \t]*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]{2,}|[IVXLCDM]{2,})\))[ \t]+){2,}):(?:[ \t]|$)/', $lines[$previous], $head) === 1 && substr_count($head[1], '>') === $quoteDepth && strlen($head[2]) === $indent && ($mask[$previous][strlen($head[1]) + strlen($head[2])] ?? '') === ':') {
                            $definitionTarget = $parent->target + $indent - $parent->column;
                        }
                    }
                    $lists[] = new ListContext(
                        $indent,
                        strlen($this->compactDjotListPrefix(substr($itemMatch[0], 0, -1))),
                        $definitionTarget ?? ($nested ? $parent->target + $parent->content : ($noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : 0)),
                        $kind,
                        $itemMatch[1] === '*' ? '*' : '-',
                        count($out),
                        $quoteDepth,
                        $sourcePrefix,
                    );
                } elseif (!$sameKind) {
                    if (trim($out !== [] ? $out[array_key_last($out)] : '') !== '') {
                        $out[] = rtrim($quoteForCarve());
                    }
                    $parent->kind = $kind;
                    if (str_replace(':task', '', $kind) !== str_replace(':task', '', $priorKind ?? '')) {
                        $parent->bullet = $parent->bullet === '-' ? '*' : '-';
                    }
                }
                $contextKey = array_key_last($lists);
                $context = $lists[$contextKey];
                if ($parent !== null && !$nested && $parent->loose && $parent->continuation && trim($out !== [] ? $out[array_key_last($out)] : '') !== '') {
                    $out[] = rtrim($quoteForCarve());
                }
                $compact = $this->compactDjotListPrefix($text);
                $written = $itemMatch[1] !== '' ? $context->bullet . substr($compact, 1) : $compact;
                $allowTaskAttributes = !isset($lines[$n + 1]) || trim($lines[$n + 1]) === '';
                $emptyTaskEnd = $this->emptyDjotTaskTailEnd($text, $allowTaskAttributes);
                if ($emptyTaskEnd !== null) {
                    $writtenEnd = $this->emptyDjotTaskTailEnd($written, $allowTaskAttributes);
                    if ($writtenEnd !== null) {
                        $written = rtrim(substr($written, 0, $writtenEnd)) . ' ' . $emptyNote;
                    }
                } elseif (preg_match('/^[-*+]\s+\[[ xX]\][ \t]*$/', $written) === 1) {
                    $written = rtrim($written) . ' {empty}';
                }
                $context->continuation = false;
                $headingMarker = '';
                $attached = [];
                foreach ($carriedAttributes as $attribute) {
                    if ($parent !== null && !$nested && $sameKind && $attribute['depth'] === $quoteDepth && $attribute['owner'] >= $indent) {
                        $attached[] = substr($attribute['attributes'], 1, -1);
                    } else {
                        $out[] = $quoteForCarve() . str_repeat(' ', $context->target) . $attribute['attributes'];
                    }
                }
                if ($attached !== [] && $holdItemAttributes !== null) {
                    while ($out !== [] && trim(str_replace('>', '', $out[array_key_last($out)])) === '' && substr_count($out[array_key_last($out)], '>') <= $quoteDepth) {
                        array_pop($out);
                    }
                    if ($context->loose) {
                        $out[] = rtrim($quoteForCarve());
                    }
                    $out[] = $quoteForCarve() . str_repeat(' ', $context->target) . $holdItemAttributes('{' . implode(' ', $attached) . '}');
                    $out[] = rtrim($quoteForCarve());
                }
                $out[] = $quoteForCarve() . str_repeat(' ', $context->target) . $written;
                $body = substr($text, strlen($itemMatch[0]) - 1);
                $itemHeading = preg_match('/^(#{1,6})(?:[ \t]+|$)/', $body, $itemHeadingMatch) === 1;
                $headingMarker = $itemHeadingMatch[1] ?? '';
                $task = $itemMatch[1] !== '' && strlen($itemMatch[0]) === 3 && $itemMatch[0][1] === ' ' && str_starts_with($body, '[') && preg_match('/^\[[ xX]\][ \t]+/', $body, $taskMatch) === 1;
                $itemBody = $task ? substr($body, strlen($taskMatch[0])) : $body;
                $itemDiv = preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $itemBody, $itemDivMatch) === 1;
                if ($itemDiv) {
                    $divs[] = ['width' => strlen($itemDivMatch[1]), 'prefix' => $quoteForCarve() . str_repeat(' ', $context->target + $context->content), 'invalid' => $fenceParser->parseDivFenceOpener($itemBody)['invalidMetadata'] ?? false, 'itemColumn' => $context->column + 1, 'depth' => $quoteDepth, 'start' => $lineOffsets[$n]];
                }
                $itemQuoteDepth = 0;
                $quoteBody = '';
                $embeddedOwners->clear();
                $embeddedListOwner = null;
                if (str_starts_with($itemBody, '>')) {
                    $itemQuote = $this->djotItemQuotePrefix($itemBody);
                    foreach ($itemQuote['owners'] as $quoteOwner) {
                        $embeddedListOwner = new ListContext($quoteOwner['column'], $quoteOwner['content'], $quoteOwner['target'], '', '', 0, $quoteDepth + $quoteOwner['depth'], strlen($quote) + $rawIndent + strlen($itemMatch[0]) - 1 + ($task ? strlen($taskMatch[0]) : 0) + $quoteOwner['sourceOffset'], $quoteOwner['sourceContent']);
                        $embeddedOwners->push($embeddedListOwner);
                    }
                    $itemQuoteDepth = $itemQuote['depth'];
                    $quoteBody = $itemQuote['body'];
                    if ($itemQuoteDepth > 0) {
                        $depth = $quoteDepth + ($quoteBody !== '' ? $itemQuoteDepth : 0);
                        $quoteBoundary = rtrim($quoteForCarve() . str_repeat(' ', $context->target + $context->content) . $itemQuote['prefix']);
                    }
                }
                $itemAttributes = $this->readDjotWordAttributes($itemBody, 0);
                $paragraph = (!$emptyItem || $emptyItemColumn !== null) && !$rawFencedItem && !$row && !$itemDiv && !$itemHeading && ($itemAttributes === null || $itemAttributes['end'] !== strlen($itemBody))
                    && preg_match('/^\[(?!\^)[^\]\n]+\]:/', $itemBody) !== 1 && !str_contains($body, "\x00DJOTNOTEATTR\x00")
                    && !($itemQuoteDepth > 0 && ($quoteBody === '' || preg_match('/^\[[^\]\n]*\]:/', $quoteBody) === 1 || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[`~]{3,}|(?:[*-][ \t]*){3,}$)/', $quoteBody) === 1));
                if ($itemQuoteDepth === 0) {
                    $depth = $quoteDepth;
                }
                $blank = $itemDiv;

                continue;
            }
            if ($div && !$paragraph) {
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                $owned = $parent !== null && $indent >= $parent->column + 1;
                $divs[] = ['width' => strlen($divMatch[1]), 'prefix' => $quoteForCarve() . str_repeat(' ', $noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : $indent), 'invalid' => $fenceParser->parseDivFenceOpener($text)['invalidMetadata'] ?? false, 'depth' => $quoteDepth, 'start' => $lineOffsets[$n]] + ($owned ? ['itemColumn' => $parent->column + 1] : []);
                $out[] = $originalForCarve($nativeRemainder);
                $paragraph = false;
                $headingMarker = '';
                $blank = true;

                continue;
            }
            $dedent = $lists !== [] ? min($indent, $lists[0]->column - $lists[0]->target) : 0;
            if (preg_match('/^\x00DJOTITEMATTR\x00(?:\d+\x00)?(B?)\d+\x00$/', $text, $heldItemAttributes) === 1) {
                if ($heldItemAttributes[1] !== '' && ($out !== [] ? rtrim($out[array_key_last($out)]) : '') !== rtrim($quoteForCarve())) {
                    $out[] = rtrim($quoteForCarve());
                }
                $out[] = $originalForCarve($nativeRemainder);
                $paragraph = false;
                $blank = $heldItemAttributes[1] !== '';

                continue;
            }
            $attrs = str_starts_with($visible, '{') ? $this->readDjotWordAttributes($raw, $rawIndent) : null;
            $attributeLine = $attrs !== null && $attrs['end'] === strlen($raw);
            if ($attributeLine && !$paragraph && $holdItemAttributes !== null) {
                $owner = null;
                for ($key = count($lists) - 1; $key >= 0; $key--) {
                    if ($lists[$key]->depth === $quoteDepth && $indent > $lists[$key]->column) {
                        $owner = $lists[$key];

                        break;
                    }
                }
                if ($owner !== null && $attrs['source'] !== '{}') {
                    $heldLoose = preg_match('/\x00DJOTITEMATTR\x00(?:\d+\x00)?B\d+\x00$/', $out[$owner->start - 2] ?? '') === 1;
                    if ($blank && !$heldLoose && !str_contains($out[$owner->start], "{loose}\n")) {
                        $start = $out[$owner->start];
                        if ($noteOwner !== null && preg_match('/^(\[\^[^\]\n]+\]:[ \t]*)(.*)$/', $start, $noteItem) === 1) {
                            $this->replaceDjotLayoutLine($out, $owner->start, $noteItem[1] . "{loose}\n  " . $noteItem[2]);
                        } else {
                            $this->replaceDjotLayoutLine($out, $owner->start, $quoteForCarve() . str_repeat(' ', $owner->target) . "{loose}\n" . $start);
                        }
                        $owner->loose = true;
                    }
                    $pendingAttributes[] = ['owner' => $owner->column, 'depth' => $quoteDepth, 'row' => count($out), 'attributes' => $attrs['source']];
                }
            } elseif (trim($visible) !== '' && $pendingAttributes !== []) {
                $owner = null;
                for ($key = count($lists) - 1; $key >= 0; $key--) {
                    if ($indent > $lists[$key]->column) {
                        $owner = $lists[$key];

                        break;
                    }
                }
                $target = $owner !== null ? $owner->target + $owner->content + max(0, $indent - $owner->column - $owner->content) : $indent;
                foreach ($pendingAttributes as $attribute) {
                    $this->replaceDjotLayoutLine($out, $attribute['row'], $quoteForCarve() . str_repeat(' ', $target) . $attribute['attributes']);
                }
                $pendingAttributes = [];
            }
            if (($blank || !$paragraph) && !$this->djotListContains($lists, $indent) && !$attributeLine) {
                $lists = [];
                $embeddedOwners->clear();
                $embeddedListOwner = null;
            }
            if ($this->rejectedDjotCommentStartsBlock($visible)) {
                $outsideList = $lists !== [] && $indent <= $lists[0]->column;
                if ($headingMarker !== '' || $outsideList) {
                    $out[] = $quoteForCarve() . str_repeat(' ', $indent);
                }
                if ($outsideList) {
                    $lists = [];
                    $paragraph = false;
                    $blank = true;
                }
                $headingMarker = '';
            }
            $heading = preg_match('/^(#{1,6})(?:[ \t]+|$)/', $visible, $headingMatch) === 1;
            $reference = preg_match('/^\[[^\]\n]*\]:/', $visible) === 1;
            $block = ($heading && $headingMatch[1] !== $headingMarker) || $div || ($rule && (!str_contains($visible, '*') || !str_contains($visible, '-'))) || str_starts_with($visible, '>') || str_starts_with($visible, '|');
            if (($block && (!$heading || $headingMatch[1] !== $headingMarker)) || $attributeLine || $reference) {
                $headingMarker = '';
            }
            if ($blank && !$block && !$attributeLine && !$reference) {
                foreach ($lists as $list) {
                    if ($indent > $list->column) {
                        $list->loose = true;
                    }
                }
            }
            if ($paragraph && $block) {
                $out[] = $quoteForCarve() . str_repeat(' ', $noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : $indent) . '\\' . $text;
                $blank = false;

                continue;
            }
            if (!$paragraph && preg_match('/^\|`+\|$/', $text) === 1) {
                $leadingBlanks = 0;
                while (($out[0] ?? null) === '') {
                    array_shift($out);
                    $leadingBlanks++;
                }
                if ($leadingBlanks > 0) {
                    foreach ($lists as $list) {
                        $list->start = max(0, $list->start - $leadingBlanks);
                    }
                }
                $out[] = $quoteForCarve() . str_repeat(' ', $noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : $indent) . '\\' . $text;
                $paragraph = true;
                $blank = false;

                continue;
            }
            if ($heading) {
                $headingMarker = $headingMatch[1];
            }
            if ($heading && $lists === []) {
                $body = substr($text, strlen($headingMatch[1]));
                // Carve whitespace is space and tab only, so a vertical tab is heading content.
                $out[] = $quoteForCarve() . ($noteOwner !== null && $quoteDepth === $noteOwner->depth ? '  ' : '') . $headingMatch[1] . (trim($body, " \t") !== '' ? ' ' . ltrim($body, " \t") : '');
            } elseif ($rule && $lists === [] && !$paragraph) {
                $out[] = $quoteForCarve() . ($noteOwner !== null && $quoteDepth === $noteOwner->depth ? '  ' : '') . '***';
            } else {
                $embedded = null;
                foreach ($embeddedOwners->all() as $candidate) {
                    if ($candidate->depth > $quoteDepth) {
                        break;
                    }
                    if ($candidate->depth === $quoteDepth && $listColumn($candidate) > $candidate->column) {
                        $embedded = $candidate;

                        break;
                    }
                }
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                if ($embedded !== null) {
                    $out[] = $quoteForCarve() . str_repeat(' ', $embedded->target + $embedded->content + max(0, $listColumn($embedded) - $embedded->column - ($embedded->sourceContent ?? $embedded->content))) . $text;
                } else {
                    $out[] = $parent !== null && $indent > $parent->column
                    ? $quoteForCarve() . str_repeat(' ', $parent->target + $parent->content + max(0, $indent - $parent->column - $parent->content)) . $text
                    : ($noteOwner !== null && $quoteDepth === $noteOwner->depth ? $quoteForCarve() . '  ' . $text : ($dedent > 0 ? $quoteForCarve() . substr($raw, $dedent) : $originalForCarve($nativeRemainder)));
                }
            }
            $fence = preg_match('/^[`~]{3,}/', $text) === 1 && trim($visible) === '';
            $paragraph = !($heading || $headingMarker !== '' || $rule || $attributeLine || $reference || $fence || $text === '+' || preg_match('/^:[ \t]/', $visible) === 1 || $quoteDepth > $depth);
            if (!$paragraph && $quoteDepth > $depth) {
                $depth = $quoteDepth;
            }
            if ($blank && !$item && !$this->djotListContains($lists, $indent) && !$attributeLine) {
                $lists = [];
                $embeddedOwners->clear();
                $embeddedListOwner = null;
            }
            $blank = false;
        }
        while (!$notes->isEmpty()) {
            $ended = $endNote($notes, $pendingAttributes);
            $noteOwner = $ended['owner'];
            $returnedNoteItemAttributes = array_merge($returnedNoteItemAttributes, $ended['attributes']);
            if ($returnedNoteItemAttributes !== []) {
                $returnedNoteDestinationDepth = min($returnedNoteDestinationDepth ?? $ended['depth'], $ended['depth']);
            }
            $pendingAttributes = [];
        }
        $newline = ($out !== [] ? $out[array_key_last($out)] : null) === '';
        if ($divs !== [] && $newline) {
            array_pop($out);
        }
        if ($divs !== []) {
            $this->dropOrphanDjotAttributeLine($out);
        }
        while ($divs !== []) {
            $div = array_pop($divs);
            if (!str_contains($div['prefix'], '>')) {
                $out[] = $div['prefix'] . str_repeat(':', $div['width']);
            }
        }
        if ($newline && ($out !== [] ? $out[array_key_last($out)] : null) !== '') {
            $out[] = '';
        }

        $rendered = $placedNotes = [];
        $appendNote = static function (array $body) use (&$rendered): void {
            foreach ($body as $line) {
                $rendered[] = $line;
            }
            if ($rendered !== [] && $rendered[array_key_last($rendered)] !== '') {
                $rendered[] = '';
            }
        };
        foreach ($completedNotes as $note) {
            if ($note['placement'] === null) {
                $appendNote($note['body']);
            } else {
                $placedNotes[$note['placement']][] = $note['body'];
            }
        }
        for ($i = 0, $end = count($out); $i <= $end; $i++) {
            foreach ($placedNotes[$i] ?? [] as $body) {
                foreach ($body as $line) {
                    $rendered[] = $line;
                }
            }
            if ($i < $end) {
                $rendered[] = $out[$i];
            }
        }
        if (!str_ends_with($source, "\n") && $rendered !== [] && $rendered[array_key_last($rendered)] === '') {
            array_pop($rendered);
        }

        return implode("\n", $rendered);
    }

    private function djotClosedQuoteBoundary(string $prefix, int $retainedDepth): string
    {
        $at = -1;
        for ($level = 0; $level <= $retainedDepth; $level++) {
            $next = strpos($prefix, '>', $at + 1);
            if ($next === false) {
                return $prefix;
            }
            $at = $next;
        }

        return substr($prefix, 0, $at + 1);
    }

    private function emptyDjotFootnoteTaskAttributes(string $line): bool
    {
        $at = strpos($line, '[^');
        if ($at === false || preg_match('/^\[\^[^\]\n]+\]:[ \t]+(.*)$/', substr($line, $at), $head) !== 1) {
            return false;
        }

        return str_contains($head[1], '{') && $this->emptyDjotTaskTailEnd($head[1], true) !== null;
    }

    private function emptyDjotTaskTailEnd(string $text, bool $allowAttributes): ?int
    {
        $at = 0;
        $length = strlen($text);
        while ($at < $length) {
            if (preg_match('/\G(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $text, $marker, 0, $at) !== 1) {
                return null;
            }
            $at += strlen($marker[0]);
            if (preg_match('/^[-*+] $/', $marker[0]) === 1 && preg_match('/\G\[[ xX]\](?:[ \t]+|$)/', $text, $task, 0, $at) === 1) {
                $at += strlen($task[0]);
                if ($at === $length) {
                    return $at;
                }
                $attributes = $this->readDjotWordAttributes($text, $at);
                if ($allowAttributes && $attributes !== null && trim(substr($text, $attributes['end'])) === '') {
                    return $at;
                }
            }
        }

        return null;
    }

    private function compactDjotListPrefix(string $text, bool $canonicalBullets = false): string
    {
        if (!$canonicalBullets && !str_contains($text, '0') && !str_contains($text, '(') && !str_contains($text, "\t") && !str_contains($text, '[') && !str_contains($text, '+')) {
            return $text;
        }
        $parts = [];
        $at = 0;
        $length = strlen($text);
        while ($at < $length) {
            $indent = strspn($text, " \t", $at);
            $parts[] = substr($text, $at, $indent);
            $at += $indent;
            if (preg_match('/\G>(?:[ \t]|$)/', $text, $quote, 0, $at) === 1) {
                $parts[] = $quote[0];
                $at += strlen($quote[0]);

                continue;
            }
            if (preg_match('/\G(?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+/', $text, $item, 0, $at) !== 1) {
                break;
            }
            $compact = preg_replace('/^(\(?)0+(?=[0-9])/', '$1', $item[0]) ?? $item[0];
            $compact = preg_replace($canonicalBullets ? '/^[-*+](?=[ \t])/' : '/^\+(?=[ \t])/', '-', $compact) ?? $compact;
            $parts[] = str_replace("\t", ' ', preg_replace('/^\(([^)]+)\)/', '$1.', $compact) ?? $compact);
            $at += strlen($item[0]);
            if (in_array($item[0][0], ['-', '*', '+'], true) && preg_match('/\G\[[ xX\-_>?]\](?:[ \t]+|$)/', $text, $task, 0, $at) === 1) {
                $accepted = preg_match('/^[-*+] $/', $item[0]) === 1 && in_array($task[0][1], [' ', 'x', 'X'], true);
                $parts[] = $accepted ? str_replace("\t", ' ', $task[0]) : '\\' . $task[0];
                $at += strlen($task[0]);
                if (!$accepted) {
                    break;
                }
            }
        }
        $parts[] = substr($text, $at);

        return implode('', $parts);
    }

    /**
     * @return array{depth: int, prefix: string, body: string, owner: ?array{depth: int, column: int, content: int, sourceOffset: int, sourceContent: int, target: int}, owners: list<array{depth: int, column: int, content: int, sourceOffset: int, sourceContent: int, target: int}>}
     */
    private function djotItemQuotePrefix(string $body): array
    {
        $at = 0;
        $depth = 0;
        $prefix = '';
        $owner = null;
        $owners = [];
        while (preg_match('/\G(?:>(?:[ \t]+|$))+/A', $body, $quote, 0, $at) === 1) {
            $depth += substr_count($quote[0], '>');
            $prefix .= $quote[0];
            $at += strlen($quote[0]);
            if (preg_match('/\G((?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)(?:\[[ xX]\][ \t]+)?(?=[^ \t])/A', $body, $item, 0, $at) !== 1) {
                break;
            }
            $end = strlen($quote[0]);
            while ($end > 0 && ($quote[0][$end - 1] === ' ' || $quote[0][$end - 1] === "\t")) {
                --$end;
            }
            $owner = ['depth' => $depth, 'column' => max(0, strlen($quote[0]) - $end - 1), 'content' => strlen($this->compactDjotListPrefix($item[1])), 'sourceOffset' => $at - max(0, strlen($quote[0]) - $end - 1), 'sourceContent' => strlen($item[1]), 'target' => max(0, strlen($quote[0]) - $end - 1)];
            $owners[] = $owner;
            $next = $at + strlen($item[0]);
            if (preg_match('/\G>(?:[ \t]|$)/A', $body, $nextQuote, 0, $next) !== 1) {
                break;
            }
            $prefix .= str_repeat(' ', strlen($this->compactDjotListPrefix($item[1])));
            $at = $next;
        }

        return ['depth' => $depth, 'prefix' => $prefix, 'body' => substr($body, $at), 'owner' => $owner, 'owners' => $owners];
    }

    /**
     * @param string $line
     * @param int $depth
     * @param list<int> $bases
     *
     * @return list<int>
     */
    private function djotIndentColumnsAfterQuotes(string $line, int $depth, array &$bases): array
    {
        $at = 0;
        $columns = [];
        for ($level = 0; $level <= $depth; $level++) {
            $bases[] = $at;
            $width = strspn($line, " \t", $at);
            $columns[] = $width;
            $at += $width;
            if ($level < $depth) {
                $at++;
                if (($line[$at] ?? '') === ' ' || ($line[$at] ?? '') === "\t") {
                    $at++;
                }
            }
        }

        return $columns;
    }

    /**
     * Djot attaches an attribute line with no block after it to nothing, so drop it.
     *
     * @param list<string> $out
     */
    private function dropOrphanDjotAttributeLine(array &$out): void
    {
        if ($out === []) {
            return;
        }
        $last = rtrim($out[array_key_last($out)]);
        $at = strspn($last, " \t");
        if (($last[$at] ?? '') !== '{') {
            return;
        }
        $attrs = $this->readDjotWordAttributes($last, $at);
        if ($attrs !== null && $attrs['end'] === strlen($last)) {
            array_pop($out);
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Converter\Djot\ListContext> $lists
     * @param int $indent
     */
    private function djotListContains(array $lists, int $indent): bool
    {
        foreach ($lists as $list) {
            if ($indent > $list->column) {
                return true;
            }
        }

        return false;
    }

    private function normalizeDjotInlineComments(string $source, bool $removeAll = false): string
    {
        $comments = [];
        $this->maskCodeAndDestinations($source, opaqueOptions: [

            'onComment' => static function (int $start, int $end) use (&$comments): void {
                $comments[$start] = $end;
            },
        ]);
        foreach (array_reverse($comments, true) as $start => $end) {
            if ($removeAll) {
                $attrs = $this->readDjotWordAttributes($source, $start);
                if ($attrs !== null && $attrs['source'] !== '{}') {
                    continue;
                }
                $end = $attrs['end'] ?? $end;
            }
            if ($removeAll || ($source[$end - 2] !== '%' && str_contains(substr($source, $start + 2, $end - $start - 2), '{'))) {
                $source = substr($source, 0, $start) . '{%%}' . substr($source, $end);
            }
        }

        return $source;
    }

    /**
     * @param string $source
     * @param \Closure(int): bool|null $preserveQuote
     */
    private function normalizeDjotInlineSpellings(string $source, ?Closure $preserveQuote = null): string
    {
        $source = $this->normalizeDjotInlineComments($source);
        $mask = $this->djotEmphasisMask($source);
        $source = preg_replace_callback('/(?:\{\})+|[ \t]+\\\\[ \t]*(?=\n)|\{[\'\"]|[\'\"]\}/', function (array $match) use ($mask, $source, $preserveQuote): string {
            [$text, $at] = $match[0];
            if ($mask[$at] !== $source[$at] || trim(substr($mask, $at, strlen($text))) === '' || ($this->isDjotEscaped($source, $at) && !str_contains($text, '\\'))) {
                return $text;
            }
            if (str_contains($text, '\\')) {
                $start = strrpos(substr($source, 0, $at), "\n");
                $start = $start === false ? 0 : $start + 1;
                if (preg_match('/^(?:[ \t]*>[ ]?)*[ \t]*:{3,}$/', substr($source, $start, $at - $start)) === 1) {
                    return $text;
                }
                $end = $at + strcspn($text, '\\');
                while ($end > $at && !$this->isDjotEscaped($source, $end - 1)) {
                    $end--;
                }

                return substr($text, 0, $end - $at) . '\\';
            }
            if (str_starts_with($text, '{}')) {
                return $text;
            }

            if ($preserveQuote !== null && $preserveQuote($at)) {
                return $text;
            }

            return match ($text) {
                "{'" => '‘',
                "'}" => '’',
                '{"' => '“',
                '"}' => '”',
                default => '\\',
            };
        }, $source, -1, $count, PREG_OFFSET_CAPTURE) ?? $source;

        return $source;
    }

    private function padDjotCodeSpans(string $source): string
    {
        $mask = $this->maskCodeAndDestinations($source, false, opaqueOptions: ['code' => false], unclosedCode: false);
        preg_match_all('/`+/', $mask, $matches, PREG_OFFSET_CAPTURE);
        $next = $closers = [];
        for ($n = count($matches[0]) - 1; $n >= 0; $n--) {
            [$ticks, $at] = $matches[0][$n];
            $width = strlen($ticks);
            $closers[$at] = $next[$width] ?? null;
            if ($width > 1) {
                $closers[$at + 1] = $next[$width - 1] ?? null;
            }
            $next[$width] = $at;
        }
        preg_match_all('/\n[ \t]*(?:>[ \t]*)*\n/', $source, $boundaries, PREG_OFFSET_CAPTURE);
        $breaks = array_column($boundaries[0], 1);
        if (str_contains($source, "\n")) {
            foreach ($this->djotInlineBoundaries($source, $mask, true) as $boundary) {
                if ($boundary > 0 && $boundary < strlen($source) && $source[$boundary - 1] === "\n" && $source[$boundary] !== '|') {
                    $breaks[] = $boundary - 1;
                }
            }
            sort($breaks, SORT_NUMERIC);
        }
        $paragraph = 0;
        $sourceLength = strlen($source);
        $out = '';
        $copied = 0;
        $skip = 0;
        foreach ($matches[0] as [$ticks, $at]) {
            if ($at < $skip) {
                continue;
            }
            $width = strlen($ticks);
            if ($this->isDjotEscaped($source, $at)) {
                $at++;
                $width--;
            }
            if ($width === 0 || ($mask[$at] ?? '') !== '`') {
                continue;
            }
            while (($breaks[$paragraph] ?? $sourceLength) <= $at) {
                $paragraph++;
            }
            $paragraphEnd = $breaks[$paragraph] ?? $sourceLength;
            $close = $closers[$at] ?? null;
            if ($close === null || $close + $width > $paragraphEnd) {
                $skip = $paragraphEnd;

                continue;
            }
            $ticks = str_repeat('`', $width);
            $body = substr($source, $at + $width, $close - $at - $width);
            $skip = $close + $width;
            if (str_starts_with($body, ' ') && str_ends_with($body, ' ') && trim($body) !== '') {
                $before = str_starts_with($body, ' `') ? '' : ' ';
                $after = str_ends_with($body, '` ') ? '' : ' ';
                $out .= substr($source, $copied, $at - $copied) . $ticks . $before . $body . $after . $ticks;
                $copied = $skip;
            }
        }

        return $out . substr($source, $copied);
    }

    /**
     * @param list<string> $parts
     *
     * @return array<string, string>
     */
    private function djotReferenceAttributes(array $parts): array
    {
        $slots = [];
        foreach ($parts as $part) {
            $parsed = $part[0] === '.' ? ['class' => substr($part, 1)] : AttributeParser::parse($part);
            foreach ($parsed as $key => $value) {
                $value = is_array($value) ? implode(' ', $value) : $value;
                if ($part[0] === '.') {
                    if (isset($slots[$key]) && $slots[$key] !== '') {
                        $slots[$key] .= ' ' . $value;
                    } else {
                        $slots[$key] = $value;
                    }
                } else {
                    $slots[$key] = $value;
                }
            }
        }

        return $slots;
    }

    /**
     * @param list<list<string>> $groups
     *
     * @return array<string, string>
     */
    private function djotReferenceAttributeGroups(array $groups): array
    {
        $attributes = [];
        foreach ($groups as $parts) {
            foreach ($this->djotReferenceAttributes($parts) as $key => $value) {
                if ($key === 'class' && ($attributes[$key] ?? '') !== '') {
                    $attributes[$key] .= ' ' . $value;
                } else {
                    $attributes[$key] = $value;
                }
            }
        }

        return $attributes;
    }

    /**
     * @param array<string, string> $attributes
     * @param bool $table
     */
    private function writeDjotReferenceAttributes(array $attributes, bool $table = false): string
    {
        $parts = [];
        foreach ($attributes as $key => $value) {
            $quoted = preg_match('/^[A-Za-z0-9_-]+$/', $value) !== 1;
            $value = QuotedSlotEscaper::escape($value);
            if ($table) {
                $value = str_replace('|', '\\|', $value);
            }
            $parts[] = $key . '=' . ($quoted ? '"' . $value . '"' : $value);
        }

        return $parts === [] ? '' : '{' . implode(' ', $parts) . '}';
    }

    private function literalDjotReferenceAttributes(string $source, int $depth): string
    {
        $lines = explode("\n", $source);
        foreach ($lines as $index => &$line) {
            $at = 0;
            if ($index > 0) {
                for ($level = 0; $level < $depth; $level++) {
                    if (preg_match('/\G[ \t]*>(?:[ \t]|$)/', $line, $prefix, offset: $at) !== 1) {
                        break;
                    }
                    $at += strlen($prefix[0]);
                }
            }
            $tail = substr($line, $at);
            $line = substr($line, 0, $at) . preg_replace_callback('/[\x21-\x2F\x3A-\x40\x5B-\x60\x7B-\x7E]/', static fn (array $match): string => '\\' . $match[0], $tail);
        }
        unset($line);

        return implode("\n", $lines);
    }

    /**
     * @param string $source
     * @param bool $caretOnly
     * @param array<int, array{label: string, target: string, end: int}> $accepted
     * @param array<string, true> $inherited
     * @param array<string, string> $headingTargets
     * @param bool $aliasesOnly
     */
    private function normalizeDjotReferenceUses(
        string $source,
        bool $caretOnly = false,
        array $accepted = [],
        array $inherited = [],
        bool $aliasesOnly = false,
        array $headingTargets = [],
    ): string {
        $mask = $this->maskCodeAndDestinations($source);
        $definitions = [];
        $definitionLines = [];
        $sourceRows = str_contains($source, '|') ? $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false)) : [];
        $removed = [];
        $lines = explode("\n", $source);
        $offsets = [];
        $definitionOffset = 0;
        foreach ($lines as $line) {
            $offsets[] = $definitionOffset;
            $definitionOffset += strlen($line) + 1;
        }
        $attributeContinues = [];
        foreach ($lines as $n => $line) {
            $contentStart = $this->djotContentStart($line);
            $content = substr($line, $contentStart);
            if (str_starts_with($content, '{')) {
                $attrs = $this->readDjotWordAttributes($content, 0);
                if ($attrs !== null && $attrs['end'] === strlen($content)) {
                    $previousContent = substr($lines[$n - 1] ?? '', $this->djotContentStart($lines[$n - 1] ?? ''));
                    $attributeContinues[$n] = preg_match('/^:{3,}[ \t]*$/', $previousContent) !== 1 && preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', substr($line, 0, $contentStart)) !== 1 && $this->djotCommentContinuesParagraph($source, $offsets[$n] + $contentStart);
                }
            }
        }
        $quoteOpenings = [];
        $previousQuoteDepth = 0;
        foreach ($lines as $index => $line) {
            $depth = substr_count(substr($line, 0, $this->djotContentStart($line)), '>');
            if ($depth > $previousQuoteDepth) {
                $quoteOpenings[$index] = true;
            }
            $previousQuoteDepth = $depth;
        }
        $offset = 0;
        foreach ($lines as $n => $line) {
            $start = $this->djotContentStart($line);
            $previous = $lines[$n - 1] ?? '';
            $previousStart = $this->djotContentStart($previous);
            $previousContent = trim(substr($previous, $previousStart));
            $boundary = $previousContent === '' || ($sourceRows[$n - 1] ?? false)
                || preg_match('/(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]+/', substr($line, 0, $start)) === 1
                || isset($definitionLines[$n - 1])
                || preg_match('/^(?:#{1,6}(?: |$)|:{3,}|[`~]{3,}|\{|\[[^\]]+\]:|(?:[*-][ \t]*){3,}$)/', $previousContent) === 1
                || ($previousStart > $start && preg_match('/(?:>|[-*+] |[0-9A-Za-z]+[.)] |\([0-9A-Za-z]+\) )/', substr($previous, 0, $previousStart)) === 1);
            if (preg_match('/^\[(?!\^[^\]\n]+\]:(?:[ \t\r]|$))([^\]\n]+)\]:[ \t]*+([^ \t\r\n]*+)\r?$/', substr($line, $start), $definition) === 1 && ($mask[$offset + $start] ?? '') === '[' && ($caretOnly ? isset($accepted[$n]) && str_starts_with($definition[1], '^') : $boundary)) {
                $attributeParts = [];
                $attributeLines = [];
                $attributeDepth = null;
                for ($k = $n - 1; $k >= 0 && !$caretOnly && !$aliasesOnly; $k--) {
                    $attributeStart = $this->djotContentStart($lines[$k]);
                    $attributePrefix = substr($lines[$k], 0, $attributeStart);
                    $prefix = substr($line, 0, $start);
                    if (strpbrk($prefix, '-*+.):') !== false || substr_count($prefix, '>') > substr_count($attributePrefix, '>')) {
                        break;
                    }
                    $attributeContent = substr($lines[$k], $attributeStart);
                    $depth = substr_count($attributePrefix, '>');
                    if (trim($attributeContent) === '') {
                        if (isset($quoteOpenings[$k])) {
                            break;
                        }
                        if ($attributeDepth !== null && $depth < $attributeDepth) {
                            break;
                        }

                        continue;
                    }
                    if ($attributeDepth !== null && $depth < $attributeDepth) {
                        break;
                    }
                    if ($attributeContinues[$k] ?? false) {
                        break;
                    }
                    $parsed = $this->readDjotWordAttributes($attributeContent, 0, preserveShorthand: true);
                    if ($parsed === null || $parsed['end'] !== strlen($attributeContent)) {
                        break;
                    }
                    $attributeDepth = $depth;
                    $attributeParts[] = $parsed['parts'];
                    $attributeLines[] = $k;
                    if (isset($quoteOpenings[$k])) {
                        break;
                    }
                }
                if ($attributeLines !== []) {
                    $first = $attributeLines[count($attributeLines) - 1];
                    $beforeLine = $lines[$first - 1] ?? '';
                    $beforeStart = $this->djotContentStart($beforeLine);
                    $before = trim(substr($beforeLine, $beforeStart));
                    $noteAt = strpos(substr($beforeLine, 0, $beforeStart), '[^');
                    $ownerStart = $noteAt === false ? $beforeStart : $noteAt + 1;
                    preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?/', $beforeLine, $beforeQuotes);
                    preg_match('/^(?:(?:[ \t]*>)+[ \t]*)?/', $lines[$first], $firstQuotes);
                    $outdented = substr_count($firstQuotes[0] ?? '', '>') < substr_count($beforeQuotes[0] ?? '', '>') || $this->djotContentStart($lines[$first]) < $ownerStart && ($noteAt !== false || preg_match('/(?:[-*+] |[0-9A-Za-z]+[.)] |\([0-9A-Za-z]+\) )/', substr($beforeLine, 0, $beforeStart)) === 1);
                    if ($before !== '' && !$outdented && !isset($definitionLines[$first - 1]) && ($this->readDjotWordAttributes($before, 0)['end'] ?? null) !== strlen($before) && preg_match('/^(?:#{1,6}(?: |$)|:{3,}|[`~]{3,}|\||(?:[-*][ \t]*){3,}$)/', $before) !== 1) {
                        $tail = null;
                        foreach ($attributeLines as $index => $at) {
                            $previous = $lines[$at - 1] ?? '';
                            if (trim(substr($previous, $this->djotContentStart($previous))) === '') {
                                $tail = $index;
                            }
                        }
                        $attributeParts = $tail === null ? [] : array_slice($attributeParts, 0, $tail + 1);
                        $attributeLines = $tail === null ? [] : array_slice($attributeLines, 0, $tail + 1);
                    }
                }
                foreach ($attributeLines as $k) {
                    $removed[$k] = true;
                }
                $attrs = $this->djotReferenceAttributeGroups(array_reverse($attributeParts));
                $url = $definition[2];
                if (!$aliasesOnly && !$caretOnly) {
                    $protectedUrl = strtr($url, ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                    if ($protectedUrl !== $url) {
                        $urlAt = strlen($line) - strlen($url) - (str_ends_with($line, "\r") ? 1 : 0);
                        $lines[$n] = substr_replace($line, $protectedUrl, $urlAt, strlen($url));
                        $url = $protectedUrl;
                    }
                }
                $definitions[$this->djotReferenceDefinitionKey($definition[1])] = ['url' => $url, 'attrs' => $attrs, 'inherited' => $attrs !== [], 'line' => $n];
                $definitionLines[$n] = ['key' => $this->djotReferenceDefinitionKey($definition[1]), 'start' => $start, 'labelLength' => strlen($definition[1])];
            }
            $offset += strlen($line) + 1;
        }
        if ($definitions === [] && $headingTargets === []) {
            return $source;
        }
        foreach ($removed as $n => $unused) {
            $start = $this->djotContentStart($lines[$n]);
            $lines[$n] = $start > 0 ? substr($lines[$n], 0, $start) . '{%%}' : '';
        }
        $source = implode("\n", $lines);
        $mask = $this->maskCodeAndDestinations($source);
        $matches = [];
        $length = strlen($source);
        $escaped = str_repeat("\0", $length);
        $closers = [];
        $brackets = [];
        $labelClosers = [];
        $labelBarriers = [];
        $tablePipes = [];
        $pipeMask = str_contains($source, '|') ? $this->maskCodeAndDestinations($source, false, opaqueOptions: ['destinations' => false, 'autolinks' => false, 'attributeValues' => false, 'comments' => false]) : '';
        $tableRows = str_contains($source, '|') ? $this->djotTableRows($source, $pipeMask) : [];
        $quoteDepths = array_map(fn (string $line): int => substr_count(substr($line, 0, $this->djotContentStart($line)), '>'), $lines);
        if (max($quoteDepths) > 0) {
            $this->normalizeDjotStructure($source, onLiteralQuote: static function (int $line, int $depth) use (&$quoteDepths): void {
                $quoteDepths[$line] = $depth;
            });
        }
        $bracketDepths = [];
        $labelCache = [];
        $rowIndex = 0;
        $slashes = 0;
        for ($at = 0; $at < $length; $at++) {
            $escaped[$at] = $slashes % 2 === 0 ? "\0" : "\1";
            $slashes = $source[$at] === '\\' ? $slashes + 1 : 0;
            if (($tableRows[$rowIndex] ?? false) && $source[$at] === '|' && $pipeMask[$at] === '|' && $escaped[$at] === "\0") {
                $tablePipes[$at] = true;
                $brackets = [];
            }
            if ($source[$at] === "\n") {
                $rowIndex++;
            }
            if ($mask[$at] !== $source[$at] || $escaped[$at] !== "\0") {
                continue;
            }
            if ($source[$at] === '[') {
                $brackets[] = $at;
                $bracketDepths[$at] = $quoteDepths[$rowIndex] ?? 0;
            } elseif ($source[$at] === ']' && $brackets !== []) {
                $closers[array_pop($brackets)] = $at;
                if (($source[$at + 1] ?? '') === '[') {
                    $labelClosers[$at + 2] = -1;
                }
            }
        }
        $next = -1;
        $nextPipe = -1;
        for ($at = $length - 1; $at >= 0; $at--) {
            if ($source[$at] === '[' && $mask[$at] === '[' && $escaped[$at] === "\0") {
                $next = -1;
            } elseif ($source[$at] === ']' && $escaped[$at] === "\0") {
                $next = $at;
            }
            if (isset($tablePipes[$at])) {
                $nextPipe = $at;
            }
            if (isset($labelClosers[$at])) {
                $labelClosers[$at] = $next;
                $labelBarriers[$at] = $nextPipe;
            }
        }
        $scanNestedAliases = $aliasesOnly && str_contains($source, "\0U\0") && array_filter(array_keys($definitions), static fn ($key): bool => str_contains((string)$key, '�')) !== [];
        $nativeDefinitions = $definitions;
        if ($scanNestedAliases && str_contains($source, '#')) {
            $nativeDefinitions = [];
            $this->djotLosses($source, true, $nativeDefinitions);
        }
        $referenceLabels = [];
        $wrapperText = [];
        $aliasLabelText = function (int $open, int $close, int $depth) use ($scanNestedAliases, $source, $closers, $labelClosers, $escaped, $inherited, &$wrapperText, &$labelCache): string {
            if (isset($wrapperText[$open])) {
                return $wrapperText[$open];
            }
            if (!$scanNestedAliases || str_contains(substr($source, $open + 1, $close - $open - 1), "\n")) {
                return $this->djotCollapsedReferenceText(substr($source, $open + 1, $close - $open - 1), $depth, $inherited);
            }
            $wrappers = [];
            $current = $open;
            $end = $close;
            while (!isset($wrapperText[$current])) {
                $wrappers[] = $current;
                $child = ($source[$current + 1] ?? '') === '!' ? $current + 2 : $current + 1;
                $childClose = $closers[$child] ?? null;
                if (($source[$child] ?? '') !== '[' || $escaped[$child] !== "\0" || $childClose === null || ($source[$childClose + 1] ?? '') !== '[' || ($labelClosers[$childClose + 2] ?? -1) !== $end - 1 || ($source[$child + 1] ?? '') === '^') {
                    $label = substr($source, $current + 1, $end - $current - 1);
                    $cacheKey = $depth . ':' . $label;
                    $wrapperText[$current] = $labelCache[$cacheKey] ??= $this->djotCollapsedReferenceText($label, $depth, $inherited);

                    break;
                }
                $current = $child;
                $end = $childClose;
            }
            $value = $wrapperText[$current];
            foreach ($wrappers as $wrapper) {
                $wrapperText[$wrapper] = $value;
            }

            return $value;
        };
        for ($open = 0; $open < $length; $open++) {
            if (isset($referenceLabels[$open])) {
                continue;
            }
            $close = $closers[$open] ?? null;
            if ($close === null || ($source[$close + 1] ?? '') !== '[') {
                continue;
            }
            $labelEnd = $labelClosers[$close + 2] ?? -1;
            $barrier = $labelBarriers[$close + 2] ?? -1;
            if ($labelEnd < 0 || ($barrier >= 0 && $barrier < $labelEnd)) {
                continue;
            }
            $at = $open > 0 && $source[$open - 1] === '!' && $escaped[$open - 1] === "\0" ? $open - 1 : $open;
            $end = $labelEnd + 1;
            $own = '';
            $ownGroups = [];
            while (($source[$end] ?? '') === '{') {
                $attrs = $this->readDjotWordAttributes($source, $end, preserveShorthand: true);
                if ($attrs === null) {
                    break;
                }
                $own .= substr($source, $end, $attrs['end'] - $end);
                $ownGroups[] = $attrs['parts'];
                $end = $attrs['end'];
            }
            $explicit = substr($source, $close + 2, $labelEnd - $close - 2);
            $content = $aliasesOnly && $scanNestedAliases ? '' : substr($source, $open + 1, $close - $open - 1);
            if (($source[$open + 1] ?? '') === '^' && $close - $open > 2) {
                continue;
            }
            $label = $explicit === '' ? $content : $explicit;
            $parsedLabel = $explicit === '';
            $formatted = $parsedLabel && strpbrk($content, "_*`{~^\"'\\[]") !== false;
            if ($parsedLabel) {
                $depth = $bracketDepths[$open] ?? 0;
                if ($aliasesOnly && $scanNestedAliases) {
                    $label = $aliasLabelText($open, $close, $depth);
                } else {
                    $cacheKey = $depth . ':' . $label;
                    $label = $labelCache[$cacheKey] ??= $aliasLabelText($open, $close, $depth);
                }
            } else {
                $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label, " \t\r\n");
            }
            $key = $parsedLabel ? $this->djotReferenceDefinitionKey($label) : $this->djotReferenceKey($label);
            $matches[] = [
                [$aliasesOnly ? '' : substr($source, $at, $end - $at), $at],
                [$aliasesOnly ? '' : substr($source, $at, $close + 1 - $at), $at],
                [$content, $open + 1],
                [substr($source, $close + 2, $labelEnd - $close - 2), $close + 2],
                [$own, $labelEnd + 1],
                $this->djotReferenceAttributeGroups($ownGroups),
                $key,
                $formatted,
                $end,
                $close,
            ];
            $referenceLabels[$close + 1] = true;
            if (!$scanNestedAliases || !array_key_exists($key, $nativeDefinitions) || $explicit !== '' && !isset($definitions[$key])) {
                $open = $end - 1;
            }
        }
        $useCounts = [];
        $overrideCounts = [];
        foreach ($matches as $match) {
            $key = $match[6];
            if (($definitions[$key]['url'] ?? '') === '') {
                continue;
            }
            $useCounts[$key] = ($useCounts[$key] ?? 0) + 1;
            foreach ($match[5] as $attribute => $value) {
                $overrideCounts[$key][$attribute] = ($overrideCounts[$key][$attribute] ?? 0) + 1;
            }
        }
        foreach ($overrideCounts as $key => $counts) {
            foreach ($counts as $attribute => $count) {
                if ($count === $useCounts[$key]) {
                    unset($definitions[$key]['attrs'][$attribute]);
                }
            }
        }
        $occupied = array_fill_keys(array_keys($definitions), true);
        if ($caretOnly) {
            foreach ($accepted as $decision) {
                $occupied[$this->djotReferenceDefinitionKey($decision['label'])] = true;
            }
        }
        foreach ($matches as $match) {
            $occupied[$match[6]] = true;
        }
        $serial = 0;
        $nextAlias = static function () use (&$serial, &$occupied): string {
            do {
                $alias = 'carve-djot-reference-' . ++$serial;
            } while (isset($occupied[$alias]));
            $occupied[$alias] = true;

            return $alias;
        };
        $aliases = [];
        $needsAliases = false;
        $hasSourceNuls = str_contains($source, "\0U\0");
        foreach ($definitions as $key => $definition) {
            if ((str_starts_with((string)$key, '^') || str_contains((string)$key, '\\') || str_contains((string)$key, '|') || str_contains((string)$key, '[') || str_contains((string)$key, '`') || str_contains((string)$key, '{') || str_contains((string)$key, "\0") || $aliasesOnly && $hasSourceNuls && str_contains((string)$key, '�'))) {
                $needsAliases = true;

                break;
            }
        }
        if ($needsAliases) {
            foreach ($definitions as $key => $definition) {
                if (!(str_starts_with((string)$key, '^') || str_contains((string)$key, '\\') || str_contains((string)$key, '|') || str_contains((string)$key, '[') || str_contains((string)$key, '`') || str_contains((string)$key, '{') || str_contains((string)$key, "\0") || $aliasesOnly && $hasSourceNuls && str_contains((string)$key, '�'))) {
                    continue;
                }
                $aliases[$key] = $nextAlias();
            }
        }
        if ($aliasesOnly) {
            $edits = [];
            foreach ($definitionLines as $recordLine => $record) {
                if (isset($aliases[$record['key']])) {
                    $edits[] = [$offsets[$recordLine] + $record['start'] + 1, $record['labelLength'], $aliases[$record['key']]];
                }
            }
            foreach ($matches as $match) {
                $headingKey = str_replace("\0U\0", "\0", (string)$match[6]);
                if ($headingTargets !== [] && (str_contains($headingKey, "\0") || str_contains($headingKey, '�')) && !isset($definitions[$match[6]])) {
                    if (isset($headingTargets[$headingKey])) {
                        $start = $match[9] + 1;
                        $target = strtr($headingTargets[$headingKey], ['\\' => '\\\\', '(' => '\\(', ')' => '\\)']);
                        $edits[] = [$start, $match[4][1] - $start, '(' . $target . ')'];
                    } else {
                        $edits[] = [$match[2][1] - 1, 0, '\\'];
                        $edits[] = [$match[9] + 1, 0, '\\'];
                        $attributeAt = $match[4][1];
                        while ($attributeAt < $match[8]) {
                            $attributes = $this->readDjotWordAttributes($source, $attributeAt);
                            if ($attributes === null) {
                                break;
                            }
                            $literal = $attributes['parts'] !== []
                                ? $this->literalDjotReferenceAttributes(substr($source, $attributeAt, $attributes['end'] - $attributeAt), $bracketDepths[$match[2][1] - 1] ?? 0)
                                : '';
                            $edits[] = [$attributeAt, $attributes['end'] - $attributeAt, $literal];
                            $attributeAt = $attributes['end'];
                        }
                    }
                } elseif (isset($aliases[$match[6]])) {
                    $edits[] = [$match[3][1], strlen($match[3][0]), $aliases[$match[6]]];
                }
            }
            usort($edits, static fn (array $a, array $b): int => $a[0] <=> $b[0]);
            $result = '';
            $cursor = 0;
            foreach ($edits as [$at, $width, $value]) {
                $result .= substr($source, $cursor, $at - $cursor) . $value;
                $cursor = $at + $width;
            }

            return $result . substr($source, $cursor);
        }
        $movedDefinitions = [];
        $liftedAttributes = [];
        $classAliases = [];
        foreach ($definitions as $key => $definition) {
            if ($definition['url'] === '') {
                continue;
            }
            $line = $lines[$definition['line']];
            $start = $this->djotContentStart($line);
            if ($definition['attrs'] !== [] && $start > 0 && trim(substr($line, 0, $start)) !== '') {
                $alias = $aliases[$key] ?? $nextAlias();
                $aliases[$key] = $alias;
                $liftedAttributes[$key] = true;
                $movedDefinitions[] = [$key, '[' . $alias . ']: ' . $definition['url'] . ' ' . $this->writeDjotReferenceAttributes($definition['attrs'])];
            }
        }
        foreach ($definitions as $key => $definition) {
            if ($definition['url'] === '') {
                continue;
            }
            if (isset($definition['attrs']['class'], $overrideCounts[$key]['class'])) {
                $alias = $nextAlias();
                $classAliases[$key] = $alias;
                $attrs = $definition['attrs'];
                unset($attrs['class']);
                $attributes = $this->writeDjotReferenceAttributes($attrs);
                $movedDefinitions[] = [$key, '[' . $alias . ']: ' . $definition['url'] . ($attributes !== '' ? ' ' . $attributes : '')];
            }
        }
        $tableAt = [];
        $lineOffset = 0;
        $matchIndex = 0;
        foreach (explode("\n", $source) as $line) {
            $lineEnd = $lineOffset + strlen($line);
            while (isset($matches[$matchIndex]) && $matches[$matchIndex][0][1] <= $lineEnd) {
                $tableAt[$matches[$matchIndex][0][1]] = str_starts_with(substr($line, $this->djotContentStart($line)), '|');
                $matchIndex++;
            }
            $lineOffset = $lineEnd + 1;
        }
        $rewrite = function (array $match) use ($definitions, $aliases, $classAliases, $mask, $source, $tableAt, $caretOnly): string {
            [$text, $at] = $match[0];
            if (($mask[$at] ?? '') === ' ' || $this->isDjotEscaped($source, $at)) {
                return $text;
            }
            $key = $match[6];
            $definition = $definitions[$key] ?? null;
            if ($definition === null) {
                return $text;
            }
            if ($caretOnly) {
                return $match[1][0] . '[' . ($aliases[$key] ?? $key) . ']' . $match[4][0];
            }
            if ($definition['url'] !== '' && ($definition['inherited'] || $match[4][0] !== '')) {
                $referenceName = (isset($match[5]['class']) ? ($classAliases[$key] ?? null) : null) ?? $aliases[$key] ?? $key;
                $attrs = $this->writeDjotReferenceAttributes($match[5], $tableAt[$at] ?? false);

                return $match[1][0] . '[' . $referenceName . ']' . $attrs;
            }

            return $match[1][0] . '[' . ($aliases[$key] ?? ($match[3][0] === '' && !$match[7] ? '' : $key)) . ']' . ($match[4][0] ?? '');
        };
        $definitionEdits = [];
        $offset = 0;
        foreach ($lines as $n => $line) {
            if (isset($definitionLines[$n])) {
                $record = $definitionLines[$n];
                $key = $record['key'];
                $start = $record['start'];
                $prefix = substr($line, 0, $start);
                $alias = $aliases[$key] ?? null;
                if ($definitions[$key]['line'] !== $n) {
                    $definitionEdits[] = [$offset + $start, strlen($line) - $start, '{%%}'];
                } elseif (!isset($liftedAttributes[$key])) {
                    $name = $alias ?? substr($line, $start + 1, $record['labelLength']);
                    $attrs = $definitions[$key]['url'] !== '' ? $this->writeDjotReferenceAttributes($definitions[$key]['attrs']) : '';
                    $definition = '[' . $name . substr($line, $start + $record['labelLength'] + 1) . ($attrs !== '' ? ' ' . $attrs : '');
                    if ($start > 0 && trim($prefix) === '') {
                        $definitionEdits[] = [$offset, strlen($line), $prefix . '{%%}'];
                        $movedDefinitions[] = [$key, $definition];
                    } elseif ($alias !== null || $attrs !== '') {
                        $definitionEdits[] = [$offset + $start, strlen($line) - $start, $definition];
                    }
                }
            }
            $offset += strlen($line) + 1;
        }
        $output = '';
        $copied = 0;
        $definitionIndex = 0;
        foreach ($matches as $match) {
            while (isset($definitionEdits[$definitionIndex]) && $definitionEdits[$definitionIndex][0] < $match[0][1]) {
                [$at, $width, $replacement] = $definitionEdits[$definitionIndex++];
                $output .= substr($source, $copied, $at - $copied) . $replacement;
                $copied = $at + $width;
            }
            if ($match[0][1] < $copied) {
                continue;
            }
            $output .= substr($source, $copied, $match[0][1] - $copied) . $rewrite($match);
            $copied = $match[0][1] + strlen($match[0][0]);
        }
        while (isset($definitionEdits[$definitionIndex])) {
            [$at, $width, $replacement] = $definitionEdits[$definitionIndex++];
            if ($at < $copied) {
                continue;
            }
            $output .= substr($source, $copied, $at - $copied) . $replacement;
            $copied = $at + $width;
        }
        $source = $output . substr($source, $copied);

        $extra = [];
        foreach ($movedDefinitions as [$key, $definition]) {
            $extra[] = $definition;
        }
        if ($extra !== []) {
            $source = implode("\n", $extra) . "\n\n" . $source;
        }

        return $source;
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use Closure;
use MarkupCarve\Carve\Parser\Block\FencedBlockParser;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\Utility\QuotedSlotEscaper;

trait NormalizesDjotStructure
{
    private function hasDjotCodeLayoutCandidates(string $source): bool
    {
        return str_contains($source, '\\|') || (str_contains($source, '`') && preg_match('/(?:^|\n)(?:[ \t]*>(?:[ \t]|$))*[ \t]*\[\^[^\]\n]+\]:/', $source) === 1) || (str_contains($source, '`') && preg_match('/(?:^|\n)[ \t]*(?:(?:[-*+]|(?:[0-9]+|[A-Za-z]+)[.)]|\((?:[0-9]+|[A-Za-z]+)\))[ \t]+(?:\[[ xX]\][ \t]+)?|\[\^[^\]\n]+\]:[ \t]*)*>/', $source) === 1)
            || (str_contains($source, '```') && preg_match('/(?:^|\n)(?:[ \t]*>(?:[ \t]|$))*[ \t]+`{3,}/', $source) === 1);
    }

    /**
     * @param string $source
     * @param int $depth
     */
    private function djotDestinationLines(string $source, int $depth): string
    {
        if (!str_contains($source, "\n")) {
            return $source;
        }
        $rawDestination = $source;
        $rawDestination = preg_replace_callback('/\\\\(?:\r?\n|[^\r\n])/', static fn (array $match): string => str_ends_with($match[0], "\n") ? "\n" : $match[0], $rawDestination) ?? $rawDestination;
        $destination = preg_replace_callback('/\n([ \t]*[^\n]*)/', static function (array $match) use ($depth): string {
            $rest = ltrim($match[1], " \t");
            for ($n = 0; $n < $depth && preg_match('/^>(?:[ \t]|$)/', $rest); $n++) {
                $rest = ltrim(substr($rest, 1), " \t");
            }

            return $rest;
        }, $rawDestination) ?? $rawDestination;

        return $destination;
    }

    private function normalizeDjotStructure(string $source, ?Closure $isBoundary = null, string $emptyNote = '%%%%'): string
    {
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
        $paragraph = false;
        $blank = true;
        $depth = 0;
        $embeddedOwners = new DjotEmbeddedListStack();
        $embeddedListOwner = null;
        $opaqueQuoteOwner = null;
        $quoteBoundary = '';
        $headingMarker = '';
        $definitionIndent = -1;
        $noteOwner = null;
        $notes = new DjotNoteStack();
        $completedNotes = [];
        $endNote = function (DjotNoteStack $notes) use (&$out, &$completedNotes, &$lists, &$divs, &$depth, &$quoteBoundary, &$embeddedListOwner, &$embeddedOwners, &$opaqueQuoteOwner, &$definitionIndent, &$paragraph, &$headingMarker): ?DjotNoteContext {
            $ended = $notes->pop();
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

            return $notes->last();
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
            $listColumn = static fn (DjotListContext $owner): int => $quoteDepth > $owner->depth ? ($quoteColumns[$owner->depth] ?? $rawIndent) + ($owner->sourcePrefix === null ? 0 : ($quoteBases[$owner->depth] ?? 0) - $owner->sourcePrefix) : ($owner->sourcePrefix === null ? $indent : strlen($quote) + $rawIndent - $owner->sourcePrefix);
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
                $block = ($isBoundary !== null && $isBoundary($original)) || $noteHead !== null || $quoteDepth > $noteOwner->depth || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[|]|[`~]{3,}|[-*+][ \t]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)][ \t]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)[ \t]|\[[^\]\n]+\]:)/', $visible) === 1
                    || (str_starts_with($visible, '{') && ($this->readDjotWordAttributes($visible, 0)['end'] ?? -1) === strlen($visible));
                if ($outside && !($paragraph && !$blank && !$block && $quoteDepth <= $noteOwner->depth)) {
                    $noteOwner = $endNote($notes);
                    if ($noteOwner === null && str_starts_with($visible, '{') && ($this->readDjotWordAttributes($visible, 0)['end'] ?? -1) === strlen($visible) && $out !== [] && str_contains(end($out), $emptyNote)) {
                        $out[] = $quote . str_repeat(' ', $indent);
                    }
                } else {
                    break;
                }
            }
            if ($noteOwner !== null && $fences[$n] !== $original) {
                $host = $noteOwner->depth > 0 ? strlen($this->djotClosedQuoteBoundary($original, $noteOwner->depth - 1)) : 0;
                $payload = substr($original, $host);
                if ($noteOwner->depth > 0) {
                    $payload = preg_replace('/^[ \t]/', '', $payload) ?? $payload;
                }
                $payloadIndent = strspn($payload, " \t");
                if (!isset($noteOwner->fenceColumn) && $payloadIndent <= $noteOwner->column && preg_match('/^(`{3,}|~{3,})/', substr($payload, $payloadIndent)) === 1) {
                    while ($noteOwner !== null && $payloadIndent <= $noteOwner->column) {
                        $noteOwner = $endNote($notes);
                    }
                }
                if ($noteOwner !== null) {
                    $noteOwner->fenceColumn ??= $payloadIndent;
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
            $blockIndent = $quoteDepth > $depth ? ($quoteColumns[$depth] ?? $indent) : $indent;
            $quoteList = $lists === [] ? null : $lists[array_key_last($lists)];
            if ($embeddedListOwner !== null && $embeddedListOwner->depth < $quoteDepth && ($quoteList === null || $embeddedListOwner->depth > $quoteList->depth)) {
                $quoteList = $embeddedListOwner;
            }
            $outsideQuoteItem = $quoteList !== null && $quoteDepth > $quoteList->depth && $listColumn($quoteList) < $quoteList->column + 1;
            if ($noteHead !== null) {
                if ($noteOwner === null && ($quotedNoteItem !== null || $inlineNoteItem !== null) && $physicalQuoteDepth < $depth) {
                    $out[] = $quoteBoundary;
                    $depth = $physicalQuoteDepth;
                    $quoteBoundary = rtrim($physicalQuote);
                }
                if ($quotedNoteItem !== null) {
                    $marker = $quotedNoteItem[2];
                    $column = strlen($quotedNoteItem[1]);
                    $context = new DjotListContext($column, strlen($this->compactDjotListPrefix($marker)), $column, preg_replace('/[0-9A-Za-z]+/', '1', trim($marker)) ?? '', $marker[0] === '*' ? '*' : '-', count($out), $physicalQuoteDepth, strlen($physicalQuote));
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
                $noteOwner = new DjotNoteContext($quoteDepth, $noteIndent, $out, $lists, $divs, $depth, $quoteBoundary, $embeddedListOwner, $opaqueQuoteOwner, $definitionIndent, $noteOwner === null && $quoteDepth === 0 && $noteIndent === 0 && $lists === [] ? count($out) : null, $embeddedOwners);
                $notes->push($noteOwner);
                $out = $lists = $divs = [];
                $embeddedOwners = new DjotEmbeddedListStack();
                $embeddedListOwner = $opaqueQuoteOwner = null;
                $definitionIndent = -1;
                $noteBodySourceAt = strlen($quote) + $rawIndent + ($inlineNoteItem !== null ? strlen($inlineNoteItem[0]) : 0);
                $nestedFirstBody = false;
                while (true) {
                    $body = $noteHead[2];
                    $childBody = $body;
                    $child = preg_match('/^((?:[-*+]|(?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\))[ \t]+)(?=[^ \t])/', $body, $childMatch) === 1;
                    if ($child) {
                        $lists[] = new DjotListContext($noteIndent + intdiv(strlen(mb_convert_encoding($noteHead[1], 'UTF-16LE', 'UTF-8')), 2), strlen($this->compactDjotListPrefix($childMatch[1])), 2, preg_replace('/[0-9A-Za-z]+/', '1', trim($childMatch[1])) ?? '', $childMatch[1][0] === '*' ? '*' : '-', 0, $quoteDepth, strlen($quote));
                        $childBody = substr($body, strlen($childMatch[1]));
                        if (preg_match('/^[-*+]/', $childMatch[1]) === 1) {
                            $childBody = preg_replace('/^\[[ xX]\][ \t]+/', '', $childBody) ?? $childBody;
                        }
                    }
                    $nestedHead = null;
                    if ($child && preg_match('/^(\[\^[^\]\n]+\]:[ \t]*)(.*)$/', $childBody, $nestedMatch) === 1) {
                        $nestedHead = $nestedMatch;
                    }
                    if (!$nestedFirstBody && $nestedHead !== null && isset($childMatch[1]) && $lists !== []) {
                        $out[] = $noteHead[1] . $this->compactDjotListPrefix($childMatch[1]) . $emptyNote;
                        $column = $lists[array_key_last($lists)]->column + strlen($childMatch[1]);
                        $noteOwner = new DjotNoteContext($quoteDepth, $column, $out, $lists, $divs, $depth, $quoteBoundary, $embeddedListOwner, $opaqueQuoteOwner, $definitionIndent, null, $embeddedOwners);
                        $notes->push($noteOwner);
                        $out = [];
                        $lists = $divs = [];
                        $embeddedOwners = new DjotEmbeddedListStack();
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
                        $embeddedListOwner = new DjotListContext($owner['column'], $owner['content'], $owner['target'], '', '', 0, $quoteDepth + $owner['depth'], $noteBodyStart + $owner['sourceOffset'], $owner['sourceContent']);
                        $embeddedOwners->push($embeddedListOwner);
                    }
                    $depth = $quoteDepth + $inlineQuote['depth'];
                    $context = $child ? $lists[array_key_last($lists)] : null;
                    $contentColumn = $context !== null ? $context->target + $context->content : 2;
                    $quoteBoundary = rtrim($quoteForCarve() . str_repeat(' ', $contentColumn) . $inlineQuote['prefix']);
                    $out[] = $noteHead[1] . $this->compactDjotListPrefix($body);
                    $content = $inlineQuote['body'];
                    if (preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $content, $inlineDiv) === 1) {
                        $divs[] = ['width' => strlen($inlineDiv[1]), 'prefix' => str_repeat(' ', $contentColumn) . $inlineQuote['prefix'], 'depth' => $depth, 'start' => $lineOffsets[$n], 'invalid' => $fenceParser->parseDivFenceOpener($content)['invalidMetadata'] ?? false] + ($context !== null ? ['itemColumn' => $context->column + 1] : []);
                    }
                    $paragraph = !($rows[$n] ?? false) && $content !== '' && preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|\[[^\]\n]*\]:|(?:[*-][ \t]*){3,}$)/', $content) !== 1 && preg_match('/^(`{3,}|~{3,})[ \t]*[a-zA-Z0-9_+#.-]*$/', $content) !== 1;
                    preg_match('/^(#{1,6})(?:[ \t]|$)/', $content, $noteHeading);
                    $headingMarker = $noteHeading[1] ?? '';
                    $blank = !$paragraph;

                    break;
                }

                continue;
            }
            if ($text === '') {
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
                if ($emptyItemAt !== null && $quoteDepth > $emptyItemAt[0] && strspn($original, " \t") < $emptyItemAt[1] + 2) {
                    $out[] = $emptyItemAt[2];
                    $original = ltrim($original, " \t");
                    $paragraph = false;
                    $nativeRemainder = ltrim($nativeRemainder, " \t");
                    $lists = [];
                }
                if ($paragraph && $quoteDepth > $depth && !$outsideQuoteItem) {
                    $markerAt = strlen($this->djotClosedQuoteBoundary($quoteForCarve(), max(0, $depth - ($noteOwner->depth ?? 0)))) - 1;
                    $written = $originalForCarve($nativeRemainder);
                    $out[] = substr($written, 0, $markerAt) . '\\' . substr($written, $markerAt);
                    $blank = false;

                    continue;
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
            if (preg_match('/^:[ \t]+[^ \t]/', $visible) === 1 && ($blank || $definitionIndent >= 0)) {
                $definitionIndent = $indent;
            } elseif ($definitionIndent >= 0 && $blank && $indent <= $definitionIndent) {
                $definitionIndent = -1;
            }
            if ($definitionIndent >= 0 && $indent <= $definitionIndent && $this->rejectedDjotCommentStartsBlock($visible)) {
                $out[] = $quoteForCarve() . str_repeat(' ', $indent);
                $definitionIndent = -1;
                $paragraph = false;
                $blank = true;
            }
            if ($definitionIndent >= 0) {
                $out[] = $originalForCarve($nativeRemainder);
                $headingMarker = '';
                $paragraph = true;
                $blank = false;

                continue;
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
            $item = preg_match('/^(?:([-*+])|((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)[.)]|\((?:[0-9]+|[A-Za-z]|[ivxlcdm]+|[IVXLCDM]+)\)))[ \t]+[^ \t]/', $visible, $itemMatch) === 1;
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
            if ($blockStart) {
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
                    $embeddedListOwner = new DjotListContext($indent, strlen($this->compactDjotListPrefix(substr($itemMatch[0], 0, -1))), 0, '', '', 0, $quoteDepth, strlen($quote), strlen($itemMatch[0]) - 1);
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
            if ($fences[$n] !== $original || $row) {
                if ($row && $item) {
                    $nativeRemainder = substr($raw, 0, $indent) . $this->compactDjotListPrefix($text);
                    $opaqueQuoteOwner = ['depth' => $quoteDepth, 'column' => $indent, 'content' => strlen($this->compactDjotListPrefix(substr($itemMatch[0], 0, -1)))];
                }
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
                $previousList = $lists === [] ? null : $lists[array_key_last($lists)];
                $endsNestedList = $previousList !== null && $indent < $previousList->column;
                while ($lists !== [] && $indent < $lists[array_key_last($lists)]->column) {
                    array_pop($lists);
                    $paragraph = false;
                    $headingMarker = '';
                }
                $parent = $lists === [] ? null : $lists[array_key_last($lists)];
                if ($parent !== null && $blank && $indent === $parent->column && $endsNestedList && !$parent->loose) {
                    while ($out !== [] && trim($out[array_key_last($out)]) === '') {
                        array_pop($out);
                    }
                    if (!$parent->continuation && trim($out[$previousList->start - 1] ?? "\0") === '') {
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
                $kind = $itemMatch[1] !== '' ? $itemMatch[1] : (preg_replace('/[0-9A-Za-z]+/', '1', $itemMatch[2]) ?? $itemMatch[2]);
                $nested = $parent !== null && $indent > $parent->column;
                if ($parent === null || $nested) {
                    $lists[] = new DjotListContext(
                        $indent,
                        strlen($this->compactDjotListPrefix(substr($itemMatch[0], 0, -1))),
                        $nested ? $parent->target + $parent->content : ($noteOwner !== null && $quoteDepth === $noteOwner->depth ? 2 : 0),
                        $kind,
                        $itemMatch[1] === '*' ? '*' : '-',
                        count($out),
                        $quoteDepth,
                        $sourcePrefix,
                    );
                } elseif ($kind !== $parent->kind) {
                    if (trim($out !== [] ? $out[array_key_last($out)] : '') !== '') {
                        $out[] = rtrim($quoteForCarve());
                    }
                    $parent->kind = $kind;
                    $parent->bullet = $parent->bullet === '-' ? '*' : '-';
                }
                $contextKey = array_key_last($lists);
                $context = $lists[$contextKey];
                if ($parent !== null && !$nested && $parent->loose && $parent->continuation && trim($out !== [] ? $out[array_key_last($out)] : '') !== '') {
                    $out[] = rtrim($quoteForCarve());
                }
                $compact = $this->compactDjotListPrefix($text);
                $written = $itemMatch[1] !== '' ? $context->bullet . substr($compact, 1) : $compact;
                $context->continuation = false;
                $headingMarker = '';
                $out[] = $quoteForCarve() . str_repeat(' ', $context->target) . $written;
                $body = substr($text, strlen($itemMatch[0]) - 1);
                $itemHeading = preg_match('/^(#{1,6})(?:[ \t]+|$)/', $body, $itemHeadingMatch) === 1;
                $headingMarker = $itemHeadingMatch[1] ?? '';
                $itemDiv = preg_match('/^(:{3,})(?:[ \t]+.*)?$/', $body, $itemDivMatch) === 1;
                if ($itemDiv) {
                    $divs[] = ['width' => strlen($itemDivMatch[1]), 'prefix' => $quoteForCarve() . str_repeat(' ', $context->target + $context->content), 'invalid' => $fenceParser->parseDivFenceOpener($body)['invalidMetadata'] ?? false, 'itemColumn' => $context->column + 1, 'depth' => $quoteDepth, 'start' => $lineOffsets[$n]];
                }
                $itemQuoteDepth = 0;
                $quoteBody = '';
                $task = str_starts_with($body, '[') && preg_match('/^\[[ xX]\][ \t]+/', $body, $taskMatch) === 1;
                $itemBody = $task ? substr($body, strlen($taskMatch[0])) : $body;
                $embeddedOwners->clear();
                $embeddedListOwner = null;
                if (str_starts_with($itemBody, '>')) {
                    $itemQuote = $this->djotItemQuotePrefix($itemBody);
                    foreach ($itemQuote['owners'] as $quoteOwner) {
                        $embeddedListOwner = new DjotListContext($quoteOwner['column'], $quoteOwner['content'], $quoteOwner['target'], '', '', 0, $quoteDepth + $quoteOwner['depth'], strlen($quote) + $rawIndent + strlen($itemMatch[0]) - 1 + ($task ? strlen($taskMatch[0]) : 0) + $quoteOwner['sourceOffset'], $quoteOwner['sourceContent']);
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
                $paragraph = !$itemDiv && !$itemHeading && ($itemAttributes === null || $itemAttributes['end'] !== strlen($itemBody))
                    && preg_match('/^\[(?!\^)[^\]\n]+\]:/', $itemBody) !== 1 && !str_contains($body, "\x00DJOTNOTEATTR\x00")
                    && !($itemQuoteDepth > 0 && ($quoteBody === '' || preg_match('/^\[[^\]\n]*\]:/', $quoteBody) === 1 || preg_match('/^(?:#{1,6}(?:[ \t]|$)|:{3,}|[`~]{3,}|(?:[*-][ \t]*){3,}$)/', $quoteBody) === 1));
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
            $attrs = str_starts_with($visible, '{') ? $this->readDjotWordAttributes($raw, $rawIndent) : null;
            $attributeLine = $attrs !== null && $attrs['end'] === strlen($raw);
            if ($blank && !$this->djotListContains($lists, $indent) && !$attributeLine) {
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
            $noteOwner = $endNote($notes);
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

    private function compactDjotListPrefix(string $text): string
    {
        if (!str_contains($text, '0') && !str_contains($text, '(')) {
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
            $parts[] = preg_replace('/^\(([^)]+)\)/', '$1.', $compact) ?? $compact;
            $at += strlen($item[0]);
            if (in_array($item[0][0], ['-', '*', '+'], true) && preg_match('/\G\[[ xX]\][ \t]+/', $text, $task, 0, $at) === 1) {
                $parts[] = $task[0];
                $at += strlen($task[0]);
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
     * @param list<\MarkupCarve\Carve\Converter\DjotListContext> $lists
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

    private function normalizeDjotInlineSpellings(string $source): string
    {
        $comments = [];
        $this->maskCodeAndDestinations($source, opaqueOptions: [

            'onComment' => static function (int $start, int $end) use (&$comments): void {
                $comments[$start] = $end;
            },
        ]);
        foreach (array_reverse($comments, true) as $start => $end) {
            if ($source[$end - 2] !== '%' && str_contains(substr($source, $start + 2, $end - $start - 2), '{')) {
                $source = substr($source, 0, $start) . '{%%}' . substr($source, $end);
            }
        }
        $mask = $this->djotEmphasisMask($source);
        $source = preg_replace_callback('/(?:\{\})+|[ \t]+\\\\[ \t]*(?=\n)|\{[\'\"]|[\'\"]\}/', function (array $match) use ($mask, $source): string {
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

    /**
     * @param string $source
     * @param bool $caretOnly
     * @param array<int, array{label: string, target: string, end: int}> $accepted
     */
    private function normalizeDjotReferenceUses(string $source, bool $caretOnly = false, array $accepted = []): string
    {
        $mask = $this->maskCodeAndDestinations($source);
        $definitions = [];
        $definitionLines = [];
        $sourceRows = str_contains($source, '|') ? $this->djotTableRows($source, $this->maskCodeAndDestinations($source, false)) : [];
        $removed = [];
        $lines = explode("\n", $source);
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
                for ($k = $n - 1; $k >= 0 && !$caretOnly; $k--) {
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
                $definitions[$this->djotReferenceDefinitionKey($definition[1])] = ['url' => $definition[2], 'attrs' => $attrs, 'inherited' => $attrs !== [], 'line' => $n];
                $definitionLines[$n] = ['key' => $this->djotReferenceDefinitionKey($definition[1]), 'start' => $start, 'labelLength' => strlen($definition[1])];
            }
            $offset += strlen($line) + 1;
        }
        if ($definitions === []) {
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
        for ($open = 0; $open < $length; $open++) {
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
            $content = substr($source, $open + 1, $close - $open - 1);
            if (str_starts_with($content, '^') && strlen($content) > 1) {
                continue;
            }
            $label = $explicit === '' ? $content : $explicit;
            $label = trim(preg_replace('/\s+/', ' ', $label) ?? $label);
            $parsedLabel = $explicit === '' && (preg_match('/[_*`{~^]/', $label) === 1 || str_contains($label, '\\'));
            if ($parsedLabel) {
                $label = $this->djotReferenceRenderedLabel($label);
            }
            $key = $parsedLabel ? $this->djotReferenceDefinitionKey($label) : $this->djotReferenceKey($label);
            $matches[] = [
                [substr($source, $at, $end - $at), $at],
                [substr($source, $at, $close + 1 - $at), $at],
                [substr($source, $open + 1, $close - $open - 1), $open + 1],
                [substr($source, $close + 2, $labelEnd - $close - 2), $close + 2],
                [$own, $labelEnd + 1],
                $this->djotReferenceAttributeGroups($ownGroups),
                $key,
                $parsedLabel,
            ];
            $open = $end - 1;
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
        foreach ($definitions as $key => $definition) {
            if ((str_starts_with((string)$key, '^') || str_contains((string)$key, '\\') || str_contains((string)$key, '|') || str_contains((string)$key, '['))) {
                $needsAliases = true;

                break;
            }
        }
        if ($needsAliases) {
            foreach ($definitions as $key => $definition) {
                if (!(str_starts_with((string)$key, '^') || str_contains((string)$key, '\\') || str_contains((string)$key, '|') || str_contains((string)$key, '['))) {
                    continue;
                }
                $aliases[$key] = $nextAlias();
            }
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

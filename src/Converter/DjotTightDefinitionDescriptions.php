<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\HardBreak;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\ContainerPrefix;
use MarkupCarve\Carve\Parser\Utility\IndentationHelper;
use MarkupCarve\Carve\Renderer\CarveRenderer;

final class DjotTightDefinitionDescriptions
{
    public static function normalize(string $source): string
    {
        if (!str_contains($source, '::') || preg_match('/^[ \t>]*(?:[-*+]|[0-9A-Za-z]+[.)]|\([0-9A-Za-z]+\))[ \t]/m', $source) !== 1) {
            return $source;
        }
        $indexes = [];
        $length = strlen($source);
        if (preg_match('/[\x80-\xFF]/', $source) === 1) {
            for ($at = 0; $at < $length; $at++) {
                if ((ord($source[$at]) & 0xC0) !== 0x80) {
                    $indexes[] = $at;
                }
            }
            $indexes[] = $length;
        }
        $sourceOffset = static fn (int $offset): int => $indexes[$offset] ?? $offset;
        $lines = explode("\n", $source);
        $offsets = [];
        $offset = 0;
        foreach ($lines as $line) {
            $offsets[] = $offset;
            $offset += strlen($line) + 1;
        }
        $edits = [];
        $clearLoose = static function (Node $node) use ($lines, $offsets, &$edits): void {
            $pos = $node->getPos();
            if ($pos === null || $pos->startLine < 2) {
                return;
            }
            $line = $pos->startLine - 2;
            if (preg_match('/\{loose\}[ \t]*$/', $lines[$line], $marker, PREG_OFFSET_CAPTURE) === 1) {
                $start = $offsets[$line] + $marker[0][1];
                $edits[] = ['start' => $start, 'end' => $start + strlen($marker[0][0]), 'value' => '%%'];
            }
        };
        $clearGap = static function (?SourceSpan $before, ?SourceSpan $after, bool $boundary = false, bool $atRoot = false) use ($sourceOffset, $lines, $offsets, &$edits): void {
            if ($before === null || $after === null) {
                return;
            }
            $prefix = substr($lines[$after->startLine - 1], 0, $sourceOffset($after->startOffset) - $offsets[$after->startLine - 1]);
            preg_match('/^[ \t]*(?:> ?)+/', $prefix, $quotePrefix);
            $markerPrefix = $quotePrefix[0] ?? '';
            $rewriteBoundary = $boundary && $atRoot && $after->startLine === $after->endLine;
            $inserted = false;
            $count = count($lines);
            for ($line = $before->endLine - 1; $line < $after->startLine - 1; $line++) {
                if (preg_match('/^[ \t>]*$/', $lines[$line]) === 1) {
                    $edits[] = ['start' => $offsets[$line], 'end' => $offsets[$line] + strlen($lines[$line]) + (int)($line + 1 < $count), 'value' => $boundary && !$inserted ? ($rewriteBoundary ? $markerPrefix : $prefix) . "+\n" : ''];
                    $inserted = true;
                }
            }
            if ($rewriteBoundary && $inserted && $prefix !== $markerPrefix) {
                $edits[] = ['start' => $offsets[$after->startLine - 1], 'end' => $sourceOffset($after->startOffset), 'value' => $markerPrefix];
            }
        };
        $pending = [];
        foreach ((new BlockParser(trackPositions: true))->parse($source)->getChildren() as $node) {
            $pending[] = [$node, false];
        }
        while ($pending !== []) {
            [$node, $inherited] = array_pop($pending);
            $tight = $node instanceof ListBlock ? $node->isTight() : $inherited;
            if ($node instanceof ListBlock) {
                $hasDefinition = false;
                $safeChildren = true;
                $paragraphGap = false;
                $previous = null;
                $items = $node->getChildren();
                foreach ($items as $item) {
                    foreach ($item->getChildren() as $child) {
                        $hasDefinition = $hasDefinition || $child instanceof DefinitionList;
                        $safeChildren = $safeChildren && ($child instanceof DefinitionList || $child instanceof Paragraph || $child instanceof Comment);
                        if ($previous instanceof Paragraph) {
                            $before = $previous->getPos();
                            $after = $child->getPos();
                            if ($before !== null && $after !== null) {
                                $blank = false;
                                for ($line = $before->endLine - 1; $line < $after->startLine - 1; $line++) {
                                    if (preg_match('/^[ \t>]*$/', $lines[$line]) === 1) {
                                        $blank = true;
                                    } elseif ($blank && $child instanceof DefinitionList && preg_match('/^[ \t>]*\{(?!loose\}[ \t]*$)/', $lines[$line]) === 1) {
                                        $paragraphGap = true;
                                    }
                                }
                                $paragraphGap = $paragraphGap || $blank && ($child instanceof Paragraph || $child instanceof Comment);
                            }
                        }
                        $previous = $child;
                    }
                }
                if ($hasDefinition && $paragraphGap) {
                    $tight = false;
                    $pos = $node->getPos();
                    if ($node->isTight() && $pos !== null) {
                        $at = $sourceOffset($pos->startOffset);
                        $prefix = substr($lines[$pos->startLine - 1], 0, $at - $offsets[$pos->startLine - 1]);
                        $edits[] = ['start' => $at, 'end' => $at, 'value' => "{loose}\n" . (preg_replace('/[^ \t>]/', ' ', $prefix) ?? $prefix)];
                    }
                }
                if (!$node->isTight() && $safeChildren && $hasDefinition && !$paragraphGap) {
                    $tight = true;
                    $clearLoose($node);
                    $listPos = $node->getPos();
                    $listLine = $listPos === null ? 0 : $listPos->startLine;
                    $atRoot = $listLine > 0 && IndentationHelper::getLeadingColumns(
                        ContainerPrefix::stripQuoteMarkers($lines[$listLine - 1] ?? ''),
                        1,
                    ) === 0;
                    foreach ($items as $item) {
                        $children = $item->getChildren();
                        $count = count($children);
                        for ($i = 1; $i < $count; $i++) {
                            $clearGap($children[$i - 1]->getPos(), $children[$i]->getPos(), $children[$i - 1] instanceof DefinitionList, $atRoot);
                        }
                    }
                    $count = count($items);
                    for ($i = 1; $i < $count; $i++) {
                        $clearGap($items[$i - 1]->getPos(), $items[$i]->getPos());
                    }
                }
            }
            if ($node instanceof DefinitionList && $tight) {
                if ($node->isLoose()) {
                    $clearLoose($node);
                }
                foreach ($node->getChildren() as $description) {
                    if (!$description instanceof DefinitionDescription) {
                        continue;
                    }
                    $children = $description->getChildren();
                    if (count($children) < 2) {
                        continue;
                    }
                    $paragraphs = true;
                    foreach ($children as $child) {
                        if (!$child instanceof Paragraph) {
                            $paragraphs = false;

                            break;
                        }
                    }
                    if (!$paragraphs) {
                        continue;
                    }
                    $first = $children[0]->getPos();
                    $last = $children[array_key_last($children)]->getPos();
                    if ($first === null || $last === null) {
                        continue;
                    }
                    $prefix = substr($lines[$first->startLine - 1], 0, $sourceOffset($first->startOffset) - $offsets[$first->startLine - 1]);
                    $continuation = preg_replace_callback('/:[ \t]+$/', static fn (array $match): string => str_repeat(' ', strlen($match[0])), $prefix) ?? $prefix;
                    $joined = new Paragraph();
                    foreach ($children as $index => $paragraph) {
                        $previous = $joined->getChildren();
                        $lastInline = $previous === [] ? null : $previous[array_key_last($previous)];
                        if ($index > 0 && !$lastInline instanceof HardBreak) {
                            $joined->appendChild(new SoftBreak());
                        }
                        foreach ($paragraph->getChildren() as $inline) {
                            $joined->appendChild(clone $inline);
                        }
                    }
                    $document = new Document();
                    $document->appendChild($joined);
                    $contents = rtrim((new CarveRenderer())->render($document), "\n");
                    $edits[] = ['start' => $sourceOffset($first->startOffset), 'end' => $sourceOffset($last->endOffset), 'value' => str_replace("\n", "\n" . $continuation, $contents)];
                }
            }
            foreach ($node->getChildren() as $child) {
                $pending[] = [$child, $tight];
            }
        }
        if ($edits === []) {
            return $source;
        }
        usort($edits, static fn (array $a, array $b): int => $a['start'] <=> $b['start']);
        $result = [];
        $copied = 0;
        foreach ($edits as $edit) {
            if ($edit['start'] < $copied) {
                continue;
            }
            $result[] = substr($source, $copied, $edit['start'] - $copied);
            $result[] = $edit['value'];
            $copied = $edit['end'];
        }

        return implode('', $result) . substr($source, $copied);
    }
}

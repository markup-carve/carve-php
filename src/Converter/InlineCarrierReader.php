<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\RawBlock;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Math;
use MarkupCarve\Carve\Node\Inline\RawInline;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\AttributeParser;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Util\CarrierMarkers;

final class InlineCarrierReader
{
    public bool $damaged = false;

    /**
     * @var list<string>
     */
    public array $dropped = [];

    /**
     * @var array<string, string>
     */
    private array $markers = [];

    private bool $changed = false;

    private string $token = '';

    public function prepare(string $markdown): string
    {
        if (!str_contains($markdown, CarrierMarkers::PREFIX)) {
            return $markdown;
        }
        $token = 'CARVEINLINE';
        while (str_contains($markdown, $token)) {
            $token .= 'X';
        }
        $this->token = $token;
        $lines = explode("\n", $markdown);
        $lastTicks = [];
        $paragraphs = [];
        $offsets = [];
        $paragraph = 0;
        $offset = 0;
        foreach ($lines as $index => $line) {
            if (trim(preg_replace('/^(?:[ \t]*>[ ]?)+/', '', $line) ?? $line) === '') {
                $paragraph++;
            }
            $paragraphs[$index] = $paragraph;
            $offsets[$index] = $offset;
            preg_match_all('/`+/', $line, $runs, PREG_OFFSET_CAPTURE);
            foreach ($runs[0] as [$run, $at]) {
                $lastTicks[$paragraph][strlen($run)] = $offset + $at;
            }
            $offset += strlen($line) + 1;
        }
        $fence = null;
        $ticks = 0;
        foreach ($lines as $index => &$line) {
            $subject = preg_replace('/^(?:[ \t]*>[ ]?)+/', '', $line) ?? $line;
            if (preg_match('/^[ ]{0,3}(`{3,}|~{3,})/', $subject, $match) === 1) {
                if ($fence === null) {
                    $fence = $match[1];
                } elseif (
                    $match[1][0] === $fence[0] && strlen($match[1]) >= strlen($fence)
                    && trim(substr($subject, strpos($subject, $match[1]) + strlen($match[1]))) === ''
                ) {
                    $fence = null;
                }

                continue;
            }
            if (
                $fence !== null
                || (str_contains($subject, '|') && preg_match('/^\s*\|/', $subject) === 1)
            ) {
                continue;
            }
            if (trim($subject) === '') {
                $ticks = 0;
            }
            $whole = CarrierMarkers::payload(trim($subject));
            if ($whole !== null && $whole !== '[' && !str_starts_with($whole, ']{') && !str_starts_with($whole, '</#')) {
                continue;
            }
            $length = strlen($line);
            $out = '';
            for ($at = 0; $at < $length;) {
                if ($ticks === 0 && $line[$at] === '\\') {
                    $out .= substr($line, $at, 2);
                    $at += 2;

                    continue;
                }
                if ($line[$at] === '`') {
                    $run = strspn($line, '`', $at);
                    if ($ticks === 0 && ($lastTicks[$paragraphs[$index]][$run] ?? -1) > $offsets[$index] + $at) {
                        $ticks = $run;
                    } elseif ($ticks === $run) {
                        $ticks = 0;
                    }
                    $out .= substr($line, $at, $run);
                    $at += $run;

                    continue;
                }
                if ($ticks === 0 && substr($line, $at, strlen(CarrierMarkers::PREFIX)) === CarrierMarkers::PREFIX) {
                    $end = strpos($line, CarrierMarkers::SUFFIX, $at);
                    if ($end !== false) {
                        $end += strlen(CarrierMarkers::SUFFIX);
                        $marker = substr($line, $at, $end - $at);
                        $key = $token . count($this->markers) . 'Z';
                        $this->markers[$key] = $marker;
                        $out .= $key;
                        $at = $end;

                        continue;
                    }
                }
                $out .= $line[$at++];
            }
            $line = $out;
        }
        unset($line);

        return implode("\n", $lines);
    }

    public function restore(string $source): string
    {
        if ($this->markers === []) {
            return $source;
        }
        $document = (new CarveConverter())->parse($source);
        $this->restoreTokens($document);
        $restoredSource = (new CarveRenderer())->render($document);
        $this->visit($document);

        return !$this->changed || $this->damaged ? $restoredSource : (new CarveRenderer())->render($document);
    }

    private function restoreTokens(Node $node, bool $opaque = false): void
    {
        $opaque = $opaque || $node instanceof Table || $node instanceof CodeBlock || $node instanceof RawBlock
            || $node instanceof Code || $node instanceof RawInline || $node instanceof Image;
        if (
            $node instanceof Text || $node instanceof Code || $node instanceof RawInline || $node instanceof RawBlock
            || $node instanceof CodeBlock || $node instanceof Math
        ) {
            $node->setContent(strtr($node->getContent(), $this->markers));
        }
        $children = [];
        foreach ($node->getChildren() as $child) {
            if ($child instanceof Comment && str_contains($child->getContent(), $this->token)) {
                $restored = new Comment(strtr($child->getContent(), $this->markers), $child->getFenceLength(), $child->isDelimited());
                $restored->setAttributesWithOrder($child->getAttributes(), $child->getAttributeOrder());
                $restored->setPos($child->getPos());
                $children[] = $restored;

                continue;
            }
            if (!$opaque && $child instanceof Text) {
                $parts = preg_split('/(' . preg_quote($this->token, '/') . '[0-9]+Z)/', $child->getContent(), -1, PREG_SPLIT_DELIM_CAPTURE);
                if ($parts !== false && count($parts) > 1) {
                    foreach ($parts as $part) {
                        if (isset($this->markers[$part])) {
                            $children[] = new RawInline($this->markers[$part], 'html');
                        } elseif ($part !== '') {
                            $children[] = new Text($part);
                        }
                    }

                    continue;
                }
            }
            $this->restoreTokens($child, $opaque);
            $children[] = $child;
        }
        $node->setChildren($children);
    }

    private function visit(Node $node): void
    {
        if ($node instanceof Table || $node instanceof CodeBlock || $node instanceof RawBlock || $node instanceof Image) {
            return;
        }
        if ($node instanceof Paragraph || $node instanceof Heading) {
            $node->setChildren($this->restoreNodes(array_values($node->getChildren())));
        } else {
            foreach ($node->getChildren() as $child) {
                $this->visit($child);
            }
        }
    }

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    private function restoreNodes(array $nodes): array
    {
        $out = [];
        $stack = [];
        for ($index = 0, $count = count($nodes); $index < $count; $index++) {
            $node = $nodes[$index];
            if ($node instanceof RawInline && $node->getFormat() === 'html' && str_starts_with($node->getContent(), CarrierMarkers::PREFIX)) {
                $payload = CarrierMarkers::payload($node->getContent());
                if ($payload === '[') {
                    $stack[] = $out;
                    $out = [];
                    $this->changed = true;

                    continue;
                }
                $closer = $payload !== null && str_starts_with($payload, ']') ? substr($payload, 1) : null;
                $attributeSource = $closer ?? $payload;
                $valid = $attributeSource !== null && str_starts_with($attributeSource, '{')
                    && str_ends_with($attributeSource, '}')
                    && AttributeParser::isValidInlinePayload(substr($attributeSource, 1, -1));
                if ($closer !== null && $valid) {
                    if ($stack === []) {
                        $this->damaged = true;

                        return $nodes;
                    }
                    $span = new Span();
                    $span->setAttributes(AttributeParser::parse(substr($attributeSource, 1, -1)));
                    $child = count($out) === 1 ? $out[0] : null;
                    $attrs = $span->getAttributeEntries();
                    $childAttrs = $child?->getAttributeEntries() ?? [];
                    $same = $attrs === $childAttrs;
                    $abbreviationOnly = count($childAttrs) === 1 && isset($childAttrs['abbr'])
                        && $childAttrs['abbr'] === ($attrs['abbr'] ?? null);
                    $span->setChildren($child instanceof Span && ($same || $abbreviationOnly) ? $child->getChildren() : $out);
                    $out = array_pop($stack);
                    $out[] = $span;
                    $this->changed = true;

                    continue;
                }
                $previous = $out === [] ? null : $out[array_key_last($out)];
                if ($closer === null && $valid && ($previous instanceof Link || $previous instanceof Image)) {
                    $replacement = $previous instanceof Link
                        ? new Link($previous->getDestination(), $previous->getTitle())
                        : new Image($previous->getSource(), $previous->getAlt(), $previous->getTitle());
                    $replacement->setChildren($previous->getChildren());
                    $replacement->setAttributes(AttributeParser::parse(substr($attributeSource, 1, -1)));
                    $out[array_key_last($out)] = $replacement;
                    $this->changed = true;

                    continue;
                }
                if (
                    $payload !== null && str_starts_with($payload, '</#') && str_ends_with($payload, '>')
                    && isset($nodes[$index + 1]) && $nodes[$index + 1] instanceof Link
                ) {
                    $parsed = (new CarveConverter())->parse($payload)->getChildren()[0] ?? null;
                    $reference = $parsed?->getChildren()[0] ?? null;
                    if ($parsed instanceof Paragraph && count($parsed->getChildren()) === 1 && $reference instanceof HeadingRef) {
                        $out[] = $reference;
                        $index++;
                        $this->changed = true;

                        continue;
                    }
                }
                if (preg_match('/^(?::|\^|$)/', $payload ?? '') !== 1) {
                    $this->dropped[] = $node->getContent();
                }
            }
            if ($node->hasChildren()) {
                $node->setChildren($this->restoreNodes(array_values($node->getChildren())));
            }
            $out[] = $node;
        }
        if ($stack !== []) {
            $this->damaged = true;
        }

        return $out;
    }
}

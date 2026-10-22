<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use SplObjectStorage;

/**
 * @internal
 */
final class EmailText
{
    /**
     * @phpstan-assert-if-true \MarkupCarve\Carve\Node\Inline\Text|\MarkupCarve\Carve\Node\Inline\EscapedText|\MarkupCarve\Carve\Node\Inline\SmartPunctuation $node
     */
    private static function textNode(Node $node): bool
    {
        return $node instanceof Text || $node instanceof EscapedText || $node instanceof SmartPunctuation;
    }

    public static function link(Document $document): bool
    {
        $stack = [$document];
        $seen = new SplObjectStorage();
        $changed = false;
        while ($stack !== []) {
            $value = array_pop($stack);
            if (is_array($value)) {
                foreach ($value as $child) {
                    if (is_array($child) || is_object($child)) {
                        $stack[] = $child;
                    }
                }

                continue;
            }
            if ($seen->offsetExists($value)) {
                continue;
            }
            $seen->offsetSet($value, null);
            if ($value instanceof Link || $value instanceof Image || ($value instanceof Node && $value->getType() === 'block_image')) {
                continue;
            }
            if ($value instanceof Node) {
                $nodes = $value->getChildren();
                $rewritten = [];
                $rewrite = false;
                for ($at = 0, $length = count($nodes); $at < $length; $at++) {
                    $child = $nodes[$at];
                    if (!self::textNode($child)) {
                        $rewritten[] = $child;

                        continue;
                    }
                    $last = $at;
                    $textNodes = [$child];
                    $text = $child->getContent();
                    $attrs = $child->getAttributes();
                    if ($attrs === []) {
                        while ($last + 1 < $length) {
                            $next = $nodes[$last + 1];
                            if (!self::textNode($next) || $next->getAttributes() !== []) {
                                break;
                            }
                            $text .= $next->getContent();
                            $textNodes[] = $next;
                            $last++;
                        }
                    }
                    $links = BareLinks::ranges($text, mode: 'email');
                    if ($links === []) {
                        for (; $at <= $last; $at++) {
                            $rewritten[] = $nodes[$at];
                        }
                        $at--;

                        continue;
                    }
                    $parts = [];
                    $sliceNodeAt = 0;
                    $sliceNodeCount = count($textNodes);
                    $sliceOffset = 0;
                    $appendSlice = static function (int $start, int $end) use (&$parts, &$sliceNodeAt, &$sliceOffset, $textNodes, $sliceNodeCount, $attrs, $text): void {
                        if ($attrs !== []) {
                            $parts[] = new Text(substr($text, $start, $end - $start));

                            return;
                        }
                        while ($sliceNodeAt < $sliceNodeCount) {
                            $original = $textNodes[$sliceNodeAt];
                            $value = $original->getContent();
                            $nextOffset = $sliceOffset + strlen($value);
                            if ($nextOffset <= $start) {
                                $sliceOffset = $nextOffset;
                                $sliceNodeAt++;

                                continue;
                            }
                            if ($sliceOffset >= $end) {
                                break;
                            }
                            $from = max(0, $start - $sliceOffset);
                            $width = min($end, $nextOffset) - $sliceOffset - $from;
                            if ($original instanceof SmartPunctuation) {
                                $parts[] = new Text(substr($value, $from, $width));
                            } elseif ($from === 0 && $width === strlen($value)) {
                                $parts[] = $original;
                            } else {
                                $part = clone $original;
                                $part->setContent(substr($value, $from, $width));
                                $parts[] = $part;
                            }
                            if ($nextOffset > $end) {
                                break;
                            }
                            $sliceOffset = $nextOffset;
                            $sliceNodeAt++;
                        }
                    };
                    $cursor = 0;
                    foreach ($links as $link) {
                        if ($link['start'] > $cursor) {
                            $appendSlice($cursor, $link['start']);
                        }
                        $label = substr($text, $link['start'], $link['end'] - $link['start']);
                        $node = new Link($link['prefix'] . $label);
                        $node->appendChild(new Text($label));
                        $parts[] = $node;
                        $cursor = $link['end'];
                    }
                    if ($cursor < strlen($text)) {
                        $appendSlice($cursor, strlen($text));
                    }
                    if ($attrs !== []) {
                        $span = new Span();
                        $span->setAttributes($attrs);
                        $span->setChildren($parts);
                        $rewritten[] = $span;
                    } else {
                        foreach ($parts as $part) {
                            $rewritten[] = $part;
                        }
                    }
                    $rewrite = $changed = true;
                    $at = $last;
                }
                if ($rewrite) {
                    $value->setChildren($rewritten);
                }
            }
            foreach ((array)$value as $key => $child) {
                if (is_string($key) && (str_ends_with($key, "\0parent") || str_ends_with($key, "\0extensionPayload"))) {
                    continue;
                }
                if (is_array($child) || is_object($child)) {
                    $stack[] = $child;
                }
            }
        }

        return $changed;
    }
}

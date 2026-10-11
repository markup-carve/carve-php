<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\EscapedText;
use MarkupCarve\Carve\Node\Inline\Image;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use MarkupCarve\Carve\Node\Inline\Span;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use SplObjectStorage;

final class MarkdownEmailText
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
                    $text = $child->getContent();
                    $attrs = $child->getAttributes();
                    if ($attrs === []) {
                        while ($last + 1 < $length) {
                            $next = $nodes[$last + 1];
                            if (!self::textNode($next) || $next->getAttributes() !== []) {
                                break;
                            }
                            $text .= $next->getContent();
                            $last++;
                        }
                    }
                    $links = MarkdownBareLinks::ranges($text, mode: 'email');
                    if ($links === []) {
                        for (; $at <= $last; $at++) {
                            $rewritten[] = $nodes[$at];
                        }
                        $at--;

                        continue;
                    }
                    $parts = [];
                    $cursor = 0;
                    foreach ($links as $link) {
                        if ($link['start'] > $cursor) {
                            $parts[] = new Text(substr($text, $cursor, $link['start'] - $cursor));
                        }
                        $label = substr($text, $link['start'], $link['end'] - $link['start']);
                        $node = new Link($link['prefix'] . $label);
                        $node->appendChild(new Text($label));
                        $parts[] = $node;
                        $cursor = $link['end'];
                    }
                    if ($cursor < strlen($text)) {
                        $parts[] = new Text(substr($text, $cursor));
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

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Inline;

use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Inline\InlineContext;
use MarkupCarve\Carve\Inline\InlineRunner;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\TestCase;

/**
 * Runs the inline pass on one content and writes the nodes in one line: type@start-end(fields){children}.
 */
abstract class InlineTestCase extends TestCase
{
    /**
     * @param string $source Lines of one content, joined by line feeds.
     * @param array<string> $linkKeys
     * @param array<string, string> $abbreviations
     * @param int $mode Scanner mode bits.
     *
     * @return string
     */
    protected static function inline(string $source, array $linkKeys = [], array $abbreviations = [], int $mode = 0): string
    {
        $ctx = new InlineContext();
        foreach ($linkKeys as $key) {
            $ctx->linkKeys[$key] = true;
        }
        $ctx->abbreviations = $abbreviations;
        $spans = [];
        $p = 0;
        foreach (explode("\n", $source) as $line) {
            $spans[] = $p;
            $spans[] = $p + strlen($line);
            $p += strlen($line) + 1;
        }

        return self::nodes(InlineRunner::parse($source, $spans, $ctx, 0, $mode));
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $nodes
     *
     * @return string
     */
    protected static function nodes(array $nodes): string
    {
        return implode(' ', array_map(self::node(...), $nodes));
    }

    /**
     * @param \MarkupCarve\Carve\Node $node
     *
     * @return string
     */
    private static function node(Node $node): string
    {
        $out = $node->type . ($node->start >= 0 ? '@' . $node->start . '-' . $node->end : '');
        $fields = [];
        foreach ($node->f as $key => $value) {
            if ($value instanceof Attrs) {
                $value = ['id' => $value->id, 'classes' => $value->classes, 'keyValues' => $value->pairs];
            }
            $fields[] = $key . '=' . json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($fields !== []) {
            $out .= '(' . implode(' ', $fields) . ')';
        }
        if ($node->children !== null) {
            $out .= '{' . self::nodes($node->children) . '}';
        }

        return $out;
    }
}

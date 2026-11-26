<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import;

use MarkupCarve\Carve\Snippet\Syntax;

/**
 * C2-H-215 to C2-H-217: the snippet carriers of a `roundtrip` render, read back as snippet uses.
 */
final class Snippets
{
    /**
     * C2-H-186: the use an element with `data-carve-snippet` stands for in inline content, or null where the carrier
     * is not read. Its content is generated and not read.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<array<string, mixed>>|null
     */
    public static function inline(HtmlNode $node, ImportContext $context): ?array
    {
        if (!$node->has('data-carve-snippet')) {
            return null;
        }
        $name = self::accepted($node, $context, 'inline');

        return $name === null ? null : [['type' => 'snippet_use', 'name' => $name, 'mode' => 'inline']];
    }

    /**
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return array<array<string, mixed>>|null
     */
    public static function block(HtmlNode $node, ImportContext $context): ?array
    {
        if (!$node->has('data-carve-snippet')) {
            return null;
        }
        if ($node->name !== 'p') {
            $context->ignoredCarrier($node, 'data-carve-snippet', 'a block use is read only from a paragraph');

            return null;
        }
        $name = self::accepted($node, $context, 'block');

        return $name === null ? null : [['type' => 'snippet_use', 'name' => $name, 'mode' => 'block']];
    }

    /**
     * C2-H-217: a note reference whose `data-carve-snippet-mark` names an accepted use is dropped.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return bool
     */
    public static function droppedMark(HtmlNode $node, ImportContext $context): bool
    {
        $mark = $node->attr('data-carve-snippet-mark');
        if ($mark === null) {
            return false;
        }
        if (isset($context->snippetOrdinals[$mark])) {
            return true;
        }
        $context->ignoredCarrier($node, 'data-carve-snippet-mark', 'the value is no ordinal of a snippet use');

        return false;
    }

    /**
     * C2-H-217: the scope carrier beside an accepted carrier reports the lost include, once per element.
     *
     * @param \MarkupCarve\Carve\Import\HtmlNode $node
     * @param \MarkupCarve\Carve\Import\ImportContext $context
     *
     * @return void
     */
    public static function scope(HtmlNode $node, ImportContext $context): void
    {
        $scope = $node->attr('data-carve-snippet-scope');
        if ($scope === null) {
            return;
        }
        if ($scope !== 'include') {
            $context->ignoredCarrier($node, 'data-carve-snippet-scope', 'the value is not include');

            return;
        }
        $context->report(
            $node,
            'snippet-scope-loss',
            'The snippet came from an included file; its definition reads back into this document',
        );
    }

    private static function accepted(HtmlNode $node, ImportContext $context, string $mode): ?string
    {
        $value = (string)$node->attr('data-carve-snippet');
        $fault = null;
        if (preg_match('/^([1-9][0-9]*):(inline|block):\$(.+)$/D', $value, $m) !== 1 || !Syntax::isName($m[3])) {
            $fault = 'the value is not N:MODE:$NAME';
        } elseif ($m[2] !== $mode) {
            $fault = 'the mode ' . $m[2] . ' does not match a slot of ' . ($mode === 'inline' ? 'inline content' : 'blocks');
        } elseif (isset($context->snippetOrdinals[$m[1]])) {
            $fault = 'another carrier holds the same ordinal';
        }
        if ($fault !== null) {
            $context->ignoredCarrier($node, 'data-carve-snippet', $fault);
            if ($node->has('data-carve-snippet-scope')) {
                $context->ignoredCarrier($node, 'data-carve-snippet-scope', 'no snippet carrier is read here');
            }

            return null;
        }
        $context->snippetOrdinals[$m[1]] = true;
        self::scope($node, $context);

        return $m[3];
    }
}

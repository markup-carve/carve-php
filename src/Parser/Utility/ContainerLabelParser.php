<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use function array_values;

/**
 * The inline nodes of a CONTAINER LABEL - a div's or an admonition's unconsumed
 * `[label]`.
 *
 * `CARVE-P9-041` names a container label among the delimited regions parsed as
 * `inline_content` in their own right, so the fallback caption publishes that
 * run and not the characters the author typed (ruled on markup-carve/carve#2572,
 * definition replaced by markup-carve/carve#2604).
 *
 * The label stays a STRING on the node - no interchange field moves - and the run
 * beside it is what the caption renders. Reading the label with the DOCUMENT's own
 * inline parser is what makes an extension-registered construct read the same
 * inside a label as outside one; an isolated parser rendered a hashtag as its own
 * characters while every other host rendered the span.
 */
final class ContainerLabelParser
{
    /**
     * The isolated parser the fallback below runs in.
     */
    protected static ?BlockParser $fallback = null;

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    public static function parse(string $label, ?InlineParser $inlineParser = null): array
    {
        if ($label === '') {
            return [];
        }

        if ($inlineParser === null) {
            self::$fallback ??= new BlockParser();
            $inlineParser = self::$fallback->getInlineParser();
        }

        $container = new Paragraph();
        $inlineParser->parse($container, $label);
        $nodes = array_values($container->getChildren());
        // NO INVENTED POSITION. The run is read from the opener's label slot
        // without the slot's own offset, and PART 12 section 4 forbids making one
        // up, so the nodes carry none rather than a wrong one.
        self::clearPositions($nodes);

        return $nodes;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node\Node> $nodes
     */
    protected static function clearPositions(array $nodes): void
    {
        foreach ($nodes as $node) {
            $node->setPos(null);
            self::clearPositions($node->getChildren());
        }
    }
}

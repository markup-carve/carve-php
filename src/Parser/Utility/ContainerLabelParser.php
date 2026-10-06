<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser\Utility;

use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Parser\InlineParser;
use MarkupCarve\Carve\Parser\SourceMap;
use function array_values;
use function rtrim;

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
     * @param string $label
     * @param \MarkupCarve\Carve\Parser\InlineParser|null $inlineParser
     * @param int|null $commentOffset Byte offset of a trailing comment in the outer run.
     *
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    public static function parse(string $label, ?InlineParser $inlineParser = null, ?int &$commentOffset = null): array
    {
        $commentOffset = null;
        if ($label === '') {
            return [];
        }

        if ($inlineParser === null) {
            self::$fallback ??= new BlockParser();
            $inlineParser = self::$fallback->getInlineParser();
        }

        $container = new Paragraph();
        $map = new SourceMap();
        $map->add(0, 0, strlen($label), 1, 1);
        $inlineParser->parse($container, $label, sourceMap: $map);
        $nodes = array_values($container->getChildren());
        // A comment that reaches the label's end is its trailing comment, in
        // either spelling: a `{%% %%}` left in the string leaks into Markdown,
        // plain text and ANSI exactly as a bare `%%` would, because those targets
        // write the label as the source the author typed.
        foreach ($nodes as $node) {
            $pos = $node->getPos();
            if ($node instanceof Comment && $pos !== null && $pos->endOffset === strlen($label)) {
                $commentOffset = $pos->startOffset;

                break;
            }
        }
        // The run the caption publishes is the run of the label the CUT LEFT, so
        // the separator the comment took with it does not survive as trailing text
        // in the node before it.
        if ($commentOffset !== null) {
            $cut = rtrim(substr($label, 0, $commentOffset), " \t");
            $ignored = null;

            return $cut === '' ? [] : self::parse($cut, $inlineParser, $ignored);
        }

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

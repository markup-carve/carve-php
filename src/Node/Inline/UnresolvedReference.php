<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\Block\LineBlock;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\Utility\BracketScanner;

/**
 * One question, asked in every writer: is this node an UNRESOLVED reference?
 *
 * PART 12 §3a keeps such a reference as a `link` (or `image`) node rather than
 * reverting it to literal source, so the node exists but nothing resolved it:
 * it has no destination, and it carries the verbatim source the author wrote.
 * Every writer renders that source instead of the node - an unresolved
 * reference is never an anchor - so they all need the same predicate, and a
 * copy per writer is how the six of them would drift apart.
 */
final class UnresolvedReference
{
    public static function markResolvedEmpty(Node $node): void
    {
        if ($node instanceof Link || $node instanceof Image) {
            $node->setResolvedEmptyReference(true);
        }
    }

    public static function isResolvedEmpty(Node $node): bool
    {
        return ($node instanceof Link || $node instanceof Image) && $node->isResolvedEmptyReference();
    }

    /**
     * The verbatim authored source (`rawRef`), or null when the node is not an
     * unresolved reference.
     */
    public static function sourceOf(Node $node): ?string
    {
        if ($node instanceof Link && $node->getDestination() === '' && !self::isResolvedEmpty($node)) {
            return $node->getRawReferenceLabel();
        }
        if ($node instanceof Image && $node->getSource() === '' && !self::isResolvedEmpty($node)) {
            return $node->getRawReferenceLabel();
        }

        return null;
    }

    /**
     * The same source, with its COMMENT LINES emptied - what a renderer emits.
     *
     * TWO CONSUMERS, TWO CONTRACTS, SPLIT HERE RATHER THAN IN THE STORED VALUE.
     * PART 12 §3a says `rawRef` is the authored source verbatim, so the
     * canonical writer emits it unchanged and a `%% secret` the author wrote
     * inside a reference label survives a round trip. PART 9 §23 says a comment
     * LINE publishes nothing, so every other target has to empty it again -
     * a renderer that writes the stored string through publishes the author's
     * private text into the output (carve-js found that half; carve-php had
     * the other, losing it in `fmt`).
     *
     * Changing what `rawRef` HOLDS can only ever fix one of the two, which is
     * why the split is at the consumer.
     *
     * ONLY A LINE WHOSE FIRST CHARACTER IS `%` qualifies, the same test the
     * block layer applies: leading whitespace is CONTENT in verse, and a
     * trailing `x %% secret` is an `inline_comment`, which §21 leaves standing.
     */
    public static function renderedSourceOf(Node $node): ?string
    {
        $source = self::sourceOf($node);
        if ($source === null || !str_contains($source, '%%')) {
            return $source;
        }

        $lines = explode("\n", $source);
        foreach ($lines as $index => $line) {
            if (str_starts_with($line, '%%')) {
                $lines[$index] = '';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * A source line feed in a failed reference is a soft break in text targets.
     */
    public static function displaySourceOf(Node $node, string $softBreak): ?string
    {
        $source = self::renderedSourceOf($node);

        if ($source === null) {
            return null;
        }
        if (!str_contains($source, "\n") && !str_contains($source, "\r")) {
            return $source;
        }

        $inLineBlock = false;
        for ($parent = $node->getParent(); $parent !== null; $parent = $parent->getParent()) {
            if ($parent instanceof LineBlock) {
                $inLineBlock = true;

                break;
            }
        }

        $preservedLines = [];
        $preservedOffsets = [];
        // Images publish alt as a scalar, so their verbatim inline nodes are
        // unavailable here. Recover code-span line feeds from authored rawRef.
        if ($node instanceof Image) {
            $altEnd = BracketScanner::balancedBracketEnds($source, 1, false)[1] ?? 0;
            preg_match_all('/`+/', $source, $runs, PREG_OFFSET_CAPTURE);
            $delimiters = $runs[0];
            for ($run = 0, $count = count($delimiters); $run < $count; ++$run) {
                [$ticks, $start] = $delimiters[$run];
                if ($start >= $altEnd) {
                    break;
                }
                $slashes = 0;
                for ($at = $start - 1; $at >= 0 && $source[$at] === '\\'; --$at) {
                    ++$slashes;
                }
                if ($slashes % 2 !== 0) {
                    continue;
                }
                for ($end = $run + 1; $end < $count; ++$end) {
                    if ($delimiters[$end][1] >= $altEnd || strlen($delimiters[$end][0]) !== strlen($ticks)) {
                        continue;
                    }
                    $contentStart = $start + strlen($ticks);
                    $contentEnd = $delimiters[$end][1];
                    for ($at = $contentStart; $at < $contentEnd; ++$at) {
                        if ($source[$at] === "\n" || $source[$at] === "\r") {
                            $preservedOffsets[$at] = true;
                        }
                    }
                    $run = $end;

                    break;
                }
            }
        }
        $searchCursor = 0;
        $pending = [$node];
        while ($pending !== []) {
            $current = array_pop($pending);
            $position = $current->getPos();
            if ($current instanceof Text && $position === null && $current->getContent() !== '') {
                $start = strpos($source, $current->getContent(), $searchCursor);
                if ($start !== false) {
                    $searchCursor = $start + strlen($current->getContent());
                }
            }
            if ($current instanceof HardBreak && $position !== null) {
                $preservedLines[$position->startLine] = true;
            }
            if ($current instanceof Code || $current instanceof Math || $current instanceof LiteralInline || $current instanceof RawText) {
                $content = $current->getContent();
                if (str_contains($content, "\n") || str_contains($content, "\r")) {
                    if ($position !== null) {
                        for ($heldLine = $position->startLine; $heldLine < $position->endLine; ++$heldLine) {
                            $preservedLines[$heldLine] = true;
                        }
                    } else {
                        $start = strpos($source, $content, $searchCursor);
                        if ($start !== false) {
                            preg_match_all('/\r\n|\r|\n/', $content, $breaks, PREG_OFFSET_CAPTURE);
                            foreach ($breaks[0] as [, $offset]) {
                                $preservedOffsets[$start + $offset] = true;
                            }
                            $searchCursor = $start + strlen($content);
                        }
                    }
                }
            }
            array_push($pending, ...array_reverse($current->getChildren()));
        }

        $position = $node->getPos();
        $line = $position === null ? 1 : $position->startLine;
        $cursor = 0;

        return preg_replace_callback('/\r\n|\r|\n/', static function (array $match) use (&$line, &$cursor, $source, $inLineBlock, $preservedLines, $preservedOffsets, $softBreak): string {
            $offset = strpos($source, $match[0], $cursor);
            $index = $offset - 1;
            while ($index >= $cursor && ($source[$index] === ' ' || $source[$index] === "\t")) {
                --$index;
            }
            $backslashes = 0;
            for (; $index >= $cursor && $source[$index] === '\\'; --$index) {
                ++$backslashes;
            }
            $cursor = $offset + strlen($match[0]);
            $rawHardBreak = $backslashes % 2 === 1;
            $replacement = $inLineBlock || isset($preservedLines[$line]) || isset($preservedOffsets[$offset]) || $rawHardBreak ? "\n" : $softBreak;
            ++$line;

            return $replacement;
        }, $source);
    }
}

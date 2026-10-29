<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use MarkupCarve\Carve\Node\Block\BlockQuote;
use MarkupCarve\Carve\Node\Block\Div;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\SoftBreak;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;

/**
 * GitHub alert blockquotes imported as admonitions (CARVE-P2-033).
 *
 * @internal
 */
final class GithubAlerts
{
    /**
     * GitHub alert label -> the Carve admonition kind it imports as.
     *
     * @var array<string, string>
     */
    public const KINDS = [
        'NOTE' => 'note',
        'TIP' => 'tip',
        'IMPORTANT' => 'info',
        'WARNING' => 'warning',
        'CAUTION' => 'danger',
    ];

    /**
     * A label line exactly as GitHub documents it: uppercase, alone on the line.
     *
     * @var string
     */
    public const LABEL = '/^\[!(NOTE|TIP|IMPORTANT|WARNING|CAUTION)\]$/D';

    /**
     * Rewrite each top-level GitHub alert blockquote as its admonition. GitHub
     * renders alerts only outside other elements, so nested quotes stay quotes.
     *
     * @param \MarkupCarve\Carve\Node\Document $document
     * @param string $source The Carve source `$document` was parsed from.
     */
    public static function import(Document $document, string $source): bool
    {
        $changed = false;
        foreach ($document->getChildren() as $index => $node) {
            $admonition = self::admonition($node, $source);
            if ($admonition !== null) {
                $document->replaceChild($index, $admonition);
                $changed = true;
            }
        }

        return $changed;
    }

    private static function admonition(Node $node, string $source): ?Div
    {
        if (!$node instanceof BlockQuote || $node->getAttributes() !== []) {
            return null;
        }
        $children = $node->getChildren();
        $head = $children[0] ?? null;
        if (!$head instanceof Paragraph || $head->getAttributes() !== []) {
            return null;
        }
        $inlines = $head->getChildren();
        $kind = self::kind($inlines[0] ?? null, $source);
        if ($kind === null) {
            return null;
        }
        if (isset($inlines[1]) && !$inlines[1] instanceof SoftBreak) {
            return null;
        }
        $rest = array_slice($children, 1);
        $tail = array_slice($inlines, 2);
        if ($tail !== []) {
            $paragraph = clone $head;
            $paragraph->setChildren($tail);
            array_unshift($rest, $paragraph);
        }
        if ($rest === []) {
            return null;
        }
        $div = new Div();
        $div->addClass($kind);
        $div->setTyped(true);
        $div->setChildren($rest);
        $div->setPos($node->getPos());

        return $div;
    }

    private static function kind(?Node $first, string $source): ?string
    {
        $pos = $first?->getPos();
        if (!$first instanceof Text || $first->getAttributes() !== [] || $pos === null) {
            return null;
        }
        // The source slice, not the text value: an escaped `\[!NOTE]` reads the
        // same and stays literal on GitHub. Offsets count codepoints, not bytes.
        $slice = mb_substr($source, $pos->startOffset, $pos->endOffset - $pos->startOffset, 'UTF-8');
        if (preg_match(self::LABEL, $slice, $label) !== 1 || $label[0] !== $first->getContent()) {
            return null;
        }

        return self::KINDS[$label[1]];
    }
}

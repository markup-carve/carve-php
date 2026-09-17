<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Inline;

use MarkupCarve\Carve\Node\Node;

/**
 * Critic substitution {~old~>new~}
 *
 * Both halves are inline content (markup-carve/carve#2083), held as two
 * {@see SubstitutionHalf} children, the deleted one first.
 */
class Substitution extends InlineNode
{
    /**
     * @param string $oldText The deleted half as plain text, for a caller that builds one by hand.
     * @param string $newText The inserted half as plain text.
     */
    public function __construct(string $oldText = '', string $newText = '')
    {
        $old = new SubstitutionHalf();
        if ($oldText !== '') {
            $old->appendChild(new Text($oldText));
        }
        $new = new SubstitutionHalf();
        if ($newText !== '') {
            $new->appendChild(new Text($newText));
        }
        $this->appendChild($old);
        $this->appendChild($new);
    }

    public function getOld(): SubstitutionHalf
    {
        return $this->half(0);
    }

    public function getNew(): SubstitutionHalf
    {
        return $this->half(1);
    }

    /**
     * The deleted half as plain text.
     */
    public function getOldText(): string
    {
        return self::plainText($this->getOld());
    }

    /**
     * The inserted half as plain text.
     */
    public function getNewText(): string
    {
        return self::plainText($this->getNew());
    }

    public function getType(): string
    {
        return 'substitution';
    }

    protected function half(int $index): SubstitutionHalf
    {
        $halves = array_values(array_filter($this->getChildren(), static fn (Node $child): bool => $child instanceof SubstitutionHalf));
        $half = $halves[$index] ?? null;
        if ($half === null) {
            $half = new SubstitutionHalf();
            $this->appendChild($half);
        }

        return $half;
    }

    protected static function plainText(Node $node): string
    {
        $text = '';
        foreach ($node->getChildren() as $child) {
            $text .= match (true) {
                $child instanceof Text, $child instanceof EscapedText => $child->getContent(),
                $child instanceof Code => $child->getContent(),
                default => self::plainText($child),
            };
        }

        return $text;
    }
}

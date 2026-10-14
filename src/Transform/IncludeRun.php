<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Transform;

use MarkupCarve\Carve\Node\Inline\EscapedText;

/**
 * A directive run reassembled once, with the byte offset where each node ends.
 *
 * A run with many `{{` candidates asks which node holds a character once per
 * candidate, so the lookup is a binary search over these ends rather than a
 * walk from the run's start.
 */
class IncludeRun
{
    /**
     * @var list<string>
     */
    protected array $pieces = [];

    /**
     * @var list<int>
     */
    protected array $ends = [];

    protected string $text;

    /**
     * @param list<\MarkupCarve\Carve\Node\Node> $nodes
     */
    public function __construct(protected array $nodes)
    {
        $end = 0;
        foreach ($nodes as $node) {
            $piece = IncludeDirectiveSyntax::textLikeContent([$node]);
            $this->pieces[] = $piece;
            $end += strlen($piece);
            $this->ends[] = $end;
        }
        $this->text = implode('', $this->pieces);
    }

    public function text(): string
    {
        return $this->text;
    }

    /**
     * @return list<\MarkupCarve\Carve\Node\Node>
     */
    public function nodes(): array
    {
        return $this->nodes;
    }

    public function piece(int $index): string
    {
        return $this->pieces[$index];
    }

    /**
     * The first node ending after byte `$offset`; the node count when none does.
     */
    public function nodeAt(int $offset): int
    {
        $low = 0;
        $high = count($this->ends);
        while ($low < $high) {
            $mid = ($low + $high) >> 1;
            if ($this->ends[$mid] <= $offset) {
                $low = $mid + 1;
            } else {
                $high = $mid;
            }
        }

        return $low;
    }

    public function startOf(int $index): int
    {
        return $this->ends[$index - 1] ?? 0;
    }

    /**
     * Whether a directive match opens or closes on a backslash escape. An
     * escaped brace is literal (CARVE-P7-004), so such a match is no directive:
     * `\{{ x }}` holds no `{{`.
     *
     * @param int $offset Byte offset of the match in `text()`.
     * @param int $length Byte length of the match.
     */
    public function matchIsEscaped(int $offset, int $length): bool
    {
        return $this->escapedAt($offset) || $this->escapedAt($offset + $length - 1);
    }

    protected function escapedAt(int $offset): bool
    {
        return ($this->nodes[$this->nodeAt($offset)] ?? null) instanceof EscapedText;
    }
}

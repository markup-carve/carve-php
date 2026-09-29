<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter;

/**
 * @internal
 */
final class MarkdownDelimiterRun
{
    public int $left = 0;

    public int $right = 0;

    public bool $active = true;

    public int $previous = -1;

    public int $next = -1;

    /**
     * The innermost LINK LABEL this run sits in, or -1 at the top level.
     *
     * CommonMark resolves a link before it processes the emphasis inside it, with
     * the bracket as the stack bottom, so a run in a label never reaches a partner
     * outside it and the leftovers each side stay literal. Here that is one
     * comparison: a pair needs both halves in the same scope.
     */
    public int $scope = -1;

    public function __construct(
        public int $start,
        public int $end,
        public string $char,
        public bool $open,
        public bool $close,
    ) {
    }

    public function remaining(): int
    {
        return $this->end - $this->start - $this->left - $this->right;
    }
}

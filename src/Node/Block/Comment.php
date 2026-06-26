<?php

declare(strict_types=1);

namespace Carve\Node\Block;

use Carve\Node\ContentNodeInterface;

/**
 * Comment block - stripped from output
 */
class Comment extends BlockNode implements ContentNodeInterface
{
    public function __construct(
        protected string $content = '',
        protected ?int $fenceLength = null,
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function getFenceLength(): ?int
    {
        return $this->fenceLength;
    }

    public function getType(): string
    {
        return 'comment';
    }
}

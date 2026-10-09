<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use MarkupCarve\Carve\Node\ContentNodeInterface;

/**
 * Raw block (pass-through to specific format)
 */
class RawBlock extends BlockNode implements ContentNodeInterface
{
    public function __construct(
        protected string $content = '',
        protected string $format = '',
    ) {
    }

    public function getContent(): string
    {
        return $this->content;
    }

    public function setContent(string $content): void
    {
        $this->content = $content;
    }

    /**
     * The content a code block stores for the same payload lines.
     *
     * The parser drops a raw block's final payload newline unless every payload
     * line is blank; a code block keeps it.
     */
    public function getCodeBlockContent(): string
    {
        return trim($this->content, "\n") === '' ? $this->content : $this->content . "\n";
    }

    public function getFormat(): string
    {
        return $this->format;
    }

    public function setFormat(string $format): void
    {
        $this->format = $format;
    }

    public function getType(): string
    {
        return 'raw_block';
    }
}

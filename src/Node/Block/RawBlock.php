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
        protected ?bool $contentTerminated = null,
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
        if ($this->content === '') {
            return '';
        }
        if (trim($this->content, "\n") === '') {
            return $this->contentTerminated === false ? substr($this->content, 0, -1) : $this->content;
        }

        return $this->content . ($this->contentTerminated === false ? '' : "\n");
    }

    public function getContentTerminated(): ?bool
    {
        return $this->contentTerminated;
    }

    public function setContentTerminated(?bool $terminated): void
    {
        $this->contentTerminated = $terminated;
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

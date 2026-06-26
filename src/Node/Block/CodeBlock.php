<?php

declare(strict_types=1);

namespace Carve\Node\Block;

use Carve\Node\ContentNodeInterface;

/**
 * Fenced code block
 */
class CodeBlock extends BlockNode implements ContentNodeInterface
{
    public function __construct(
        protected string $content = '',
        protected ?string $language = null,
        /**
         * Optional bracketed label from the info string (```php [NPM] -> "NPM").
         * Structured metadata only: NOT part of the language/class. The core
         * renderer ignores it; an extension (e.g. CodeGroup) may use it.
         */
        protected ?string $label = null,
        protected ?string $header = null,
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

    public function appendContent(string $content): void
    {
        $this->content .= $content;
    }

    public function getLanguage(): ?string
    {
        return $this->language;
    }

    public function setLanguage(?string $language): void
    {
        $this->language = $language;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setLabel(?string $label): void
    {
        $this->label = $label;
    }

    public function getHeader(): ?string
    {
        return $this->header;
    }

    public function setHeader(?string $header): void
    {
        $this->header = $header;
    }

    public function getType(): string
    {
        return 'code_block';
    }
}

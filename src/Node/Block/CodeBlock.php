<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Node\Block;

use MarkupCarve\Carve\Node\ContentNodeInterface;

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

    /**
     * Whether the fence closed with NO payload line at all.
     *
     * `code_content` is any text until the matching fence, preserved literally, so
     * zero lines preserved literally is zero characters (markup-carve/carve#2560,
     * corpus category 524). One blank payload line keeps its newline, and the two
     * are indistinguishable from $content alone: the parser strips the separator
     * before the closing fence, which leaves both shapes with the empty string.
     *
     * The AST cannot carry this yet. `code_block` has a single `content` string in
     * `resources/ast-schema.json`, and carve-js serializes the empty string for both
     * shapes too, so a tree that has been through the codec renders the one-line
     * reading. Nothing but the parser can set this.
     *
     * An UNCLOSED fence is not this shape: it runs to the end of its block and
     * occupies a line whatever follows the opener, which is what the oracle writes
     * for a bare ``` with nothing after it.
     */
    protected bool $noPayloadLines = false;

    public function hasNoPayloadLines(): bool
    {
        return $this->noPayloadLines;
    }

    public function setNoPayloadLines(bool $noPayloadLines): void
    {
        $this->noPayloadLines = $noPayloadLines;
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

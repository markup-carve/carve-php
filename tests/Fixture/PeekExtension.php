<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\BeforeRenderContext;
use MarkupCarve\Carve\Extension\BeforeRenderExtensionInterface;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\SmartTypographyMode;

/**
 * Records what the context reported for the render that ran.
 */
class PeekExtension implements BeforeRenderExtensionInterface
{
    /**
     * The effective mode the context reported.
     */
    public string $mode = '';

    /**
     * Whether the context reported the static HTML path.
     */
    public bool $isStatic = false;

    /**
     * Whether the context reported an HTML target.
     */
    public bool $targetIsHtml = false;

    /**
     * The symbol map the context reported.
     *
     * @var array<string, string>
     */
    public array $symbols = [];

    /**
     * The smart-typography mode the context reported.
     */
    public SmartTypographyMode $smartTypography = SmartTypographyMode::Glyph;

    /**
     * @param \MarkupCarve\Carve\CarveConverter $converter The converter registering this extension.
     */
    public function register(CarveConverter $converter): void
    {
    }

    /**
     * @param \MarkupCarve\Carve\Node\Document $document The resolved document.
     * @param \MarkupCarve\Carve\Extension\BeforeRenderContext $context The read-only render context.
     *
     * @return \MarkupCarve\Carve\Node\Document
     */
    public function beforeRender(Document $document, BeforeRenderContext $context): Document
    {
        $this->mode = $context->mode();
        $this->isStatic = $context->isStatic();
        $this->targetIsHtml = $context->targetIsHtml();
        $this->symbols = $context->symbols();
        $this->smartTypography = $context->smartTypography();

        return $document;
    }
}

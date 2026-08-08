<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\BeforeRenderContext;
use MarkupCarve\Carve\Extension\BeforeRenderExtensionInterface;
use MarkupCarve\Carve\Node\Document;

/**
 * Writes to the map it was handed; the renderer's own map must not move.
 */
class TamperExtension implements BeforeRenderExtensionInterface
{
    /**
     * The map the context handed this hook.
     *
     * @var array<string, string>
     */
    public array $seen = [];

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
        $this->seen = $context->symbols();
        $mine = $context->symbols();
        $mine['ok'] = 'TAMPERED';

        return $document;
    }
}

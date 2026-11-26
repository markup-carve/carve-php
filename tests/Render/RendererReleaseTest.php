<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Html\HtmlRenderer;
use MarkupCarve\Carve\Options;
use PHPUnit\Framework\TestCase;
use WeakReference;

/**
 * A render leaves no cycle behind: the tree is freed by reference counting alone, without a cycle collection.
 */
final class RendererReleaseTest extends TestCase
{
    public function testRenderReleasesTheTree(): void
    {
        $source = "# H\n\nUse *a*, [b](https://example.com) and a note.[^n]\n\n[^n]: Note.\n\n| a | b |\n\n- x\n";
        $doc = Carve::parse($source);
        $ref = WeakReference::create($doc);
        $gc = gc_enabled();
        gc_disable();
        try {
            [$html] = HtmlRenderer::renderWithLog($doc, Options::from([], ['parse', 'resolve', 'render']), $source);
            unset($doc);

            self::assertStringContainsString('<table>', $html);
            self::assertNull($ref->get());
        } finally {
            if ($gc) {
                gc_enable();
            }
        }
    }
}

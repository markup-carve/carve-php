<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Performance;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

/**
 * Source rendering with no extensions must not walk the completed tree for extension link keys.
 */
final class SourceRenderTraversalTest extends TestCase
{
    public function testNoExtensionSourceRenderSkipsLinkKeyWalk(): void
    {
        if (!function_exists('xdebug_start_function_monitor')) {
            self::markTestSkipped('Run with Xdebug to count the link-key walk');
        }
        $source = "- item *bold* `code`\n  - nested one\n  - nested two\n- next\n";
        xdebug_start_function_monitor(['MarkupCarve\\Carve\\Carve::linkKeysByFile']);
        try {
            $html = Carve::render($source);
            self::assertSame($html, Carve::toHtml($source));
            self::assertSame([], xdebug_get_monitored_functions());
        } finally {
            xdebug_stop_function_monitor();
        }
    }
}

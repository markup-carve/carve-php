<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

/**
 * `escapeText()` exists as a PUBLIC wrapper rather than by widening the
 * protected `escape()` it delegates to.
 *
 * Widening it would fatal at CLASS-LOAD time in any subclass that overrides
 * `escape()` with the visibility it has always had - `Access level to
 * Sub::escape() must be public (as in class HtmlRenderer)` - so a host with its
 * own renderer subclass could not even load, whatever it called
 * (markup-carve/carve-php#1538).
 *
 * `escape()` therefore stays an extension point, and both halves are pinned:
 * the public name is there for an extension to reach, and the protected one is
 * still overridable.
 */
class TheTextEscaperIsAWrapperNotAWidenedOverridePointTest extends TestCase
{
    public function testTheTextEscaperIsPublic(): void
    {
        $method = new ReflectionMethod(HtmlRenderer::class, 'escapeText');

        $this->assertTrue($method->isPublic(), 'an extension has to be able to reach it');
    }

    public function testTheUnderlyingEscaperStaysProtected(): void
    {
        $method = new ReflectionMethod(HtmlRenderer::class, 'escape');

        $this->assertTrue(
            $method->isProtected(),
            'widening this fatals at class load in a subclass that overrides it',
        );
    }

    /**
     * The consequence, stated as behavior rather than as visibility: a subclass
     * overriding the protected escaper loads and is used.
     */
    public function testASubclassMayStillOverrideTheProtectedEscaper(): void
    {
        $renderer = new class extends HtmlRenderer {
            protected function escape(string $text): string
            {
                return '[' . $text . ']';
            }
        };

        $this->assertSame('[a "q" b]', $renderer->escapeText('a "q" b'));
    }

    /**
     * And the two escapings stay apart - PART 10 §2.
     */
    public function testTextAndAttributeEscapingDiffer(): void
    {
        $renderer = new HtmlRenderer();

        $this->assertSame('a "q" b', $renderer->escapeText('a "q" b'));
        $this->assertSame('a &quot;q&quot; b', $renderer->escapeAttribute('a "q" b'));
        $this->assertSame('R&amp;D &lt;x&gt;', $renderer->escapeText('R&D <x>'));
    }
}

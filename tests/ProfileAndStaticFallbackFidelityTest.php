<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\FencedRenderExtension;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\RenderMode;
use PHPUnit\Framework\TestCase;

class ProfileAndStaticFallbackFidelityTest extends TestCase
{
    public function testDeniedReferenceUsesResolvedTitleOrTarget(): void
    {
        $converter = new CarveConverter(profile: Profile::comment());
        self::assertSame(preg_replace('/\n\z/', '', "<p>Named title</p>\n<p># Named title</p>\n"), $converter->convert("</#a>\n\n{#a}\n# Named title\n"));
        self::assertSame(preg_replace('/\n\z/', '', "<p>missing</p>\n"), $converter->convert("</#missing>\n"));
    }

    public function testDeniedCodeBlockUsesLiteralPayload(): void
    {
        $converter = new CarveConverter(profile: Profile::minimal());
        self::assertSame(preg_replace('/\n\z/', '', "<p>x</p>\n"), $converter->convert("```\nx\n```\n"));
    }

    public function testStaticDiagramKeepsPayloadFinalBreak(): void
    {
        $converter = new CarveConverter(mode: RenderMode::STATIC);
        $converter->addExtension(FencedRenderExtension::mermaid());
        self::assertSame(preg_replace('/\n\z/', '', "<pre class=\"mermaid\"><code class=\"language-mermaid\">graph TD; A--&gt;B\n</code></pre>\n"), $converter->convert("```mermaid\ngraph TD; A-->B\n```\n"));
    }
}

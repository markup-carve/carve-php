<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\FencedRender;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class ImgFenceFailureTest extends TestCase
{
    public function testMalformedSvgIsClaimedAndReportsRendererFailure(): void
    {
        foreach (['img', 'image'] as $language) {
            $source = "```$language\n<svg><script>x</script>\n```\n";
            $name = 'carve.imgFence.' . $language;
            $options = ['extensions' => [$name], 'renderers' => [$name => 'standard']];
            $result = Carve::renderWithDiagnostics($source, $options);

            self::assertSame(
                '<pre><code class="language-' . $language . '">&lt;svg&gt;&lt;script&gt;x&lt;/script&gt;' . "\n</code></pre>",
                $result->html,
            );
            self::assertSame(['extension-renderer-failed'], $result->codes());
            self::assertSame('render-loss', $result->diagnostics[0]->channel);
            self::assertSame('warning', $result->diagnostics[0]->severity);
            self::assertStringContainsString($name, $result->diagnostics[0]->message);
        }
    }

    public function testAnotherRendererFailureUsesTheSameBoundary(): void
    {
        $extension = FencedRender::bundle('diagram', build: static function (): string {
            throw new RuntimeException('renderer unavailable');
        });
        $result = Carve::renderWithDiagnostics("```diagram\na\n```\n", [
            'extensions' => [$extension],
            'mode' => 'static',
        ]);

        self::assertSame("<pre><code class=\"language-diagram\">a\n</code></pre>", $result->html);
        self::assertSame(['extension-renderer-failed'], $result->codes());
        self::assertStringContainsString('carve.fencedRender.diagram', $result->diagnostics[0]->message);
    }
}

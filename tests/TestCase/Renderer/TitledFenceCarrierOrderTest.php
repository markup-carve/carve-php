<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TitledFenceCarrierOrderTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function fences(): array
    {
        return [
            'plain' => ["``` \"t\" [L]\n```\n", '<pre title="t" data-carve-label="L"><code></code></pre>'],
            'language' => ["```php \"t\" [L]\n```\n", '<pre title="t" data-carve-label="L"><code class="language-php"></code></pre>'],
            'authored class' => ["{.x}\n``` \"t\" [L]\n```\n", '<pre class="x" title="t" data-carve-label="L"><code></code></pre>'],
        ];
    }

    #[DataProvider('fences')]
    public function testAuthoredAttributesPrecedeLabelCarrier(string $source, string $expected): void
    {
        $renderer = new HtmlRenderer();
        $renderer->setRoundTripMode(true);

        self::assertSame($expected, (new CarveConverter(renderer: $renderer))->convert($source));
    }
}

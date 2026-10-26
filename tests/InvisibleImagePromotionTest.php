<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

final class InvisibleImagePromotionTest extends TestCase
{
    public function testInvisiblePrefixesDoNotBlockPromotion(): void
    {
        $converter = new CarveConverter();
        foreach (
            [
                '{%%}![alt](x.png)',
                "%% hidden\n![alt](x.png)",
                "{%%}![alt][r]\n\n[r]: x.png",
            ] as $source
        ) {
            self::assertSame('<img src="x.png" alt="alt">' . "\n", $converter->convert($source));
        }
    }

    public function testImportersWritePlainImages(): void
    {
        self::assertSame('![alt](x.png)', (new DjotToCarve())->convert('![alt](x.png)'));
        self::assertSame('![alt](x.png)', rtrim((new MarkdownToCarve())->convert('![alt](x.png)')));
        $result = (new HtmlToCarve())->convertWithFidelityReport('<p><img src="x.png" alt="alt"></p>');
        self::assertSame('![alt](x.png)', rtrim($result->value));
        self::assertSame([], $result->diagnostics);
    }
}

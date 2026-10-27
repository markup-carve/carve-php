<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
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
            self::assertSame(preg_replace('/\n\z/', '', '<img src="x.png" alt="alt">' . "\n"), $converter->convert($source));
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

    public function testInvisibleChildSurvivesPromotionAndAstIngest(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $tree = $codec->encode($converter->parse('{%%}![alt](x.png)'));

        self::assertSame('paragraph', $tree['children'][0]['type']);
        self::assertTrue($tree['children'][0]['blockImage']);
        self::assertCount(2, $tree['children'][0]['children']);
        self::assertSame('<img src="x.png" alt="alt">' . "\n", $converter->render($codec->decode($tree)));
    }

    public function testTaskItemRetainsPromotedParagraph(): void
    {
        $tree = (new AstCodec())->encode((new CarveConverter())->parse("- [ ] ![alt][r]\n\n[r]: x.png\n"));

        self::assertSame('paragraph', $tree['children'][0]['items'][0]['children'][0]['type']);
        self::assertTrue($tree['children'][0]['items'][0]['children'][0]['blockImage']);
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class LabelWhitespaceKeyTest extends TestCase
{
    public function testLinksAndImagesNormalizeAsciiWhitespace(): void
    {
        $converter = CarveConverter::create();
        self::assertStringContainsString('href="/u"', $converter->convert("[t][ a  b ]\n\n[a b]: /u\n"));
        self::assertStringContainsString('src="/i"', $converter->convert("![x][ a\tb ]\n\n[a b]: /i\n"));
    }

    public function testLabelsRemainCaseSensitiveAndKeepNonAsciiWhitespace(): void
    {
        $converter = CarveConverter::create();
        self::assertStringNotContainsString('href="/u"', $converter->convert("[t][A B]\n\n[a b]: /u\n"));
        self::assertStringNotContainsString('href="/u"', $converter->convert("[t][a\u{00a0}b]\n\n[a b]: /u\n"));
    }

    public function testNormalizationDoesNotMakeMultilineLabelsValid(): void
    {
        $converter = CarveConverter::create();
        self::assertStringNotContainsString('href="/u"', $converter->convert("[t][a\nb]\n\n[a b]: /u\n"));
        self::assertStringNotContainsString('doc-noteref', $converter->convert("x[^a\nb]\n\n[^a b]: note\n"));
    }

    public function testFootnotesUseTheSameKeyAndFirstDefinitionWins(): void
    {
        $html = CarveConverter::create()->convert("[^ a\t b ]\n\n[^a b]: first\n\n[^ a  b ]: second\n");
        self::assertStringContainsString('doc-noteref', $html);
        self::assertStringContainsString('first', $html);
        self::assertStringNotContainsString('second', $html);
    }

    public function testLastLinkDefinitionWinsAndKeepsItsSpelling(): void
    {
        $source = "[t][a b]\n\n[a b]: /first\n\n[a  b]: /last\n";
        $converter = CarveConverter::create();
        self::assertStringContainsString('href="/last"', $converter->convert($source));
        self::assertStringContainsString('[a  b]: /last', $converter->toCarve($source));
        self::assertStringContainsString('href="/last"', (new CarveConverter())->convert($source));
    }
}

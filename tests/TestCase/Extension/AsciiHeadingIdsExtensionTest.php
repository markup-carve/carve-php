<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\AsciiHeadingIdsExtension;
use Carve\Extension\HeadingReferenceExtension;
use PHPUnit\Framework\TestCase;
use Transliterator;
use function class_exists;

class AsciiHeadingIdsExtensionTest extends TestCase
{
    public function testDefaultKeepsNonAsciiVerbatim(): void
    {
        $html = (new CarveConverter())->convert('# Über uns');

        // No extension: id keeps non-ASCII verbatim (case preserved).
        $this->assertStringContainsString('<section id="Über-uns">', $html);
    }

    public function testExtensionFoldsLatinDiacriticsToAscii(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert('# Über uns');

        $this->assertStringContainsString('<section id="Uber-uns">', $html);
        $this->assertStringContainsString('>Über uns</h1>', $html);
    }

    public function testExtensionFoldsCyrillic(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert('# Привет мир');

        $this->assertStringContainsString('<section id="Privet-mir">', $html);
    }

    public function testDigitLeadingSlugKeepsThePrefixAfterFold(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert('# 2024 Recap');

        $this->assertStringContainsString('<section id="s-2024-Recap">', $html);
    }

    public function testImplicitReferenceResolvesToTheFoldedId(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingReferenceExtension());
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert(<<<'DJOT'
See [[Über uns]].

# Über uns
DJOT);

        // The parse-time tracker must apply the same fold, otherwise the
        // implicit [[...]] reference would point at the unfolded id.
        $this->assertStringContainsString('href="#Uber-uns"', $html);
    }

    public function testCjkIsRomanizedWithIntl(): void
    {
        if (!class_exists(Transliterator::class)) {
            $this->markTestSkipped('ext-intl not available');
        }

        $converter = new CarveConverter();
        $converter->addExtension(new AsciiHeadingIdsExtension());

        $html = $converter->convert('# 日本語の見出し');

        $this->assertStringContainsString('<section id="ri-ben-yuno-jian-chushi">', $html);
        $this->assertStringContainsString('>日本語の見出し</h1>', $html);
    }
}

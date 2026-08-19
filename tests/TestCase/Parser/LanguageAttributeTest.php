<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

final class LanguageAttributeTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testShorthandDesugarsToLang(): void
    {
        self::assertSame('<p><span lang="fr">x</span></p>', trim($this->converter->convert('[x]{:fr}')));
        self::assertSame('<p><span lang="">x</span></p>', trim($this->converter->convert('[x]{:}')));
        self::assertSame('<p><span lang="fr">x</span></p>', trim($this->converter->convert('[x]{lang=de :fr}')));
        self::assertSame('<p><span id="id" lang="fr" class="cls">x</span></p>', trim($this->converter->convert('[x]{#id :fr .cls}')));
    }

    public function testMalformedTagsRemainLiteral(): void
    {
        foreach ([':tada:', ':en_US', ':en--GB', ':-en', ':en-', ':français', ':abcdefghi'] as $attribute) {
            $html = (string)$this->converter->convert('[x]{' . $attribute . '}');
            self::assertStringNotContainsString('<span', $html, $attribute);
            self::assertStringNotContainsString('lang=', $html, $attribute);
        }
    }

    public function testSigilTakesNoPadding(): void
    {
        self::assertSame('<p><span lang="" fr="">x</span></p>', trim($this->converter->convert('[x]{: fr}')));
    }

    public function testCanonicalWriterUsesShorthand(): void
    {
        $writer = new CarveRenderer();
        self::assertSame("[x]{:fr}\n", $writer->render($this->converter->parse("[x]{lang=fr}\n")));
        self::assertSame("[x]{:}\n", $writer->render($this->converter->parse("[x]{lang=\"\"}\n")));
        self::assertSame("[x]{:de}\n", $writer->render($this->converter->parse("[x]{:fr lang=de}\n")));
        self::assertSame("[x]{lang=en_US}\n", $writer->render($this->converter->parse("[x]{lang=en_US}\n")));
    }

    public function testCanonicalWriterUsesShorthandOnFencedDivs(): void
    {
        $writer = new CarveRenderer();
        self::assertSame("{:fr}\n::: note\nBody.\n:::\n", $writer->render($this->converter->parse("{lang=fr}\n::: note\nBody.\n:::\n")));
        self::assertSame("{lang=en_US}\n::: note\nBody.\n:::\n", $writer->render($this->converter->parse("{lang=en_US}\n::: note\nBody.\n:::\n")));
    }
}

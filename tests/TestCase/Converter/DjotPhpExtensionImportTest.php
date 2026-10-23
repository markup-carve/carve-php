<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use PHPUnit\Framework\TestCase;

class DjotPhpExtensionImportTest extends TestCase
{
    public function testUnquotedAdmonitionTitleIsQuoted(): void
    {
        $source = "::: tip Live sandbox\nWant to try it?\n:::\n";
        $carve = (new DjotToCarve(djotPhpExtensions: true))->convert($source);

        $this->assertSame("::: tip \"Live sandbox\"\nWant to try it?\n:::\n", $carve);
        $this->assertStringContainsString('<p class="admonition-title" id="adm-1">Live sandbox</p>', (new CarveConverter())->convert($carve));
    }

    public function testLabeledFenceRemainsCodeBlockInCodeGroup(): void
    {
        $source = "::: code-group\n\n``` php [From an array]\n\$a = 1;\n```\n\n:::\n";
        $carve = (new DjotToCarve(djotPhpExtensions: true))->convert($source);

        $this->assertStringContainsString("``` php [From an array]\n\$a = 1;\n```", $carve);
        $renderer = (new CarveConverter())->addExtension(new CodeGroupExtension());
        $html = $renderer->convert($carve);
        $this->assertStringContainsString('From an array', $html);
        $this->assertStringContainsString('language-php', $html);
    }

    public function testExtensionLikeLinesInsideCodeStayLiteral(): void
    {
        foreach (["```\n::: tip Live sandbox\n```\n", "``` php [Example]\n::: tip Live sandbox\n```\n", "``` php [Example: php]\n::: tip Live sandbox\n```\n"] as $source) {
            $this->assertSame($source, (new DjotToCarve(djotPhpExtensions: true))->convert($source));
        }
    }

    public function testStrictDjotKeepsExtensionOpenersLiteral(): void
    {
        $this->assertSame("\\::: tip Live sandbox\nbody\n\\:::\n", (new DjotToCarve())->convert("::: tip Live sandbox\nbody\n:::\n"));
        $this->assertSame("` php [Example]\ncode\n`\n", (new DjotToCarve())->convert("``` php [Example]\ncode\n```\n"));
    }

    public function testOnlyBlockOpenersForKnownAdmonitionsAreQuoted(): void
    {
        $converter = new DjotToCarve(djotPhpExtensions: true);

        $this->assertSame("Some text\n\\::: tip Live sandbox", $converter->convert("Some text\n::: tip Live sandbox"));
        $this->assertSame("`a\n::: tip Live sandbox` b", $converter->convert("`a\n::: tip Live sandbox` b"));
        $this->assertStringContainsString('\\:::: a b', $converter->convert("::: outer\n:::: a b\nx\n:::\nafter"));
        $this->assertStringContainsString('\\:::: tip Title', $converter->convert("::: foo\n\n:::: tip Title\nx\n::::\n\n:::\nafter"));
        $this->assertStringNotContainsString('"Some title"', $converter->convert("::: foo-box Some title\nbody\n:::"));
    }

    public function testQuotedAdmonitionInsideBlockQuote(): void
    {
        $source = "> ::: tip Live sandbox\n> body\n> :::\n";

        $this->assertSame("> ::: tip \"Live sandbox\"\n> body\n> :::\n", (new DjotToCarve(djotPhpExtensions: true))->convert($source));
    }

    public function testLaterAdmonitionsKeepTheirTitles(): void
    {
        $converter = new DjotToCarve(djotPhpExtensions: true);
        $source = "::: tip One title\na\n:::\n\n::: warning Two title\nb\n:::\n";

        $this->assertStringContainsString('::: warning "Two title"', $converter->convert($source));
        $nested = "::: foo\n\n:::: tip Inner title\nx\n::::\n:::\n\n::: warning Later title\ny\n:::\n";
        $this->assertStringContainsString('::: warning "Later title"', $converter->convert($nested));
        $shorterCloser = "::: outer\n\n:::: inner\nx\n:::\n\n::: tip Later title\ny\n:::\n";
        $this->assertStringContainsString('::: tip "Later title"', $converter->convert($shorterCloser));
        $quotedCloser = "::: outer\n\n> :::: inner\n> x\n> :::\n\n::: tip Later title\ny\n:::\n:::\n";
        $this->assertStringContainsString('\\::: tip Later title', $converter->convert($quotedCloser));
        foreach (
            [
                "Some text\n::: tip Live\nmore\n\n",
                "> ::: tip A\n> body\n\n",
                "`a\n::: tip X` b\n\n",
                "::: foo-box Some title\nbody\n:::\n\n",
            ] as $prefix
        ) {
            $this->assertStringContainsString('::: warning "Later title"', $converter->convert($prefix . "::: warning Later title\ny\n:::\n"));
        }
    }
}

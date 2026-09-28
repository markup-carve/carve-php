<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An angle-bracketed run alone on a line is an HTML block only when it spells a
 * TAG.
 *
 * CommonMark condition 7 opens a block on a complete open or closing tag, and a
 * tag needs a name: `[A-Za-z][A-Za-z0-9-]*`. The importer tested the shape
 * `<...>` instead, so every autolink on a line of its own arrived as a
 * ```` ```=html ```` block and the link was gone from every non-HTML target
 * (carve-php#2635). Eighteen of the 652 CommonMark 0.31.2 examples took that
 * path; twelve of them held a link.
 */
class AMarkdownAutolinkIsNotAnHtmlBlockTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function autolinkShapes(): array
    {
        return [
            // The Autolinks section of CommonMark 0.31.2, examples 594-610.
            'a bare scheme' => ["<http://foo.bar.baz>\n", "<http://foo.bar.baz>\n"],
            'a query string' => [
                "<https://foo.bar.baz/test?q=hello&id=22&boolean>\n",
                "<https://foo.bar.baz/test?q=hello&id=22&boolean>\n",
            ],
            'a port' => ["<irc://foo.bar:2233/baz>\n", "<irc://foo.bar:2233/baz>\n"],
            'an uppercase mailto' => ["<MAILTO:FOO@BAR.BAZ>\n", "<MAILTO:FOO@BAR.BAZ>\n"],
            'a scheme holding plus signs' => ["<a+b+c:d>\n", "<a+b+c:d>\n"],
            'a made-up scheme' => ["<made-up-scheme://foo,bar>\n", "<made-up-scheme://foo,bar>\n"],
            'a relative path' => ["<https://../>\n", "<https://../>\n"],
            'a host and port only' => ["<localhost:5001/foo>\n", "<localhost:5001/foo>\n"],
            'an email' => ["<foo@bar.example.com>\n", "<foo@bar.example.com>\n"],
            'an email holding a plus sign' => [
                "<foo+special@Bar.baz-bar0.com>\n",
                "<foo+special@Bar.baz-bar0.com>\n",
            ],
            // Not autolinks in CommonMark either, and not HTML blocks either:
            // they are paragraph text, which is what the importer must write.
            'a space inside the brackets' => ["<https://foo.bar/baz bim>\n", "<https://foo.bar/baz bim>\n"],
            'an escaped plus in an email' => ["<foo\\+@bar.example.com>\n", "<foo\\+@bar.example.com>\n"],
            'an empty bracket pair' => ["<>\n", "<>\n"],
            'brackets padded with spaces' => ["< https://foo.bar >\n", "< https://foo.bar >\n"],
            'a dotted run with no scheme' => ["<foo.bar.baz>\n", "<foo.bar.baz>\n"],
            // Controls: a real tag name still opens the block.
            'an unknown tag alone' => ["<x>\n", "```=html\n<x>\n```\n"],
            'a self-closing unknown tag' => ["<x/>\n", "```=html\n<x/>\n```\n"],
            'a closing tag alone' => ["</x>\n", "```=html\n</x>\n```\n"],
            'a tag carrying an attribute' => ["<x foo=\"a\">\n", "```=html\n<x foo=\"a\">\n```\n"],
            'a hyphenated tag name' => ["<x-y>\n", "```=html\n<x-y>\n```\n"],
            'a tag name holding an underscore is no tag' => ["<x_y>\n", "<x_y>\n"],
            'a name opening with a digit is no tag' => ["<1x>\n", "<1x>\n"],
        ];
    }

    #[DataProvider('autolinkShapes')]
    public function testTheAutolinkSurvivesTheImport(string $markdown, string $carve): void
    {
        $this->assertSame($carve, (new MarkdownToCarve())->convert($markdown));
    }

    /**
     * The point of the import: the link has to be a link again when the written
     * Carve is read. A raw block cannot carry one to any non-HTML target.
     *
     * @return array<string, array{string, string}>
     */
    public static function renderedShapes(): array
    {
        return [
            'a bare scheme' => [
                "<http://foo.bar.baz>\n",
                '<p><a href="http://foo.bar.baz">http://foo.bar.baz</a></p>',
            ],
            'an email' => [
                "<foo@bar.example.com>\n",
                '<p><a href="mailto:foo@bar.example.com">foo@bar.example.com</a></p>',
            ],
            'a host and port only' => [
                "<localhost:5001/foo>\n",
                '<p><a href="localhost:5001/foo">localhost:5001/foo</a></p>',
            ],
            'a space inside the brackets stays text' => [
                "<https://foo.bar/baz bim>\n",
                '<p>&lt;https://foo.bar/baz bim&gt;</p>',
            ],
        ];
    }

    #[DataProvider('renderedShapes')]
    public function testTheWrittenCarveRendersTheLink(string $markdown, string $html): void
    {
        $carve = (new MarkdownToCarve())->convert($markdown);

        $this->assertSame($html, trim(CarveConverter::create()->convert($carve)));
    }

    /**
     * A tag on the line BELOW prose cannot open a condition 7 block, and neither
     * can an autolink. Both stay in the paragraph, by two different routes.
     */
    public function testAnAutolinkBelowProseStaysInTheParagraph(): void
    {
        $this->assertSame(
            "text\n<http://foo.bar.baz>\n",
            (new MarkdownToCarve())->convert("text\n<http://foo.bar.baz>\n"),
        );
    }
}

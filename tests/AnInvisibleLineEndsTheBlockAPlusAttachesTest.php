<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The block a `+` attaches to a footnote or a description ends at a comment
 * line or a link definition (PART 9 §10 I5, §17 L3): the invisible line ends
 * the paragraph, and the next line is read at the host's own column.
 */
class AnInvisibleLineEndsTheBlockAPlusAttachesTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'the pack case notes-aa4cc37942d1' => [
            "[^a]: intro\n+\nmore\n_u_\n%% c\n  more\n\n\nsee[^a]\n",
            "<p>more</p>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <p>more\n<u>u</u><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a comment line after the attached paragraph' => [
            "[^a]: intro\n+\nmore\n%% c\nmore\n\nsee[^a]\n",
            "<p>more</p>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <p>more<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a closer-less comment opener hides the rest' => [
            "[^a]: intro\n+\nmore\n%%%% d\nmore\n\nsee[^a]\n",
            '',
        ];

        yield 'a link definition in a description' => [
            ":: t\n: intro\n+\nmore\n[r]: /u\nmore\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>intro</p>\n    <p>more</p>\n  </dd>\n</dl>\n<p>more</p>",
        ];

        yield 'a comment after an attached quote' => [
            ":: t\n: intro\n+\n> q\n%% c\nmore\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>intro</p>\n    <blockquote><p>q</p></blockquote>\n  </dd>\n</dl>\n<p>more</p>",
        ];

        yield 'an attached list goes on past the comment' => [
            ":: t\n: intro\n+\n- inner\n%% c\n- s\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>intro</p>\n    <ul>\n      <li>inner</li>\n      <li>s</li>\n    </ul>\n  </dd>\n</dl>",
        ];

        yield 'an attached quote goes on at a quoted line' => [
            "[^a]: intro\n+\n> q\n[r]: /u\n> r\nmore\n\nsee[^a]\n",
            "<blockquote><p>r\nmore</p></blockquote>\n<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <blockquote><p>q</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>",
        ];

        yield 'a matched comment span keeps its reading' => [
            ":: t\n: intro\n+\nmore\n%%%\nc\n%%%\nmore\n",
            "<dl>\n  <dt>t</dt>\n  <dd>\n    <p>intro</p>\n    <p>more</p>\n  </dd>\n</dl>\n<p>more</p>",
        ];
    }

    #[DataProvider('cases')]
    public function testTheAttachedBlockEndsAtTheInvisibleLine(string $source, string $expected): void
    {
        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}

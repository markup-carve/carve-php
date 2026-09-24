<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\MarkdownToCarve;
use PHPUnit\Framework\TestCase;

/**
 * The importer writes `- %%` for an item it emptied, so an authored `%%` has to
 * come out escaped.
 *
 * Markdown has no comment syntax: `- %%` there is an item whose text is two
 * percent signs, and Carve reads an unescaped `%%` as a comment running to the
 * end of the line. Writing the emptied item through a sentinel is what keeps the
 * synthetic comment out of the escaper's way (#2311), and nothing pinned the
 * other half of that arrangement.
 *
 * Both cases are needed. Either one alone passes while the other is broken: an
 * escaper that never escapes `%%` still writes the synthetic comment correctly,
 * and an emptied item written as a literal `%%` still escapes an authored one.
 */
class AnAuthoredCommentSurvivesTheImportersOwnTest extends TestCase
{
    public function testAnAuthoredCommentIsEscapedToLiteralText(): void
    {
        $imported = (new MarkdownToCarve())->convert("- %%\n");

        $this->assertSame("- \\%%\n", $imported);
        $this->assertSame(
            "<ul>\n  <li>%%</li>\n</ul>\n",
            (new CarveConverter())->convert($imported),
            'the text the author wrote did not reach the rendered item',
        );
    }

    public function testAnAuthoredCommentIsEscapedWhereverItIsWritten(): void
    {
        $importer = new MarkdownToCarve();

        // The escape has to hold on the item's content column and on the one the
        // block openers use, because two different rules reach them.
        $this->assertSame("- \\%% mine\n", $importer->convert("- %% mine\n"));
        $this->assertSame("\\%%\n", $importer->convert("%%\n"));
    }

    public function testAnEmptiedItemKeepsTheSyntheticCommentUnescaped(): void
    {
        // The definition on the marker line moves to the end of the document and
        // leaves the item with nothing in it.
        $imported = (new MarkdownToCarve())->convert("- [x]: /u\n- b [x]\n");

        $this->assertSame("- %%\n- b [x][]\n\n[x]: /u\n", $imported);
        $this->assertSame(
            "<ul>\n  <li></li>\n  <li>b <a href=\"/u\">x</a></li>\n</ul>\n",
            (new CarveConverter())->convert($imported),
            'the synthetic comment was not read as a comment',
        );
    }

    /**
     * The two forms are told apart on the same document, which is the property
     * the sentinel exists for. Asserting them in separate documents would pass a
     * build that escaped or spared every `%%` alike.
     */
    public function testTheTwoFormsStayDistinguishableSideBySide(): void
    {
        $imported = (new MarkdownToCarve())->convert("- [x]: /u\n- %%\n");

        $this->assertSame("- %%\n- \\%%\n\n[x]: /u\n", $imported);
        $this->assertSame(
            "<ul>\n  <li></li>\n  <li>%%</li>\n</ul>\n",
            (new CarveConverter())->convert($imported),
        );
    }
}

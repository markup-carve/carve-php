<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Comments render nothing when either the host or the caption is selected.
 * PART 9R shares that eligibility rule across image and display-math hosts.
 */
class ACaptionNeedsVisibleContentTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'display math with a trailing comment' => [
            "$$`a` %% c\n^ cap\n",
            "<figure>\n  <p><span class=\"math display\" role=\"math\">\\[a\\]</span></p>\n  <figcaption>cap</figcaption>\n</figure>",
        ];

        yield 'image with a leading braced comment' => [
            "{%%}![a](/i)\n^ cap\n",
            "<figure>\n  <img src=\"/i\" alt=\"a\">\n  <figcaption>cap</figcaption>\n</figure>",
        ];

        yield 'table with a line-comment-only caption' => [
            "| a |\n^ %% x\n",
            "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>^</p>",
        ];

        yield 'quote with a comment-only caption after a blank' => [
            "> q\n\n^ %% x\n",
            "<blockquote><p>q</p></blockquote>\n<p>^</p>",
        ];

        yield 'comment-only caret continues an open quote paragraph' => [
            "> q\n^ %% x\n",
            "<blockquote><p>q\n^</p></blockquote>",
        ];

        yield 'comment-only caret continues an image paragraph' => [
            "![a](/i)\n^ %% x\n",
            "<p><img src=\"/i\" alt=\"a\">\n^</p>",
        ];

        yield 'comment-only caret continues a display-math paragraph' => [
            "$$`a`\n^ %% x\n",
            "<p><span class=\"math display\" role=\"math\">\\[a\\]</span>\n^</p>",
        ];

        yield 'braced comment alone cannot fill a caption' => [
            "| a |\n^ {% x %}\n",
            "<table>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>\n<p>^ </p>",
        ];

        yield 'text after a comment fills the caption' => [
            "| a |\n^ {% x %}y\n",
            "<table>\n  <caption>y</caption>\n  <tbody>\n    <tr><td>a</td></tr>\n  </tbody>\n</table>",
        ];
    }

    #[DataProvider('cases')]
    public function testCaptionEligibilityUsesVisibleInlineContent(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testTheEligibilityProbeDoesNotDuplicateReferenceWarnings(): void
    {
        $converter = new CarveConverter(warnings: true);
        $converter->convert("| a |\n^ {% x %}[t][undef]\n");

        $warnings = $converter->getWarnings();
        $this->assertCount(1, $warnings);
        $this->assertSame(2, $warnings[0]->getLine());
    }
}

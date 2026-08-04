<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 9 §24 C3 recognizes a comment at ANY column and keeps it invisible, and
 * that covers the `%%%` FENCE form as well as the `%%` line.
 *
 * The fence IS a block start, so it satisfied the fold test and came back as
 * visible text; the line form is not a block start, so it already fell through
 * and stayed invisible. One branch, two outcomes for the same construct
 * (carve-php#770).
 */
class CommentFenceAtAnyColumnTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    public function testAnIndentedFenceOpenerIsInvisible(): void
    {
        $html = $this->converter->convert("- a\n %%% n");

        $this->assertStringNotContainsString('%%%', $html);
        $this->assertSame("<ul>\n  <li>a</li>\n</ul>", trim($html));
    }

    public function testTheFenceBodyIsInvisibleToo(): void
    {
        // Rendering the opener would put the comment on the page; rendering its
        // BODY defeats the construct entirely.
        $html = $this->converter->convert("- a\n %%% n\n x\n %%%\n tail");

        $this->assertStringNotContainsString('%%%', $html);
        $this->assertStringNotContainsString('>x<', $html);
        $this->assertStringContainsString('tail', $html);
    }

    public function testTheLineFormIsUnchanged(): void
    {
        $html = $this->converter->convert("- a\n %% c");

        $this->assertStringNotContainsString('%%', $html);
        $this->assertSame("<ul>\n  <li>a</li>\n</ul>", trim($html));
    }

    public function testAFenceAtTheContentColumnStillWorks(): void
    {
        $html = $this->converter->convert("- a\n  %%% n");

        $this->assertStringNotContainsString('%%%', $html);
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A comment produces no output (PART 9 §28), so it cannot become term text.
 *
 * A term folds a FOLLOWING PLAIN LINE as a soft break, and this engine was
 * counting a `%%` line comment as one - rendering the comment's SOURCE inside
 * the `<dt>`. A comment BLOCK (`%%%`) already ended the term correctly, so the
 * engine disagreed with itself as well as with carve-rs and carve-js
 * (carve-php#671).
 */
class CommentUnderDefinitionTermTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    private function squash(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', $html));
    }

    public function testABareCommentDoesNotJoinTheTerm(): void
    {
        $this->assertSame('<dl> <dt>t</dt> </dl>', $this->squash($this->converter->convert(":: t\n%%\n")));
    }

    public function testACommentWithContentDoesNotJoinTheTerm(): void
    {
        $this->assertSame('<dl> <dt>t</dt> </dl>', $this->squash($this->converter->convert(":: t\n%% c\n")));
    }

    public function testACommentBlockIsUnchanged(): void
    {
        // Already correct; here so a fix cannot regress the case that worked.
        $this->assertSame(
            '<dl> <dt>t</dt> </dl>',
            $this->squash($this->converter->convert(":: t\n%%%\nx\n%%%\n")),
        );
    }

    public function testAPlainLineStillFoldsIntoTheTerm(): void
    {
        // The rule the comment was being mistaken for: "A term folds a
        // following plain line as a soft break."
        $this->assertSame(
            '<dl> <dt>t plain</dt> </dl>',
            $this->squash($this->converter->convert(":: t\nplain\n")),
        );
    }

    public function testACommentBetweenTermAndDefinitionEndsTheEntry(): void
    {
        // Pinned as MEASURED, not as assumed: carve-rs and carve-js do this too,
        // so the comment ends the entry and the `:  d` line is left as prose in
        // all three engines.
        //
        // Arguably a comment should be transparent here - it renders nothing,
        // and an author would expect it to annotate the entry rather than break
        // it. But three engines agree, so changing it is a language decision and
        // not this fix's business. Recorded so the agreement is deliberate.
        $this->assertSame(
            '<dl> <dt>t</dt> </dl> <p>: d</p>',
            $this->squash($this->converter->convert(":: t\n%%\n:  d\n")),
        );
    }

    public function testADefinitionStillAttachesWithNoCommentBetween(): void
    {
        $this->assertSame(
            '<dl> <dt>t</dt> <dd>d</dd> </dl>',
            $this->squash($this->converter->convert(":: t\n:  d\n")),
        );
    }
}

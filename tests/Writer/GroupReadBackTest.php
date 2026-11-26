<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Writer;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\TestCase;

/**
 * C2-W-088, C2-W-133: the read-back rounds of a risky block group run without the plain groups around it
 * (carve-php#5734), so its form no longer depends on an unrelated group.
 */
final class GroupReadBackTest extends TestCase
{
    /**
     * @var string
     */
    private const BLOCK = "1. x\n[t](u)\n_u_\n[r]: /u\n[t][r]\n\\#\n";

    /**
     * @var string
     */
    private const BLOCK_FORM = "1. x\n   [t](u)\n   \\_u_\n   [r]: /u\n   [t][r]\n   #\n";

    public function testTicketDocumentKeepsItsForm(): void
    {
        $paragraph = 'Some *strong* and _emph_ text with `code` and more words here.';
        $form = 'Some *strong* and \\_emph_ text with `code` and more words here.';
        $source = str_repeat($paragraph . "\n\n", 500) . self::BLOCK;

        self::assertSame(str_repeat($form . "\n\n", 500) . self::BLOCK_FORM, Carve::format($source));
    }

    public function testRiskyGroupFormIgnoresAnUnrelatedGroup(): void
    {
        $alone = Carve::format(self::BLOCK);
        $source = "a literal \\*star* here\n\n" . self::BLOCK;

        self::assertSame(self::BLOCK_FORM, $alone);
        self::assertSame("a literal \\*star* here\n\n" . self::BLOCK_FORM, Carve::format($source));
    }

    /**
     * Each risky group is checked on its own where no other block feeds its parse, as carve-js repairs group by group.
     */
    public function testRiskyGroupFormIgnoresAnotherRiskyGroup(): void
    {
        $other = "/*{*a*/*} b*/\n";

        self::assertSame(Carve::format($other) . "\n" . self::BLOCK_FORM, Carve::format($other . "\n" . self::BLOCK));
        self::assertSame(self::BLOCK_FORM . "\n" . Carve::format($other), Carve::format(self::BLOCK . "\n" . $other));
    }

    public function testRepeatedRiskyGroupsTakeOneForm(): void
    {
        $group = "/*{*a*/*} b*/\n";
        $form = Carve::format($group);

        self::assertSame(str_repeat($form . "\n", 40) . self::BLOCK_FORM, Carve::format(str_repeat($group . "\n", 40) . self::BLOCK));
    }

    /**
     * Headings, notes and definitions stay beside the risky group, so ids, note numbers and references read back.
     */
    public function testResolvedContextReadsBack(): void
    {
        $source = "= A\n\npara one[^n] and [t][r]\n\nplain words\n\n= A\n\n" . self::BLOCK . "\nmore[^m] plain\n\n"
            . "[^n]: note\n\n[^m]: other\n\n[r]: /v\n";
        $out = Carve::format($source);

        self::assertTrue(TreeEquality::equal(
            TreeEncoder::decodedForWriter(Carve::parse($source)),
            TreeEncoder::decodedForWriter(Carve::parse($out)),
        ));
        self::assertSame($out, Carve::format($out));
    }
}

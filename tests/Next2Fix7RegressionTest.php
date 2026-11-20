<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Import\HtmlImporter;
use PHPUnit\Framework\TestCase;

final class Next2Fix7RegressionTest extends TestCase
{
    public function testLaterDescriptionMarksInAnItemParagraphStayBare(): void
    {
        $source = "- a\n  :: term\n  : desc\n";
        self::assertSame($source, Carve::format($source));
        self::assertSame($source, Carve::format(Carve::format($source)));
    }

    public function testEveryUntitledFootnoteSectionOnlyContributesNotes(): void
    {
        $html = '<p>c<a role="doc-noteref" href="#fn1">x</a><a role="doc-noteref" href="#fn2">y</a></p>'
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>Old</p></li></ol></section>'
            . '<section role="doc-endnotes"><ol><li id="fn2"><p>New</p></li></ol></section>';
        self::assertSame("c[^1][^2]\n\n[^1]: Old\n\n[^2]: New\n", HtmlImporter::import($html)->source);
    }

    public function testAttributedParagraphBeyondAnItemContentColumnIsReported(): void
    {
        $source = "- a\n\n    {.c}\n  b\n";
        $matches = array_values(array_filter(Carve::lint($source), static fn ($d): bool => $d->code === 'list-item-block-overindented'));
        self::assertCount(1, $matches);
        self::assertSame([9, 13], [$matches[0]->startOffset, $matches[0]->endOffset]);
    }
}

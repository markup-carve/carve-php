<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Footnote;
use MarkupCarve\Carve\Node\Block\RawBlock;
use PHPUnit\Framework\TestCase;

class ARawFenceOnAnItemNoteKeepsItsFinalBlankTest extends TestCase
{
    public function testTheFinalBlankBelongsToTheUnclosedRawPayload(): void
    {
        $source = "a[^n]\n\n- [^n]: ```=html\n    x \n\nafter\n";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $note = null;
        foreach ($document->getChildren() as $child) {
            if ($child instanceof Footnote) {
                $note = $child;
            }
        }

        self::assertInstanceOf(Footnote::class, $note);
        $raw = $note->getChildren()[0] ?? null;
        self::assertInstanceOf(RawBlock::class, $raw);
        self::assertSame("x \n\n", $raw->getContent());
        self::assertStringContainsString("\n      x \n\n      <p>", $converter->render($document));
    }
}

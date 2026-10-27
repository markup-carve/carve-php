<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class ADescriptionParagraphAfterNestedListKeepsLazyTextTest extends TestCase
{
    public function testParagraphAfterNestedFootnoteListKeepsItsLazyLine(): void
    {
        self::assertSame(
            "<dl>\n  <dt>t</dt>\n  <dd>tail\nx</dd>\n</dl>\n",
            CarveConverter::create()->convert(":: t\n:  [^f]: note\n     - nested\n   tail\nx\n"),
        );
    }
}

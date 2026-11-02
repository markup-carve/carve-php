<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

class AFoldedDescriptionCommentKeepsItsBodyIndentTest extends TestCase
{
    public function testFoldedAndDocumentCommentsKeepTheirOwners(): void
    {
        $folded = ":: t\n:  d\n  %% c\n";
        $document = ":: t\n: d\n\n%% c\n";
        // CARVE-P11-079 (b): the document owns a comment below the description's column.
        self::assertSame($document, CarveConverter::toCarve($folded));
        self::assertSame($document, CarveConverter::toCarve($document));
        self::assertSame(CarveConverter::toCarve($folded), CarveConverter::toCarve(CarveConverter::toCarve($folded)));
    }
}

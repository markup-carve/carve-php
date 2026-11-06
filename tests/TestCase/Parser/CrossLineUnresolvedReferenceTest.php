<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\DocumentLinter;
use MarkupCarve\Carve\Node\Inline\Link;
use PHPUnit\Framework\TestCase;

final class CrossLineUnresolvedReferenceTest extends TestCase
{
    public function testCrossLineLabelKeepsOneUnresolvedReference(): void
    {
        $source = "[*t*][a\nb]\n";
        $converter = new CarveConverter();
        $document = $converter->parse($source);
        $link = $document->getChildren()[0]->getChildren()[0];

        self::assertInstanceOf(Link::class, $link);
        self::assertSame("a\nb", $link->getReferenceLabel());
        self::assertSame("[*t*][a\nb]", $link->getRawReferenceLabel());
        self::assertSame(['strong'], array_map(static fn ($child): string => $child->getType(), $link->getChildren()));
        self::assertSame("<p>[*t*][a\nb]</p>\n", $converter->render($document));

        $findings = array_values(array_filter(
            (new DocumentLinter())->lint($source),
            static fn ($warning): bool => $warning->rule === 'unresolved-reference-link',
        ));
        self::assertCount(1, $findings);
        self::assertSame([1, 1], [$findings[0]->line, $findings[0]->column]);
    }
}

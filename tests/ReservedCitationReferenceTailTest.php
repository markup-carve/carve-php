<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use PHPUnit\Framework\TestCase;

final class ReservedCitationReferenceTailTest extends TestCase
{
    public function testLinkAndImageTailsStayLiteralWithOrWithoutCitations(): void
    {
        foreach ([false, true] as $withCitations) {
            foreach (['[t][@a]', '![t][@a]', '[][@a]', '![][@a]'] as $source) {
                $converter = new CarveConverter();
                if ($withCitations) {
                    $converter->addExtension(new CitationsExtension());
                }
                $paragraph = $converter->parse($source)->getChildren()[0];
                self::assertSame(['text'], array_map(static fn ($node) => $node->getType(), $paragraph->getChildren()), $source);
                self::assertSame("<p>{$source}</p>\n", $converter->convert($source));
            }
        }
    }

    public function testOtherLabelsAndStandaloneCitationsStillParse(): void
    {
        $converter = new CarveConverter();
        $children = $converter->parse('[t][a] and ![t][a]')->getChildren()[0]->getChildren();
        $types = array_map(static fn ($node) => $node->getType(), $children);
        self::assertContains('link', $types);
        self::assertContains('image', $types);

        $converter->addExtension(new CitationsExtension());
        self::assertSame('citation_group', $converter->parse('[@a]')->getChildren()[0]->getChildren()[0]->getType());
    }
}

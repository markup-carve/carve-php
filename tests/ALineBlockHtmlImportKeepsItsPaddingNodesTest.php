<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class ALineBlockHtmlImportKeepsItsPaddingNodesTest extends TestCase
{
    public function testLeadingSpacesStayNonBreakingSpaceNodes(): void
    {
        $source = file_get_contents(__DIR__ . '/spec/tests/corpus/561-a-line-block-payload-span-pairs-past-the-content-column-3.crv');
        $html = (new CarveConverter(roundTripMode: true))->convert($source);
        foreach (['safe', 'semantic', 'roundtrip'] as $mode) {
            $importer = new HtmlToCarve(importMode: $mode);
            $tree = $importer->convertToAst($html);
            $paragraph = $tree['children'][0]['items'][0]['children'][0]['children'][0];
            self::assertSame(['non_breaking_space', 'non_breaking_space', 'code'], array_column($paragraph['children'], 'type'));
            $pending = [(new BlockParser())->parse($importer->convert($html))];
            $spaces = 0;
            while ($pending !== []) {
                $node = array_pop($pending);
                $spaces += (int)($node->getType() === 'non_breaking_space');
                array_push($pending, ...$node->getChildren());
            }
            self::assertSame(2, $spaces);
        }
    }
}

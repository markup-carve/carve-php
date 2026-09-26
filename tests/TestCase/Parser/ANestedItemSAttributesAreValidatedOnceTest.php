<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Node\Block\ListBlock;
use MarkupCarve\Carve\Parser\Block\ListParser;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

/**
 * Every enclosing list re-reads a nested item's marker line, so validating its
 * abutting payload there made a deep attributed list quadratic in its depth.
 */
final class ANestedItemSAttributesAreValidatedOnceTest extends TestCase
{
    public function testEachDistinctPayloadIsValidatedOnce(): void
    {
        $depth = 12;
        $prefix = 'n' . bin2hex(random_bytes(6));
        $source = '';
        for ($level = 0; $level < $depth; $level++) {
            $source .= str_repeat('  ', $level) . "-{#$prefix-$level .item} item $level\n";
        }

        $listParser = new class extends ListParser {
            public int $validations = 0;

            protected function validateMarkerAttributes(string $body): ?array
            {
                $this->validations++;

                return parent::validateMarkerAttributes($body);
            }
        };
        $parser = new class ($listParser) extends BlockParser {
            public function __construct(ListParser $listParser)
            {
                parent::__construct();
                $this->listParser = $listParser;
            }
        };

        $document = $parser->parse($source);

        $list = $document->getChildren()[0];
        self::assertInstanceOf(ListBlock::class, $list);
        self::assertSame("$prefix-0", $list->getChildren()[0]->getAttribute('id'));
        self::assertSame($depth, $listParser->validations);
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class CarveRendererCloneTest extends TestCase
{
    public function testClonedWriterKeepsItsOwnHookCalls(): void
    {
        $writer = new class extends CarveRenderer {
            public int $comparisons = 0;

            protected function canonicalizeAst(mixed $value): mixed
            {
                $this->comparisons++;

                return parent::canonicalizeAst($value);
            }

            public function compare(mixed $value): mixed
            {
                return $this->canonicalizeAst($value);
            }
        };
        $document = (new CarveConverter())->parse('hello *world*');
        $expected = $writer->render($document);
        $writer->compare($document);
        $comparisons = $writer->comparisons;
        $copy = clone $writer;
        self::assertSame($expected, $copy->render($document));
        $copy->compare($document);
        self::assertGreaterThan($comparisons, $copy->comparisons);
        self::assertSame($comparisons, $writer->comparisons);
    }
}

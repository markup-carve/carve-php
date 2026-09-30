<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

class WriterStateCompatibilityTest extends TestCase
{
    public function testProtectedStateWorksBeforeTheFirstRender(): void
    {
        $renderer = new class extends CarveRenderer {
            public function takeInitialAskedUnits(bool $seed = true): array
            {
                if ($seed) {
                    $this->askedUnits = [42 => true];
                }

                return $this->takeAskedUnits();
            }

            public function escapeModeBeforeRendering(): string
            {
                return $this->escapeModeHere();
            }
        };

        self::assertSame([42 => true], $renderer->takeInitialAskedUnits());
        self::assertSame([], $renderer->takeInitialAskedUnits(false));
        self::assertSame('conservative', $renderer->escapeModeBeforeRendering());
    }

    public function testClonedWriterUsesItsOwnHooksAndState(): void
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

            public function seedAskedUnit(int $id): void
            {
                $this->askedUnits = [$id => true];
            }

            public function askedUnits(): array
            {
                return $this->takeAskedUnits();
            }
        };
        $document = (new CarveConverter())->parse('hello *world*');
        $expected = $writer->render($document);
        $writer->compare($document);
        $comparisons = $writer->comparisons;
        $copy = clone $writer;
        $copy->seedAskedUnit(42);
        self::assertSame([], $writer->askedUnits());
        self::assertSame([42 => true], $copy->askedUnits());
        self::assertSame($expected, $copy->render($document));
        $copy->compare($document);
        self::assertGreaterThan($comparisons, $copy->comparisons);
        self::assertSame($comparisons, $writer->comparisons);
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CarveWriterKeepsAuthoredTypedDivClassTest extends TestCase
{
    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function documents(): iterable
    {
        yield 'class alone' => ["{.foo}\n::: foo\nx\n:::\n", ['foo', 'foo']];
        yield 'class after key' => ["{k=v .foo .c}\n::: foo\nx\n:::\n", ['foo', 'foo', 'c']];
        yield 'note class' => ["{.note}\n::: note\nx\n:::\n", ['note', 'note']];
        yield 'warning class' => ["{.warning}\n::: warning\nx\n:::\n", ['warning', 'warning']];
    }

    #[DataProvider('documents')]
    public function testAuthoredClassSurvivesFormatAndReparse(string $source, array $classes): void
    {
        $converter = new CarveConverter();
        $before = $converter->parse($source);
        $written = (new CarveRenderer())->render($before);
        $after = $converter->parse($written);

        self::assertSame($source, $written);
        self::assertSame($classes, $before->getChildren()[0]->getClassEntries());
        self::assertSame($classes, $after->getChildren()[0]->getClassEntries());
        self::assertSame($converter->convert($source), $converter->convert($written));
    }
}

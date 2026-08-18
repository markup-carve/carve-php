<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/** The definition-continuation matrix from markup-carve/carve#1376. */
class DefinitionContinuationColumnsTest extends TestCase
{
    protected CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = new CarveConverter();
    }

    protected function source(
        string $head,
        int $contentColumn,
        string $definition,
        int $continuationColumn,
    ): string {
        return $head . "\n"
            . str_repeat(' ', $contentColumn) . $definition . "\n"
            . str_repeat(' ', $continuationColumn) . "more\ntail\n";
    }

    protected function tailIsInside(string $html): bool
    {
        return strpos($html, 'tail') < strpos($html, '</li>');
    }

    public function testCollectedDefinitionsCloseTheBelowContentColumnPath(): void
    {
        foreach ([['- a', 2], ['1. a', 3], ['. a', 2]] as [$head, $contentColumn]) {
            foreach (['[^f]: t', '[r]: /u'] as $definition) {
                for ($column = 1; $column < $contentColumn; $column++) {
                    $html = $this->converter->convert(
                        $this->source($head, $contentColumn, $definition, $column),
                    );
                    $this->assertFalse($this->tailIsInside($html), $html);
                    $this->assertStringContainsString("<p>more\ntail</p>", $html);
                }
            }
        }
    }

    public function testItemProseReopensAtItsColumnAndOneShortOfAFootnoteBody(): void
    {
        foreach ([['- a', 2], ['1. a', 3], ['. a', 2]] as [$head, $contentColumn]) {
            foreach (['[^f]: t', '[r]: /u'] as $definition) {
                $html = $this->converter->convert(
                    $this->source($head, $contentColumn, $definition, $contentColumn),
                );
                $this->assertTrue($this->tailIsInside($html), $html);
            }
            $html = $this->converter->convert(
                $this->source($head, $contentColumn, '[^f]: t', $contentColumn + 1),
            );
            $this->assertTrue($this->tailIsInside($html), $html);
        }
    }

    public function testTheFootnoteBodyColumnStaysInTheDefinitionBlock(): void
    {
        foreach ([['- a', 2], ['1. a', 3], ['. a', 2]] as [$head, $contentColumn]) {
            $source = $this->source($head, $contentColumn, '[^f]: t', $contentColumn + 2);
            $html = $this->converter->convert($source . "\nx[^f]\n");
            $this->assertFalse($this->tailIsInside($html), $html);
            $this->assertStringContainsString("t\nmore", $html);
        }
    }

    public function testAbbreviationShapeIsItemProseNotADefinition(): void
    {
        foreach ([['- a', 2], ['1. a', 3], ['. a', 2]] as [$head, $contentColumn]) {
            $html = $this->converter->convert(
                $this->source($head, $contentColumn, '*[A]: expansion', 1),
            );
            $this->assertTrue($this->tailIsInside($html), $html);
        }
    }
}

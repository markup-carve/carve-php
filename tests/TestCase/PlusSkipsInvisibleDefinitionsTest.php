<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class PlusSkipsInvisibleDefinitionsTest extends TestCase
{
    public function testAnAbbreviationBetweenTheMarkerAndTextIsCollected(): void
    {
        $source = "- +\n*[A]: b\ntext\n\nA\n";
        $expected = <<<'HTML'
<ul>
  <li>text</li>
</ul>
<p><abbr title="b">A</abbr></p>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testARefusedMarkerKeepsTheSiblingListTogetherAcrossDefinitions(): void
    {
        foreach (['[r]: /u', '*[A]: b'] as $definition) {
            $source = "- +\n$definition\n- b\n";
            $expected = <<<'HTML'
<ul>
  <li>+</li>
  <li>b</li>
</ul>
HTML;

            self::assertSame($expected, trim((new CarveConverter())->convert($source)), $definition);
        }
    }

    public function testAnAttributeBeforeTheSiblingStillStartsANewList(): void
    {
        $source = "- +\n{.x}\n- b\n";
        $expected = <<<'HTML'
<ul>
  <li>+</li>
</ul>
<ul class="x">
  <li>b</li>
</ul>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testFormattingKeepsTheSkippedDefinitionAndItsMeaning(): void
    {
        $source = "- +\n*[A]: b\ntext\n\nA\n";
        $formatted = CarveConverter::toCarve($source);

        self::assertStringContainsString('*[A]: b', $formatted);
        self::assertStringNotContainsString('%%', $formatted);
        self::assertSame((new CarveConverter())->convert($source), (new CarveConverter())->convert($formatted));
    }

    public function testAReferenceDefinitionWithoutAPlusKeepsTheListBoundary(): void
    {
        $source = "- a\n\n[r]: /u\n\n- b\n";
        $expected = <<<'HTML'
<ul>
  <li>a</li>
</ul>
<ul>
  <li>b</li>
</ul>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testBlankAfterTheSkippedDefinitionLoosensTheSiblingList(): void
    {
        $source = "- +\n*[A]: b\n\n- c\n";
        $expected = <<<'HTML'
<ul>
  <li><p>+</p></li>
  <li><p>c</p></li>
</ul>
HTML;

        self::assertSame($expected, trim((new CarveConverter())->convert($source)));
    }
}

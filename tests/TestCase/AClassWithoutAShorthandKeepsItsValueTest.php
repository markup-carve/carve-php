<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Div;
use PHPUnit\Framework\TestCase;

class AClassWithoutAShorthandKeepsItsValueTest extends TestCase
{
    public function testAClassOutsideTheIdentifierGrammarUsesAKeyValuePair(): void
    {
        $source = "{class=\"-col\"}\n::: div\nx\n:::\n";
        $written = CarveConverter::carve()->convert($source);

        $this->assertStringContainsString('{class="-col"}', $written);
        $div = (new CarveConverter())->parse($written)->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);
        $this->assertSame(['div', '-col'], $div->getClassList());
    }

    public function testAnAuthoredValueWithSpacesStaysOneEntry(): void
    {
        $written = CarveConverter::carve()->convert("{class=\"a -col\"}\n::: div\nx\n:::\n");

        $this->assertStringContainsString('{class="a -col"}', $written);
        $div = (new CarveConverter())->parse($written)->getChildren()[0];
        $this->assertInstanceOf(Div::class, $div);
        $this->assertSame(['div', 'a -col'], $div->getClassList());
    }

    public function testSpellableClassesKeepTheirShorthand(): void
    {
        $written = CarveConverter::carve()->convert("{class=a class=b}\n::: div\nx\n:::\n");

        $this->assertStringContainsString('{.a .b}', $written);
    }

    public function testStructuralClassesAreFilteredBeforeChoosingTheSpelling(): void
    {
        foreach (['-col' => '{class="-col"}', 'a' => '{.a}'] as $class => $attrs) {
            $written = CarveConverter::carve()->convert('{.note class="' . $class . '"}' . "\n::: note\nx\n:::\n");

            $this->assertStringContainsString($attrs, $written);
        }
    }
}

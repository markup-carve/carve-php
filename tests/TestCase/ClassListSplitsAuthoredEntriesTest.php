<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\HeadingNumbersExtension;
use MarkupCarve\Carve\Node\Inline\InlineExtension;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassListSplitsAuthoredEntriesTest extends TestCase
{
    /**
     * @return array<string, array{string, array<string>, array<string>}>
     */
    public static function spellings(): array
    {
        return [
            'single shorthand' => ['.a', ['a'], ['a']],
            'several shorthands' => ['.a .b', ['a', 'b'], ['a', 'b']],
            'single value' => ['class=a', ['a'], ['a']],
            'several names in one value' => ['class="a b"', ['a b'], ['a', 'b']],
            'shorthand then value' => ['.a class="b c"', ['a', 'b c'], ['a', 'b', 'c']],
            'value then shorthand' => ['class="b c" .a', ['b c', 'a'], ['b', 'c', 'a']],
            'duplicates survive' => ['.a class="a b"', ['a', 'a b'], ['a', 'a', 'b']],
            'extra inner whitespace' => ['class="a   b"', ['a   b'], ['a', 'b']],
            'leading and trailing whitespace' => ['class="  a b  "', ['  a b  '], ['a', 'b']],
            'tab' => ["class=\"a\tb\"", ["a\tb"], ['a', 'b']],
            'empty value' => ['class=""', [''], []],
            'bare class key' => ['class', [''], []],
            'empty value beside shorthand' => ['class="" .b', ['', 'b'], ['b']],
            'whitespace-only value' => ['class="   " .b', ['   ', 'b'], ['b']],
        ];
    }

    /**
     * @param string $attributes
     * @param array<string> $entries
     * @param array<string> $names
     */
    #[DataProvider('spellings')]
    public function testInlineExtensionClassListHoldsNames(string $attributes, array $entries, array $names): void
    {
        $node = $this->inlineExtension(':x[y]{' . $attributes . '}');

        self::assertSame($names, $node->getClassList());
        self::assertSame($entries, $node->getClassEntries());
    }

    /**
     * @param string $attributes
     * @param array<string> $entries
     * @param array<string> $names
     */
    #[DataProvider('spellings')]
    public function testBlockClassListHoldsNames(string $attributes, array $entries, array $names): void
    {
        $node = (new CarveConverter())->parse('{' . $attributes . "}\nx\n")->getChildren()[0];

        self::assertSame($names, $node->getClassList());
        self::assertSame($entries, $node->getClassEntries());
    }

    public function testMergedAttributeIsUnchanged(): void
    {
        $node = $this->inlineExtension(':x[y]{.a class="b c"}');

        self::assertSame('a b c', $node->getAttribute('class'));
        self::assertTrue(in_array('b', $node->getClassList(), true));
    }

    public function testHasClassMatchesNamesAndHasClassEntryMatchesEntries(): void
    {
        $node = (new CarveConverter())->parse("{class=\"b c\"}\nx\n")->getChildren()[0];

        self::assertTrue($node->hasClass('b'));
        self::assertTrue($node->hasClass('c'));
        self::assertFalse($node->hasClass('b c'));
        self::assertFalse($node->hasClassEntry('b'));
        self::assertTrue($node->hasClassEntry('b c'));
    }

    public function testHeadingNumbersStillMatchWholeEntries(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new HeadingNumbersExtension());
        $html = $converter->convert("{class=\"x unnumbered\"}\n# A\n\n{.unnumbered}\n# B\n");

        self::assertStringContainsString('<h1 class="x unnumbered"><span class="section-number">1</span> A</h1>', $html);
        self::assertStringContainsString('<h1 class="unnumbered">B</h1>', $html);
    }

    private function inlineExtension(string $source): Node
    {
        $found = null;
        $converter = new CarveConverter();
        $converter->getRenderer()->on('render.inline_extension', function ($event) use (&$found): void {
            $node = $event->getNode();
            if ($node instanceof InlineExtension) {
                $found = $node;
            }
        });
        $converter->convert($source);
        self::assertInstanceOf(InlineExtension::class, $found);

        return $found;
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mention or tag opens only after a non-word character and its name runs to
 * the last name character, so one glued to a word has no spelling
 * (markup-carve/carve-js#1807).
 */
class AMentionGluedToAWordIsUnspellableTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    protected const MENTION = ['type' => 'mention', 'user' => 'name'];

    /**
     * @var array<string, string>
     */
    protected const TAG = ['type' => 'tag', 'name' => 'tag'];

    /**
     * @return array<string, array{array<int, array<string, mixed>>, string}>
     */
    public static function refused(): array
    {
        return [
            'a letter before a mention' => [[['type' => 'text', 'value' => 'xa'], self::MENTION], 'mention'],
            'an underscore before a tag' => [[['type' => 'text', 'value' => 'x_'], self::TAG], 'tag'],
            'a digit before a mention' => [[['type' => 'text', 'value' => 'x1'], self::MENTION], 'mention'],
            'a name character after a mention' => [[self::MENTION, ['type' => 'text', 'value' => 'ax']], 'mention'],
            'a name character after a tag' => [[self::TAG, ['type' => 'text', 'value' => 'ax']], 'tag'],
            'a hyphen after a tag' => [[self::TAG, ['type' => 'text', 'value' => '-x']], 'tag'],
            'a dot and a name character after a mention' => [[self::MENTION, ['type' => 'text', 'value' => '.x']], 'mention'],
            'an attribute-looking word before a mention' => [[['type' => 'text', 'value' => 'x {.k'], self::MENTION], 'mention'],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @param string $type
     */
    #[DataProvider('refused')]
    public function testTheWriterRefusesTheTree(array $children, string $type): void
    {
        try {
            (new CarveRenderer())->render($this->paragraph($children));
            $this->fail('No SourceUnspellableException was thrown');
        } catch (SourceUnspellableException $exception) {
            $this->assertSame($type, $exception->nodeType);
        }
    }

    /**
     * @return array<string, array{array<int, array<string, mixed>>, string}>
     */
    public static function written(): array
    {
        return [
            'a space before a mention' => [[['type' => 'text', 'value' => 'x '], self::MENTION], "x @name\n"],
            'a dot at the end after a tag' => [[self::TAG, ['type' => 'text', 'value' => '.']], "#tag.\n"],
            'a space after a mention' => [[self::MENTION, ['type' => 'text', 'value' => ' x']], "@name x\n"],
            'text spelling a sigil after a word' => [[['type' => 'text', 'value' => 'a'], ['type' => 'text', 'value' => '@c']], "a@c\n"],
            'a strong closer before a tag' => [
                [['type' => 'strong', 'children' => [['type' => 'text', 'value' => 'b']]], ['type' => 'text', 'value' => ' '], self::TAG],
                "*b* #tag\n",
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $children
     * @param string $carve
     */
    #[DataProvider('written')]
    public function testTheWriterWritesASpellableNeighbor(array $children, string $carve): void
    {
        $this->assertSame($carve, (new CarveRenderer())->render($this->paragraph($children)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function parsed(): array
    {
        return [
            'spaces' => ['a @name b'],
            'a dot before' => ['x.@name'],
            'parentheses' => ['(@name)'],
            'a trailing dot' => ['#tag.'],
            'a dotted name' => ['@a.b-c_d x'],
            'inside strong' => ['*@name*'],
            'after a braced strong' => ['{*x*}@name'],
        ];
    }

    #[DataProvider('parsed')]
    public function testAParsedTreeIsNeverRefused(string $source): void
    {
        $written = CarveConverter::toCarve($source);
        $tree = (new AstCodec())->encode(CarveConverter::create()->parse($written));

        $this->assertMatchesRegularExpression('/"type":"(mention|tag)"/', (string)json_encode($tree));
    }

    /**
     * @param array<int, array<string, mixed>> $children
     */
    protected function paragraph(array $children): Document
    {
        return (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => $children]],
        ]);
    }
}

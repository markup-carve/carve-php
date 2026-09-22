<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\BbcodeToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A bbcode formatting tag is spelled the way the Carve writer would spell the
 * tree it describes. The same cases, byte for byte, are pinned in carve-js.
 */
class BbcodeFormattingSpellsLikeTheWriterTest extends TestCase
{
    private BbcodeToCarve $bbcode;

    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->bbcode = new BbcodeToCarve();
        $this->converter = new CarveConverter();
    }

    /**
     * @return array<string, array{string}>
     */
    public static function emptyTagProvider(): array
    {
        return ['b' => ['b'], 'i' => ['i'], 'u' => ['u'], 's' => ['s']];
    }

    /**
     * Ruling markup-carve/carve-rs#1719: an empty element holds nothing a reader sees.
     *
     * @param string $tag
     */
    #[DataProvider('emptyTagProvider')]
    public function testAnEmptyTagIsDropped(string $tag): void
    {
        $this->assertSame("a  b\n", $this->bbcode->convert("a [{$tag}][/{$tag}] b"));
    }

    public function testATagLeftEmptyByAnEmptyInnerTagIsDropped(): void
    {
        $this->assertSame("a  b\n", $this->bbcode->convert('a [b][i][/i][/b] b'));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function meaningProvider(): array
    {
        return [
            'leading space' => ['a [b] x[/b] b', '<p>a <strong> x</strong> b</p>'],
            'trailing space' => ['a [b]x [/b] b', '<p>a <strong>x </strong> b</p>'],
            'leading tab' => ["a [u]\tx[/u] b", "<p>a <u>\tx</u> b</p>"],
            'intraword' => ['a[b]x[/b]b', '<p>a<strong>x</strong>b</p>'],
            'italic around bold' => ['a [i][b]x[/b][/i] b', '<p>a <em><strong>x</strong></em> b</p>'],
            'bold around italic' => ['a [b][i]x[/i][/b] b', '<p>a <strong><em>x</em></strong> b</p>'],
            'after its own delimiter' => ['a*[b]x[/b] b', '<p>a*<strong>x</strong> b</p>'],
            'underline after a slash' => ['a/[u]x[/u] b', '<p>a/<u>x</u> b</p>'],
            'adjacent' => ['a [b]x[/b][b]y[/b] b', '<p>a <strong>x</strong><strong>y</strong> b</p>'],
            'nested in its own kind' => ['a [b]x[b]y[/b]z[/b] b', '<p>a <strong>xyz</strong> b</p>'],
            'before an emptied tag' => ['a [b]x[/b][i][/i]y b', '<p>a <strong>x</strong>y b</p>'],
            'unclosed' => ['a [b]x[i]y b', '<p>a [b]x[i]y b</p>'],
            'upper case' => ['a [B]x[/B] b', '<p>a <strong>x</strong> b</p>'],
            'closed inside an unclosed one' => ['a [b]a[b]x[/b] b', '<p>a [b]a<strong>x</strong> b</p>'],
            'inside braces' => ['a{[i]x[/i]} b', '<p>a{<em>x</em>} b</p>'],
            'after a literal delimiter' => ['*[b]_[/b]', '<p>*<strong>_</strong></p>'],
            'after a backslash and a delimiter' => ['a \\*[b]x[/b] b', '<p>a \\*<strong>x</strong> b</p>'],
        ];
    }

    /**
     * @param string $bbcode
     * @param string $html
     */
    #[DataProvider('meaningProvider')]
    public function testTheTagKeepsItsMeaning(string $bbcode, string $html): void
    {
        $this->assertSame($html, trim($this->converter->convert($this->bbcode->convert($bbcode))));
    }

    public function testAFormatPassLeavesTheImportAlone(): void
    {
        foreach (['a [b]x[/b]_y b', 'a*[b]x[/b] b', 'a [b] x[/b] b', 'a [i][u]x[/u][/i] b', 'a{[i]x[/i]} b'] as $bbcode) {
            $once = $this->bbcode->convert($bbcode);
            $this->assertSame($once, CarveConverter::toCarve($once), $bbcode);
        }
    }

    public function testNestingAsDeepAsTheInputLimitAllows(): void
    {
        $depth = 10000;
        $tags = ['b', 'i', 'u', 's'];
        $open = '';
        $close = '';
        for ($k = 0; $k < $depth; $k++) {
            $open .= '[' . $tags[$k % 4] . ']';
            $close .= '[/' . $tags[($depth - 1 - $k) % 4] . ']';
        }

        $this->assertSame(
            $this->bbcode->convert('[b][i][u][s]x[/s][/u][/i][/b]'),
            $this->bbcode->convert($open . 'x' . $close),
        );
    }
}

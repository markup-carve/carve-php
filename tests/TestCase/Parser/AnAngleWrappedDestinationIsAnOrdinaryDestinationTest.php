<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `[link](<foo>)` is a link whose href is `<foo>`.
 *
 * PART 3 builds `link_destination` from `destination_char`, any character but
 * `(`, `)` and Unicode whitespace, so an angle bracket is ordinary there. The
 * note beside the production, "there is NO angle-bracket-wrapped destination
 * form", says the brackets are not STRIPPED - the href keeps them - and not
 * that the run is refused. This reader refused it, and only in the one shape
 * where the brackets wrapped the WHOLE destination (carve-php#2641).
 *
 * The refusal that reaches `[t](<u v>)` is the whitespace test, which stands.
 */
class AnAngleWrappedDestinationIsAnOrdinaryDestinationTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function destinations(): array
    {
        return [
            'the whole destination wrapped' => ['[link](<foo>)', '<p><a href="&lt;foo&gt;">link</a></p>'],
            'an image' => ['![a](<foo>)', '<img src="&lt;foo&gt;" alt="a">'],
            'a title after it' => [
                '[t](<u> "ti")',
                '<p><a href="&lt;u&gt;" title="ti">t</a></p>',
            ],
            'a scheme inside the brackets' => [
                '[link](<http://x>)',
                '<p><a href="&lt;http://x&gt;">link</a></p>',
            ],
            'an empty bracket pair' => ['[z](<>)', '<p><a href="&lt;&gt;">z</a></p>'],
            'nested brackets' => ['[t](<a<b>>)', '<p><a href="&lt;a&lt;b&gt;&gt;">t</a></p>'],
            'doubled brackets' => ['[link](<<foo>>)', '<p><a href="&lt;&lt;foo&gt;&gt;">link</a></p>'],
            'balanced parentheses inside the brackets' => [
                '[k](<a(b)>)',
                '<p><a href="&lt;a(b)&gt;">k</a></p>',
            ],
            // A backslash before anything but `(`, `)` or `\` is an ordinary
            // destination character, so `\>` keeps both bytes.
            'a backslash before the closing bracket' => [
                '[k](<a\\>b>)',
                '<p><a href="&lt;a\\&gt;b&gt;">k</a></p>',
            ],
            // Shapes the refusal never reached, kept as controls.
            'an opening bracket only' => ['[link](<foo)', '<p><a href="&lt;foo">link</a></p>'],
            'a closing bracket only' => ['[link](foo>)', '<p><a href="foo&gt;">link</a></p>'],
            'brackets inside the run' => ['[link](a<b>c)', '<p><a href="a&lt;b&gt;c">link</a></p>'],
            // The whitespace test, which is what refuses this one.
            'whitespace inside the brackets' => ['[k](<u v>)', '<p>[k](&lt;u v&gt;)</p>'],
            'an unbalanced parenthesis ends the destination early' => [
                '[x](<a)b>) c',
                '<p><a href="&lt;a">x</a>b&gt;) c</p>',
            ],
            // An autolink is a different construct and keeps its own reading.
            'a bare autolink is no destination' => [
                '<http://foo.bar.baz>',
                '<p><a href="http://foo.bar.baz">http://foo.bar.baz</a></p>',
            ],
        ];
    }

    #[DataProvider('destinations')]
    public function testTheDestinationIsRead(string $source, string $html): void
    {
        $this->assertSame($html, trim(CarveConverter::create()->convert($source . "\n")));
    }

    /**
     * The canonical writer spells the href back as it read it, so the source is
     * a fixed point rather than something that drifts on a format pass.
     *
     * @return array<string, array{string}>
     */
    public static function roundTrips(): array
    {
        return [
            'a link' => ["[link](<foo>)\n"],
            'an image' => ["![a](<foo>)\n"],
            'a title after it' => ["[t](<u> \"ti\")\n"],
        ];
    }

    #[DataProvider('roundTrips')]
    public function testTheSourceRoundTrips(string $source): void
    {
        $this->assertSame($source, CarveConverter::toCarve($source));
    }

    /**
     * A reference definition already read the angle brackets as ordinary
     * characters, so the two destination readers now agree.
     */
    public function testAReferenceDefinitionReadsItTheSameWay(): void
    {
        $this->assertSame(
            '<p><a href="&lt;foo&gt;">r</a></p>',
            trim(CarveConverter::create()->convert("[r][]\n\n[r]: <foo>\n")),
        );
    }
}

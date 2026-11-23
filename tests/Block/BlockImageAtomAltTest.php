<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Block;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-B-197: the alt of a block image line closes where the inline bracket scan closes it, behind atoms.
 */
final class BlockImageAtomAltTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function lines(): array
    {
        return [
            'cross-reference' => ["![a </#x]> b](u)\n", '<img src="u" alt="a &lt;/#x]&gt; b">'],
            'code span' => ["![a `]` b](u)\n", '<img src="u" alt="a `]` b">'],
            'long code span with caption' => [
                "![a ````]```` b](i)\n^ cap\n",
                "<figure>\n  <img src=\"i\" alt=\"a ````]```` b\">\n  <figcaption>cap</figcaption>\n</figure>",
            ],
            'autolink' => ["![<https://e.x/]>](u)\n", '<img src="u" alt="&lt;https://e.x/]&gt;">'],
            'unclosed code run is no atom' => ["![a `] b](u)\n", '<p>![a `] b](u)</p>'],
            'space ends the destination' => ["![a](u v)\n", '<p>![a](u v)</p>'],
        ];
    }

    #[DataProvider('lines')]
    public function testLine(string $source, string $html): void
    {
        $this->assertSame($html, Carve::toHtml($source));
    }

    public function testCommentOnlyLinesBelowKeepTheRole(): void
    {
        $source = "![a](u)\n%% c\n";
        $paragraph = json_decode(Carve::toJson($source), true, 512, JSON_THROW_ON_ERROR)['children'][0];

        $this->assertSame('image', $paragraph['role']);
        $this->assertSame('<img src="u" alt="a">', Carve::toHtml($source));
        $this->assertSame($source, Carve::format($source));
    }
}

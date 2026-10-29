<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 8 precedence 5: a `[^` run is a note reference before bracket scanning,
 * and a label may hold `[`, so `[^[g]` starts no bracket run and a delimiter
 * after its `]` still closes an outer span (markup-carve/carve#3917,
 * markup-carve/carve-php#4225).
 */
class ANoteReferenceEndsAtItsLabelCloserTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provider(): array
    {
        return [
            'emphasis' => ["/[^[g]/] b/\n", '<p><em>[^[g]</em>] b/</p>'],
            'strong' => ["*[^[g]*] b*\n", '<p><strong>[^[g]</strong>] b*</p>'],
            'underline' => ["_[^[g]_] b_\n", '<p><u>[^[g]</u>] b_</p>'],
            'strikethrough' => ["~[^[g]~] b~\n", '<p><s>[^[g]</s>] b~</p>'],
            'highlight' => ["=[^[g]=] b=\n", '<p><mark>[^[g]</mark>] b=</p>'],
            'a label with an inner bracket' => ["/[^a[b]/] b/\n", '<p><em>[^a[b]</em>] b/</p>'],
            'a label of delimiters' => ["/[^/[/g/]/] b/\n", '<p><em>[^/[/g/]</em>] b/</p>'],
            'a braced closer after the reference' => ["{*[^[g]*}] b*}\n", '<p><strong>[^[g]</strong>] b*}</p>'],
            'a braced parent past a stray bracket' => ["{=[^[[g]] b=}\n", '<p><mark>[^[[g]] b</mark></p>'],
            'a braced parent around the pair' => ["{=/[^[[g]/] b/=}\n", '<p><mark><em>[^[[g]</em>] b/</mark></p>'],
        ];
    }

    #[DataProvider('provider')]
    public function testTheDelimiterAfterTheReferenceCloses(string $source, string $expected): void
    {
        $this->assertSame($expected, CarveConverter::create()->convert($source));
    }

    public function testAResolvedReferenceClosesTheSameWay(): void
    {
        $html = CarveConverter::create()->convert("/[^[g]/] b/\n\n[^[g]: note\n");

        $this->assertStringStartsWith('<p><em><a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a></em>] b/</p>', $html);
    }
}

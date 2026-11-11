<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#5982: `{#a ID=b #c}` and `{#a #c ID=b}` render different
 * ids, so their trees must encode differently (PART 12 §6). `order` lists the
 * last-written slot again where the deduplicated list would end on another one.
 */
class AnAstRoundTripKeepsTheLastWrittenSpellingTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideSources(): array
    {
        $runs = [
            '#a ID=b #c', '#a ID=b', 'ID=b #a', 'ID=b #a ID=c', '#a Id=b #c',
            '#a #c ID=b', 'ID=b id=c', '#a id=b #c', 'title=a TITLE=b title=c', 'ID=b title=t #a ID=c',
        ];
        $hosts = [
            'link' => "[x](/u){%s}\n",
            'block line' => "{%s}\npara\n",
            'image' => "y ![i](/p.png){%s}\n",
            'block image' => "{%s}\n![i](/p.png)\n",
            'reference definition' => "[x][r]\n\n[r]: /u {%s}\n",
            'admonition' => "{%s}\n::: note\nbody\n:::\n",
        ];
        $cases = [];
        foreach ($hosts as $host => $template) {
            foreach ($runs as $run) {
                $cases["$host {{$run}}"] = [sprintf($template, $run)];
            }
        }

        return $cases;
    }

    #[DataProvider('provideSources')]
    public function testTheRoundTripRendersLikeTheParse(string $source): void
    {
        $codec = new AstCodec();
        $document = (new CarveConverter())->parse($source);
        $json = $codec->encodeJson($document);
        $decoded = $codec->decodeJson($json);

        $this->assertSame((new CarveConverter())->render($document), (new CarveConverter())->render($decoded));
        $this->assertSame($json, $codec->encodeJson($decoded));
    }

    public function testTheRepeatedSlotIsOnTheWire(): void
    {
        $attrs = static fn (string $source): string => (new AstCodec())->encodeJson((new CarveConverter())->parse($source));

        $this->assertStringContainsString('"attrs":{"id":"c","keyValues":{"ID":"b"},"order":["#id","ID","#id"]}', $attrs("[x](/u){#a ID=b #c}\n"));
        $this->assertStringContainsString('"attrs":{"id":"c","keyValues":{"ID":"b"},"order":["#id","ID"]}', $attrs("[x](/u){#a #c ID=b}\n"));
        $this->assertStringContainsString('"attrs":{"id":"c","order":["#id"]}', $attrs("[x](/u){#a id=b #c}\n"));
    }
}

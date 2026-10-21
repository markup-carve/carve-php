<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class PlainTightListFastPathTest extends TestCase
{
    public function testPlainListsKeepTheGeneralParsersAst(): void
    {
        $sources = [
            "- alpha\n- beta\n\n> quote\n",
            "- alpha \n- beta\n",
            "- alpha\n- beta\n\n- gamma\n",
            "- alpha\n- beta\n\n  continuation\n",
            "- alpha\n- beta\n\n+\n# next\n",
            "- ALPHA\n- beta\n\n*[ALPHA]: expansion\n",
            "# heading\n\n- alpha\n- beta\n\nparagraph\n",
        ];
        $codec = new AstCodec();
        foreach ($sources as $source) {
            $optimized = (new CarveConverter())->parse($source);
            $reference = (new CarveConverter(parser: new class extends BlockParser {
            }))->parse($source);
            self::assertSame($codec->encodeJson($reference), $codec->encodeJson($optimized), $source);
        }
    }

    public function testRegisteredInlineMatcherStillSeesPlainListContent(): void
    {
        $source = "- alpha\n- beta\n";
        $codec = new AstCodec();
        $parser = new BlockParser();
        $parser->getInlineParser()->addInlinePattern('/a/', static fn (): Text => new Text('X'));
        $referenceParser = new class extends BlockParser {
        };
        $referenceParser->getInlineParser()->addInlinePattern('/a/', static fn (): Text => new Text('X'));

        $optimized = (new CarveConverter(parser: $parser))->parse($source);
        $reference = (new CarveConverter(parser: $referenceParser))->parse($source);
        self::assertSame($codec->encodeJson($reference), $codec->encodeJson($optimized));
    }
}

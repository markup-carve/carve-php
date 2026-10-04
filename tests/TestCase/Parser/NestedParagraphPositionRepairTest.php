<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\TestCase;

class NestedParagraphPositionRepairTest extends TestCase
{
    public function testExtensionParagraphsKeepTheirSuffixPositions(): void
    {
        $reference = new class (trackPositions: true) extends BlockParser {
        };
        $candidate = new BlockParser(trackPositions: true);
        foreach ([$reference, $candidate] as $parser) {
            $parser->addBlockPattern('/^!!/', static function (array $lines, int $start, Node $parent): int {
                $paragraph = new Paragraph();
                $paragraph->appendChild(new Text(substr($lines[$start], 2)));
                $parent->appendChild($paragraph);

                return 1;
            });
        }
        $codec = new AstCodec();
        foreach (["- !!payload\n", "- - !!payload\n", "- ::: box\n  !!payload\n  :::\n"] as $source) {
            self::assertSame($codec->encodeJson($reference->parse($source)), $codec->encodeJson($candidate->parse($source)));
        }
    }

    public function testLocalRepairMatchesAncestorWalkAndResetsBetweenDocuments(): void
    {
        $reference = new class (trackSourceLines: true, trackPositions: true) extends BlockParser {
        };
        $candidate = new BlockParser(trackSourceLines: true, trackPositions: true);
        $codec = new AstCodec();
        foreach ([2, 48, 192, 205] as $depth) {
            $sources = [
                str_repeat('- ', $depth) . "α payload\n",
                str_repeat('- ', $depth) . "same\n\n  same\n",
                "- lead\n\n  > - nested\n  >   tail\n\n+\n::: box\nparagraph\n:::\n",
                "[^note]:\n  - - nested\n\nref[^note]\n",
                "- ::: box\n  - nested\n  :::\n",
                "- - line\\\n  continued\n\n- sibling\n",
                "- - &amp;\n",
                "- - {.x} text\n",
            ];
            foreach ($sources as $source) {
                foreach (["\n", "\r\n", "\r"] as $ending) {
                    $input = "\u{feff}" . str_replace("\n", $ending, $source);
                    self::assertSame(
                        $codec->encodeJson($reference->parse($input)),
                        $codec->encodeJson($candidate->parse($input)),
                        $input,
                    );
                }
            }
        }
    }
}

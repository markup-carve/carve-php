<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\Test\PinnedOracleHtml;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HostedColonCloserGeometryTest extends TestCase
{
    public static function hostedCases(): iterable
    {
        foreach ([1, 2] as $depth) {
            foreach ([3, 4] as $width) {
                foreach (['- ', '12. '] as $marker) {
                    $quote = str_repeat('> ', $depth);
                    $space = str_repeat(' ', strlen($marker));
                    $run = str_repeat(':', $width);

                    yield "literal-$depth-$width-$marker" => [
                        $quote . $marker . $run . " |\n"
                            . $quote . $space . "```\n"
                            . $quote . $space . $run . "\n"
                            . $quote . $space . $run . "bad\n"
                            . $quote . $space . $run . "\n- tail\n",
                        $depth, 'literal', $run,
                    ];

                    yield "box-$depth-$width-$marker" => [
                        $quote . $marker . $run . " box\n"
                            . $quote . $space . "a\n"
                            . $quote . $space . '  ' . $run . "\n- tail\n",
                        $depth, 'box', $run,
                    ];

                    yield "verse-$depth-$width-$marker" => [
                        $quote . $marker . $run . " |\n"
                            . $quote . $space . "a\n"
                            . $quote . $space . '  ' . $run . "\n"
                            . $quote . $space . "b\n- tail\n",
                        $depth, 'verse', $run,
                    ];
                }
            }
        }
    }

    #[DataProvider('hostedCases')]
    public function testLiteralPayloadAndCloserColumnKeepTheirOwners(string $source, int $depth, string $kind, string $run): void
    {
        $legacy = new class extends BlockParser {
            protected function advanceTrailingBlockState(array $state, string $line, bool $atContentColumn = false): array
            {
                return parent::advanceTrailingBlockState($state, $line, $atContentColumn);
            }
        };
        foreach ([new BlockParser(), $legacy] as $parser) {
            $doc = $parser->parse($source);
            $children = $doc->getChildren();
            self::assertCount($kind === 'verse' ? 2 : 1, $children, $source);
            $host = $children[0];
            for ($i = 0; $i < $depth; $i++) {
                self::assertSame('block_quote', $host->getType(), $source);
                $host = $host->getChildren()[0];
            }
            self::assertSame('list', $host->getType(), $source);
            self::assertCount(1, $host->getChildren(), $source);
            $item = $host->getChildren()[0];
            $blocks = $item->getChildren();
            if ($kind === 'literal') {
                self::assertCount(2, $blocks, $source);
                self::assertSame('line_block', $blocks[0]->getType(), $source);
                self::assertSame('paragraph', $blocks[1]->getType(), $source);
                self::assertStringContainsString('- tail', self::text($blocks[1]), $source);
            } elseif ($kind === 'box') {
                self::assertCount(1, $blocks, $source);
                self::assertSame('div', $blocks[0]->getType(), $source);
                self::assertSame('paragraph', $blocks[0]->getChildren()[0]->getType(), $source);
                self::assertStringContainsString($run, self::text($blocks[0]), $source);
                self::assertStringContainsString('- tail', self::text($blocks[0]), $source);
            } else {
                self::assertCount(1, $blocks, $source);
                self::assertSame('line_block', $blocks[0]->getType(), $source);
                self::assertSame('list', $children[1]->getType(), $source);
                self::assertStringContainsString('&nbsp;&nbsp;' . $run, (new CarveConverter())->render($doc), $source);
            }
        }
    }

    public function testAuthoredAndCanonicalClosersReleaseTheRootTail(): void
    {
        foreach (
            [
                "> - ::: box\n>   a\n>   :::\n- tail\n",
                "> - a\n>     ::: box\n>     b\n>     :::\n- tail\n",
                "> - a\n>     ::: box\n>     b\n>   :::\n- tail\n",
                "> - a\n>     :::\n>     b\n>     :::\n- tail\n",
            ] as $source
        ) {
            $children = (new BlockParser())->parse($source)->getChildren();
            self::assertCount(2, $children, $source);
            self::assertSame('block_quote', $children[0]->getType(), $source);
            self::assertSame('list', $children[1]->getType(), $source);
            self::assertStringContainsString('tail', self::text($children[1]), $source);
        }
    }

    public function testClosedOpaqueCommentSpansKeepDefinitionsInTheLiteralBody(): void
    {
        $converter = new CarveConverter();
        $hidden = "::: |\n%%%\n:::\n[r]: /target\n%%%\n\n[t][r]";
        $html = $converter->convert($hidden);
        self::assertStringNotContainsString('href="/target"', $html);
        self::assertStringContainsString('[t][r]', $html);

        $external = "::: |\n%%%\n:::\n  %%%\nkept\n:::\n\n[r]: /url\n\n[t][r]";
        $html = $converter->convert($external);
        self::assertStringContainsString('kept', $html);
        self::assertStringContainsString('href="/url"', $html);
    }

    public function testBlankLineEndsUnfinishedLiteralParagraphClaim(): void
    {
        foreach (['- ', '12. '] as $marker) {
            $source = $marker . "::: |\n" . str_repeat(' ', strlen($marker)) . "a\n\nb\n";
            $children = (new BlockParser())->parse($source)->getChildren();
            self::assertCount(2, $children);
            self::assertSame('list', $children[0]->getType());
            self::assertSame('paragraph', $children[1]->getType());
            self::assertSame('b', self::text($children[1]));
        }
    }

    public function testOverindentedDefinitionsKeepTheirEstablishedHoist(): void
    {
        $converter = new CarveConverter();
        $source = "- ::: box\n    [r]: /u\n  :::\n\n[t][r]\n";
        $html = $converter->convert($source);
        self::assertSame(trim(PinnedOracleHtml::forSource($source)), trim($html));

        $html = $converter->convert("- ::: box\n    [^n]: note\n  :::\n\nref[^n]\n");
        self::assertStringContainsString('role="doc-noteref"', $html);
        self::assertStringContainsString('note', $html);
        self::assertStringNotContainsString('[^n]: note', $html);
    }

    private static function text(Node $node): string
    {
        $parts = method_exists($node, 'getContent') ? [$node->getContent()] : [];
        foreach ($node->getChildren() as $child) {
            $parts[] = self::text($child);
        }

        return implode("\n", $parts);
    }
}

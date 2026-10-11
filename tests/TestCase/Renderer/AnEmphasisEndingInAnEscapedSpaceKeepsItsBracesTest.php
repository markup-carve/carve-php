<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AnEmphasisEndingInAnEscapedSpaceKeepsItsBracesTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sources(): array
    {
        $cases = [];
        foreach (['*', '/', '_', '~', '='] as $delimiter) {
            $sources = [
                'only a space' => '{' . $delimiter . '\\ ' . $delimiter . "}\n",
                'after text' => '{' . $delimiter . 'x\\ ' . $delimiter . "}\n",
                'between words' => 'a {' . $delimiter . 'b\\ ' . $delimiter . "} c\n",
                'with an authored sentinel' => "\u{E005} {" . $delimiter . 'x\\ ' . $delimiter . "}\n",
            ];
            foreach ($sources as $name => $source) {
                $cases[$delimiter . ' ' . $name] = [$source];
            }
        }

        return $cases;
    }

    #[DataProvider('sources')]
    public function testFormattingPreservesTheSpan(string $source): void
    {
        $written = CarveConverter::toCarve($source);
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $originalTree = $codec->encode($converter->parse($source));
        $writtenTree = $codec->encode($converter->parse($written));
        unset($originalTree['srcByteLength'], $writtenTree['srcByteLength']);

        self::assertSame($source, $written);
        self::assertSame($originalTree, $writtenTree);
        self::assertSame($converter->convert($source), $converter->convert($written));
        self::assertSame($written, CarveConverter::toCarve($written));
    }

    public function testTextAfterTheEscapeKeepsTheBareSpelling(): void
    {
        foreach (['*', '/', '_', '~', '='] as $delimiter) {
            foreach (['\\ x', '\\!', "x\u{E005}"] as $content) {
                self::assertSame(
                    $delimiter . $content . $delimiter . "\n",
                    CarveConverter::toCarve('{' . $delimiter . $content . $delimiter . "}\n"),
                );
            }
        }
    }

    public function testEditorialSpansKeepTheirBraces(): void
    {
        foreach (['+', '-'] as $delimiter) {
            $source = '{' . $delimiter . '\\ ' . $delimiter . "}\n";
            self::assertSame($source, CarveConverter::toCarve($source));
        }
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Parser\BlockParser;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Transform\IncludeContext;
use MarkupCarve\Carve\Transform\IncludeExpander;
use MarkupCarve\Carve\Transform\IncludeResolverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeparatorWidthsFidelityAndBudgetTest extends TestCase
{
    #[DataProvider('nativeFirstRows')]
    public function testWidthsCannotPromoteNonHeaderCells(string $source): void
    {
        $document = (new BlockParser())->parse($source);
        $document->getChildren()[0]->setSeparatorWidths([3, 3]);
        $writer = (new CarveRenderer())->setPreserveTableSeparators(true);
        $writer->beginConversionDiagnosticCollection();
        $written = $writer->render($document);
        $report = $writer->finishConversionDiagnosticCollection();

        self::assertSame($source, $written);
        self::assertSame('field-unspellable', $report['diagnostics'][0]['code']);
        self::assertSame('table', $report['diagnostics'][0]['node']);
        self::assertSame('separatorWidths', $report['diagnostics'][0]['field']);
        self::assertSame(
            (new HtmlRenderer())->render($document),
            (new HtmlRenderer())->render((new BlockParser())->parse($written)),
        );
    }

    /**
     * @return iterable<array{string}>
     */
    public static function nativeFirstRows(): iterable
    {
        yield ["| a | b |\n| x | y |\n"];
        yield ["|= a | b |\n| x | y |\n"];
    }

    public function testAnUncoveredHeaderRetainsItsNativeAlignment(): void
    {
        $source = "| a |> b |\n|---|\n| x | y |\n";
        $parser = new BlockParser();
        $document = $parser->parse($source);
        $written = (new CarveRenderer())->setPreserveTableSeparators(true)->render($document);
        $codec = new AstCodec();

        self::assertSame($source, $written);
        self::assertSame('right', $codec->encode($document)['children'][0]['rows'][0]['cells'][1]['align']);
        self::assertSame([3], $codec->encode($parser->parse($written))['children'][0]['separatorWidths']);
        self::assertSame((new HtmlRenderer())->render($document), (new HtmlRenderer())->render($parser->parse($written)));
    }

    #[DataProvider('bridgeSources')]
    public function testTheProseMirrorBridgePreservesWidthPresenceAndValues(string $source): void
    {
        $document = (new BlockParser())->parse($source);
        $bridge = (new ProseMirrorRenderer())->render($document);
        $restored = (new ProseMirrorToCarve())->convert($bridge);
        $codec = new AstCodec();
        $before = $codec->encode($document)['children'][0];
        $after = $codec->encode($restored)['children'][0];

        self::assertSame($before['separatorWidths'] ?? null, $after['separatorWidths'] ?? null);
        self::assertSame($before['separatorWidths'] ?? null, $bridge['content'][0]['attrs']['carveSeparatorWidths'] ?? null);
        self::assertSame((new CarveRenderer())->render($document), (new CarveRenderer())->render($restored));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function bridgeSources(): iterable
    {
        yield ["| a | b |\n|:-----|---:|\n| x | y |\n"];
        yield ["|= a |= b |\n| x | y |\n"];
        yield ["| a |\n|" . str_repeat('-', 1500) . "|\n| x |\n"];
    }

    #[DataProvider('alignedHeaders')]
    public function testSeparatorColonsAndVerticalMarkersPreserveBothAlignments(string $horizontal, string $vertical): void
    {
        $separator = match ($horizontal) {
            'left' => ':---',
            'center' => ':---:',
            'right' => '---:',
        };
        $parser = new BlockParser();
        $marker = match ($vertical) {
            'top' => '^',
            'middle' => '~',
            'bottom' => 'v',
        };
        $document = $parser->parse('|?' . $marker . " a |\n|" . $separator . "|\n| x |\n");
        $written = (new CarveRenderer())->setPreserveTableSeparators(true)->render($document);
        $reparsed = $parser->parse($written);

        self::assertSame((new HtmlRenderer())->render($document), (new HtmlRenderer())->render($reparsed));
        $header = (new AstCodec())->encode($reparsed)['children'][0]['rows'][0]['cells'][0];
        self::assertSame($horizontal, $header['align']);
        self::assertSame($vertical, $header['valign']);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function alignedHeaders(): iterable
    {
        foreach (['left', 'center', 'right'] as $horizontal) {
            foreach (['top', 'middle', 'bottom'] as $vertical) {
                yield [$horizontal, $vertical];
            }
        }
    }

    public function testCompactAstWidthsUseTheMeasuredPayloadBudget(): void
    {
        $codec = new AstCodec();
        $ast = $codec->encode((new BlockParser())->parse("|= a |\n"));
        $ast['srcByteLength'] = PHP_INT_MAX;
        $ast['children'][0]['separatorWidths'] = [200000000];
        $document = $codec->decodeJson((string)json_encode($ast, JSON_THROW_ON_ERROR));
        self::assertSame([200000000], $codec->encode($document)['children'][0]['separatorWidths']);

        $this->expectException(SourceUnspellableException::class);
        $this->expectExceptionMessage('document expansion budget');
        (new CarveRenderer())->setPreserveTableSeparators(true)->render($document);
    }

    public function testCompactProseMirrorWidthsCannotAllocateAnUnpaidSeparator(): void
    {
        $bridge = (new ProseMirrorRenderer())->render((new BlockParser())->parse("|= a |\n"));
        $bridge['content'][0]['attrs']['carveSeparatorWidths'] = [200000000];
        $document = (new ProseMirrorToCarve())->convert($bridge);

        $this->expectException(SourceUnspellableException::class);
        $this->expectExceptionMessage('document expansion budget');
        (new CarveRenderer())->setPreserveTableSeparators(true)->render($document);
    }

    #[DataProvider('includedSources')]
    public function testIncludedSourceUsesAdmittedBytesWithoutChangingItsTree(string $source): void
    {
        $resolver = new class ($source) implements IncludeResolverInterface {
            public function __construct(private string $source)
            {
            }

            public function resolve(string $path, IncludeContext $context): string
            {
                return $this->source;
            }
        };
        $root = (new BlockParser())->parse("{{ c.crv }}\n");
        $expanded = (new IncludeExpander($resolver))->transform($root);
        self::assertSame($root->getSourceLength(), $expanded->getSourceLength());
        self::assertSame($source, (new CarveRenderer())->setPreserveTableSeparators(true)->render($expanded));
        self::assertSame((new HtmlRenderer())->render($expanded), (new HtmlRenderer())->render((new BlockParser())->parse($source)));
        self::assertArrayNotHasKey('admittedIncludeLength', (new AstCodec())->encode($expanded));
    }

    /**
     * @return iterable<array{string}>
     */
    public static function includedSources(): iterable
    {
        yield ["| a |\n|" . str_repeat('-', 1030000) . "|\n| x |\n"];
        yield ["end-\".\n"];
    }

    public function testEncodingCannotPublishInvalidHandSetWidths(): void
    {
        $codec = new AstCodec();
        foreach ([[], [0], [-1], [1.5], ['3']] as $widths) {
            $document = (new BlockParser())->parse("|= a |\n");
            $document->getChildren()[0]->setSeparatorWidths($widths);
            $encoded = $codec->encode($document);
            self::assertArrayNotHasKey('separatorWidths', $encoded['children'][0]);
            self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
        }
    }

    public function testAnAuthoredLongSeparatorCannotEraseItsDocument(): void
    {
        $source = "Before\n\n| a |\n|" . str_repeat('-', 1000000) . "|\n| x |\n\nAfter\n";
        $document = (new BlockParser())->parse($source);

        self::assertSame($source, (new CarveRenderer())->setPreserveTableSeparators(true)->render($document));
    }
}

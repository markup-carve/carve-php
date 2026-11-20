<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Export;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\CarveExportError;
use MarkupCarve\Carve\Export\ExportHost;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Extension\Bundle;
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use PHPUnit\Framework\TestCase;

final class ExtensionExportTest extends TestCase
{
    private static function inline(): ExtensionDefinition
    {
        return new ExtensionDefinition(
            'media.embed',
            '1',
            matcher: ['form' => 'inline', 'name' => 'embed'],
            transform: static function (Claim $claim): Node {
                $node = new Node('inline_extension', []);
                $node->f = [
                    'name' => 'media.embed',
                    'version' => '1',
                    'props' => [],
                    'slots' => [],
                    'fallback' => $claim->node->children ?? [],
                ];

                return $node;
            },
        );
    }

    private static function block(): ExtensionDefinition
    {
        return new ExtensionDefinition(
            'media.card',
            '1',
            matcher: ['form' => 'div', 'name' => 'card'],
            transform: static function (Claim $claim): Node {
                $node = new Node('block_extension', []);
                $node->f = [
                    'name' => 'media.card',
                    'version' => '1',
                    'props' => [],
                    'slots' => [],
                    'fallback' => $claim->node->children ?? [],
                ];

                return $node;
            },
        );
    }

    public function testTargetSpecificInlineExportAndCoreFallback(): void
    {
        $url = '//www.youtube.com/embed/dQw4w9WgXcQ?wmode=transparent';
        $definition = self::inline()
            ->withExporter('markdown', static fn (Node $node, ExportHost $host): string => '[YouTube](' . $url . ')')
            ->withExporter('plain', static fn (Node $node, ExportHost $host): string => $url);
        $tree = Carve::parse('Watch :embed[YouTube].', ['extensions' => [$definition]]);

        $this->assertSame("Watch YouTube.\n", (new MarkdownExporter())->export($tree)->output);
        $this->assertSame("Watch [YouTube]($url).\n", (new MarkdownExporter())->export($tree, ['extensions' => [$definition]])->output);
        $this->assertSame("Watch $url.\n", (new PlainTextExporter())->export($tree, ['extensions' => [$definition]])->output);
        $this->assertSame(
            "Watch $url.\n",
            (new PlainTextExporter())->export($tree, ['extensions' => [new Bundle([$definition])]])->output,
        );
        $this->assertSame("Watch YouTube.\n", (new AnsiExporter())->export($tree, ['extensions' => [$definition]])->output);
        $otherVersion = self::inline()->withExporter('markdown', static fn (): string => 'wrong');
        $otherVersion = new ExtensionDefinition($otherVersion->name, '2', exporters: $otherVersion->exporters);
        $this->assertSame("Watch YouTube.\n", (new MarkdownExporter())->export($tree, ['extensions' => [$otherVersion]])->output);
    }

    public function testTheMediaEmbedReproductionHasDifferentTargetOutput(): void
    {
        $url = '//www.youtube.com/embed/dQw4w9WgXcQ?wmode=transparent';
        $definition = new ExtensionDefinition(
            'media.youtube',
            '1',
            matcher: ['form' => 'inline', 'name' => 'youtube'],
            transform: static function (Claim $claim) use ($url): Node {
                $node = new Node('inline_extension', []);
                $node->f = [
                    'name' => 'media.youtube',
                    'version' => '1',
                    'props' => [],
                    'slots' => [],
                    'fallback' => [Node::text($url, -1, -1)],
                ];

                return $node;
            },
        );
        $definition = $definition
            ->withExporter('markdown', static fn (Node $node, ExportHost $host): string => '[YouTube](' . $url . ')')
            ->withExporter('plain', static fn (Node $node, ExportHost $host): string => $url);
        $tree = Carve::parse(':youtube[dQw4w9WgXcQ]', ['extensions' => [$definition]]);

        $this->assertSame($url . "\n", (new MarkdownExporter())->export($tree)->output);
        $this->assertSame("[YouTube]($url)\n", (new MarkdownExporter())->export($tree, ['extensions' => [$definition]])->output);
        $this->assertSame($url . "\n", (new PlainTextExporter())->export($tree, ['extensions' => [$definition]])->output);
    }

    public function testBlockExportMayDelegateToTheCoreFallback(): void
    {
        $definition = self::block()->withExporter('markdown', static function (Node $node, ExportHost $host): string {
            return "Title\n\n" . $host->fallback();
        });
        $tree = Carve::parse("::: card\nDetails.\n:::\n", ['extensions' => [$definition]]);

        $this->assertSame("Details.\n", (new MarkdownExporter())->export($tree)->output);
        $this->assertSame("Title\n\nDetails.\n", (new MarkdownExporter())->export($tree, ['extensions' => [$definition]])->output);
        $this->assertSame("Details.\n", (new PlainTextExporter())->export($tree, ['extensions' => [$definition]])->output);
    }

    public function testStrictLossesOnlyReportsThePathTheExporterWrites(): void
    {
        $definition = self::block()->withExporter('markdown', static fn (): string => "Handled\n\n");
        $tree = Carve::parse("::: card\n```=latex\n\\x\n```\n:::\n", ['extensions' => [$definition]]);
        $this->assertSame("Handled\n", (new MarkdownExporter())->export($tree, [
            'extensions' => [$definition],
            'strictLosses' => true,
        ])->output);

        $this->expectException(CarveExportError::class);
        (new MarkdownExporter())->export($tree, ['strictLosses' => true]);
    }

    public function testExportRegistrationRejectsAnUnknownTarget(): void
    {
        $this->expectException(OptionError::class);
        self::inline()->withExporter('html', static fn (): string => 'x');
    }
}

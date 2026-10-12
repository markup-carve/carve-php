<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Event\RenderEvent;
use MarkupCarve\Carve\Exception\RenderLossException;
use MarkupCarve\Carve\Node\Block\Heading;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Block\Table;
use MarkupCarve\Carve\Node\Block\TableCell;
use MarkupCarve\Carve\Node\Block\TableRow;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class MarkdownUnicodeMarkerExhaustionTest extends TestCase
{
    private static function pool(): string
    {
        $pool = '';
        for ($code = 0xE000; $code <= 0xF8FF; $code++) {
            $pool .= mb_chr($code, 'UTF-8');
        }

        return $pool;
    }

    /**
     * @return array<int, array{string, string}>
     */
    public static function sources(): array
    {
        $rows = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/fixtures/markdown-unicode-marker-exhaustion.json'), true, flags: JSON_THROW_ON_ERROR);
        $pool = self::pool();
        $cases = [];
        foreach ($rows as $row) {
            $cases[] = [str_replace('FULL_PRIVATE_USE_POOL', $pool, $row['source']), str_replace('FULL_PRIVATE_USE_POOL', $pool, $row['markdown'])];
        }

        return $cases;
    }

    #[DataProvider('sources')]
    public function testFullPoolGoldensThroughSourceAndAst(string $source, string $expected): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse($source);
        foreach ([$writer->convertWithReport($source, strictLosses: true), $writer->renderWithReport($document, strictLosses: true)] as $result) {
            self::assertSame($expected, $result->value);
            self::assertStringContainsString(self::pool(), $result->value);
            self::assertSame([], $result->losses);
            self::assertSame(0, $result->totalLosses);
            self::assertFalse($result->truncated);
        }
        self::assertSame("ordinary\n", $writer->convert("ordinary\n"));
    }

    public function testLossCapsAndStrictCountsWithStringMarkers(): void
    {
        $pool = self::pool();
        $source = "```\n{$pool}\n```\n\n`one`{=latex} and `two`{=typst}";
        $writer = CarveConverter::markdown();
        $result = $writer->convertWithReport($source);
        self::assertStringContainsString($pool, $result->value);
        self::assertSame(['latex', 'typst'], array_column($result->losses, 'format'));
        self::assertSame(2, $result->totalLosses);
        $bounded = $writer->convertWithReport($source, maxRenderLosses: 0);
        self::assertSame([], $bounded->losses);
        self::assertSame(2, $bounded->totalLosses);
        self::assertTrue($bounded->truncated);
        try {
            $writer->convertWithReport($source, strictLosses: true, maxRenderLosses: 1);
            self::fail('Strict rendering should refuse the two raw-format losses.');
        } catch (RenderLossException $error) {
            self::assertSame(2, $error->totalLosses);
            self::assertSame(array_slice($result->losses, 0, 1), $error->losses);
            self::assertTrue($error->truncated);
        }
        self::assertSame(0, $writer->convertWithReport("ordinary\n")->totalLosses);
    }

    public function testTrailingHeadingHashesWithStringMarkers(): void
    {
        $anchor = "```\n" . self::pool() . "\n```\n\n";
        $writer = CarveConverter::markdown();
        foreach (
            [
                ["# heading \\#\n", "# heading \\#\n"],
                ["# a \\# \\#\n", "# a # \\#\n"],
                ["# company_id \\#\n", "# company_id \\#\n"],
            ] as [$source, $expected]
        ) {
            foreach ([$writer->convertWithReport($anchor . $source), $writer->renderWithReport($writer->parse($anchor . $source))] as $result) {
                self::assertSame($anchor . $expected, $result->value);
                self::assertSame(0, $result->totalLosses);
            }
        }
    }

    public function testFallbackTokensAssembledAcrossTextNodesRemainLiteral(): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse("ordinary\n");
        $authored = '';
        for ($nonce = 0; $nonce < 32; $nonce++) {
            $authored .= "\u{E009}m{$nonce}x0\u{E00A}";
        }
        $children = [new Text(self::pool())];
        foreach (mb_str_split($authored) as $character) {
            $children[] = new Text($character);
        }
        $children[] = new Text(' a@b.co');
        $paragraph = new Paragraph();
        $paragraph->setChildren($children);
        $document->setChildren([$paragraph]);
        $result = $writer->renderWithReport($document);
        self::assertSame(self::pool() . $authored . " a<!---->@b.co\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }

    public function testEmailOffsetsAfterStringMarkers(): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse("ordinary\n");
        $paragraph = new Paragraph();
        $paragraph->setChildren([new Text(self::pool() . ' _ a@b.co')]);
        $document->setChildren([$paragraph]);
        $result = $writer->renderWithReport($document);
        self::assertSame(self::pool() . " _ a<!---->@b.co\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }

    public function testTightEmphasisKeepsItsFlanksWithStringMarkers(): void
    {
        $writer = CarveConverter::markdown();
        $anchor = "```\n" . self::pool() . "\n```\n\n";
        foreach (['a*_x*', 'a*x!*', 'a*[x*', 'a*#x*', '*x*_b', '*x*_', 'a/*_x*/'] as $source) {
            $expected = $writer->convert($source);
            $result = $writer->convertWithReport($anchor . $source);
            self::assertSame($anchor . $expected, $result->value, $source);
            self::assertSame(0, $result->totalLosses, $source);
        }
    }

    public function testProbeTokensConsumedAsListMarkersAreNotReused(): void
    {
        $writer = CarveConverter::markdown();
        $document = $writer->parse("```\n" . self::pool() . "\n```\n");
        $token = "\u{E009}m0x8\u{E00A}";
        $children = [new Text('1')];
        foreach (mb_str_split($token) as $character) {
            $children[] = new Text($character);
        }
        $children[] = new Text(' x');
        $paragraph = new Paragraph();
        $paragraph->setChildren($children);
        $document->setChildren([$paragraph, ...$document->getChildren()]);
        $result = $writer->renderWithReport($document);
        self::assertStringContainsString('1' . $token . " x\n", $result->value);
        self::assertSame(0, $result->totalLosses);
    }

    public function testOrdinaryRenderKeepsListenerMarkerCharacters(): void
    {
        $renderer = new MarkdownRenderer();
        $calls = 0;
        $replacement = "\u{E009}m0x0\u{E00A} \u{E009}m1x0\u{E00A}";
        $renderer->on('render.text', static function (RenderEvent $event) use (&$calls, $replacement): void {
            $calls++;
            $event->setHtml($replacement);
        });
        $document = (new CarveConverter())->parse("a\n");
        self::assertSame($replacement . "\n", $renderer->render($document));
        self::assertSame(1, $calls);
        $renderer->off();
        self::assertSame("a\n", $renderer->render($document));
    }

    public function testListenerAppendingAParentChildKeepsFirstPassOutput(): void
    {
        $renderer = new MarkdownRenderer();
        $calls = 0;
        $renderer->on('render.paragraph', static function (RenderEvent $event) use (&$calls): void {
            $calls++;
            $document = $event->getNode()->getParent();
            if ($document !== null) {
                $paragraph = new Paragraph();
                $paragraph->appendChild(new Text('note'));
                $document->appendChild($paragraph);
            }
        });
        self::assertSame("a\n", $renderer->render((new CarveConverter())->parse("a\n")));
        self::assertSame(1, $calls);
    }

    public function testListenerCollisionAndParentAppendKeepFirstPassOutput(): void
    {
        $renderer = new MarkdownRenderer();
        $replacement = "\u{E009}m1x0\u{E00A}";
        $calls = 0;
        $renderer->on('render.paragraph', static function (RenderEvent $event) use (&$calls, $replacement): void {
            $calls++;
            $event->setHtml($replacement);
            $document = $event->getNode()->getParent();
            if ($document !== null) {
                $paragraph = new Paragraph();
                $paragraph->appendChild(new Text('note'));
                $document->appendChild($paragraph);
            }
        });
        self::assertSame($replacement . "\n", $renderer->render((new CarveConverter())->parse("a\n")));
        self::assertSame(1, $calls);
    }

    public function testListenerAssemblingMarkerAcrossTextNodesKeepsItsContent(): void
    {
        $renderer = new MarkdownRenderer();
        $renderer->on('render.paragraph', static function (RenderEvent $event): void {
            $event->getNode()->setChildren([new Text("\u{E009}"), new Text('m0x0'), new Text("\u{E00A}")]);
        });
        self::assertSame("\u{E009}m0x0\u{E00A}\n", $renderer->render((new CarveConverter())->parse("a\n")));
    }

    public function testListenerCollisionAndSelfRemovalKeepFirstPassOutput(): void
    {
        $renderer = new MarkdownRenderer();
        $replacement = "\u{E009}m1x0\u{E00A}";
        $renderer->on('render.paragraph', static function (RenderEvent $event) use ($replacement): void {
            $event->setHtml($replacement);
            $node = $event->getNode();
            $node->getParent()?->removeChild($node);
        });
        self::assertSame($replacement . "\n", $renderer->render((new CarveConverter())->parse("a\n")));
    }

    public function testListenerCollisionKeepsAnAlreadyRenderedTextValue(): void
    {
        $renderer = new MarkdownRenderer();
        $document = (new CarveConverter())->parse("first\n\nsecond\n");
        $first = $document->getChildren()[0]->getChildren()[0];
        $replacement = "\u{E009}m1x0\u{E00A}";
        $calls = 0;
        $renderer->on('render.paragraph', static function (RenderEvent $event) use (&$calls, $first, $replacement): void {
            $calls++;
            if ($calls === 2 && $first instanceof Text) {
                $first->setContent('changed');
                $event->setHtml($replacement);
            }
        });
        self::assertSame("first\n\n" . $replacement . "\n", $renderer->render($document));
        self::assertSame(2, $calls);
    }

    public function testTableMutationKeepsTheSameShapeWithCollidingOutput(): void
    {
        $render = static function (string $replacement): string {
            $document = new Document();
            $table = new Table();
            $row = new TableRow(true);
            $cell = new TableCell();
            $first = new Text('head');
            $cell->appendChild($first);
            $row->appendChild($cell);
            $table->appendChild($row);
            $document->appendChild($table);
            $renderer = new MarkdownRenderer();
            $renderer->on('render.text', static function (RenderEvent $event) use ($first, $row, $replacement): void {
                if ($event->getNode() === $first) {
                    $added = new TableCell();
                    $added->appendChild(new Text('added'));
                    $row->appendChild($added);
                    $event->setHtml($replacement);
                }
            });

            return $renderer->render($document);
        };
        $replacement = "\u{E009}m1x0\u{E00A}";
        self::assertSame(str_replace('REPLACEMENT', $replacement, $render('REPLACEMENT')), $render($replacement));
    }

    public function testCollisionKeepsAnAlreadyRenderedHeadingLevel(): void
    {
        $document = (new CarveConverter())->parse("# heading\n\nsecond\n");
        $heading = $document->getChildren()[0];
        $renderer = new MarkdownRenderer();
        $replacement = "\u{E009}m1x0\u{E00A}";
        $renderer->on('render.paragraph', static function (RenderEvent $event) use ($heading, $replacement): void {
            if ($heading instanceof Heading) {
                $heading->setLevel(2);
            }
            $event->setHtml($replacement);
        });
        self::assertSame("# heading\n\n" . $replacement . "\n", $renderer->render($document));
    }

    public function testCallbackLiteralMarkersUseTheirActualLabelByteCost(): void
    {
        $renderer = new class extends MarkdownRenderer {
            protected function resetAbbreviationBudget(int $sourceLength): void
            {
                parent::resetAbbreviationBudget($sourceLength);
                $this->abbreviationBudget = 6;
            }
        };
        $document = new Document();
        $heading = new Heading(1);
        $heading->setAttribute('id', 'target');
        $heading->appendChild(new Text('label'));
        $paragraph = new Paragraph();
        $paragraph->appendChild(new HeadingRef('target'));
        $document->setChildren([$heading, $paragraph]);
        $renderer->on('render.text', static function (RenderEvent $event): void {
            $event->setHtml(str_repeat("\u{E004}", 3));
        });
        self::assertStringContainsString('[target](#', $renderer->render($document));
    }

    public function testLateInsertedInlineLiteralsMatchPreexistingLiterals(): void
    {
        $converter = new CarveConverter();
        $markers = "\u{E004}\u{E009}\u{E00A}\u{E014}\u{E015}\u{E100}\u{E101}";
        foreach (
            [
                'MARKER', '`MARKER`', '[label](https://x.test/MARKER)',
                '[label](https://x.test/ "MARKER")', '![MARKER](https://x.test/image)',
                '![alt](https://x.test/MARKER "MARKER")', '`MARKER`{=html}',
                '</#MARKER>', '\\MARKER', ':MARKER:',
            ] as $source
        ) {
            $literal = 'a ' . str_replace('MARKER', $markers, $source) . ' b';
            $expected = (new MarkdownRenderer())->render($converter->parse($literal));
            $document = $converter->parse("placeholder\n");
            $replacement = $converter->parse($literal)->getChildren()[0];
            self::assertInstanceOf(Paragraph::class, $replacement);
            $renderer = new MarkdownRenderer();
            $renderer->on('render.paragraph', static function (RenderEvent $event) use ($replacement): void {
                $paragraph = $event->getNode();
                foreach ($paragraph->getChildren() as $child) {
                    $paragraph->removeChild($child);
                }
                foreach ($replacement->getChildren() as $child) {
                    $paragraph->appendChild($child);
                }
            });
            self::assertSame($expected, $renderer->render($document), $source);
        }
    }

    public function testNoOpListenersKeepOrdinaryMarkdownOutput(): void
    {
        foreach (["a /word/ here\n", "a *word* here\n", "# Heading\n\ntext\n", "> a\n> b\n", "- a\n- b\n", "```\na\n```\n", "a [label](/url) b\n", "a `code` b\n", "a {_ word _} b\n"] as $source) {
            $document = (new CarveConverter())->parse($source);
            $plain = (new MarkdownRenderer())->render($document);
            $observed = new MarkdownRenderer();
            $observed->on('render.*', static function (): void {
            });
            self::assertSame($plain, $observed->render($document), $source);
        }
    }

    public function testListenerMutatingTextToMarkersKeepsItsContent(): void
    {
        $renderer = new MarkdownRenderer();
        $replacement = "\u{E004}\u{E009}\u{E00A}\u{E00E}";
        $calls = 0;
        $renderer->on('render.text', static function (RenderEvent $event) use (&$calls, $replacement): void {
            $calls++;
            $node = $event->getNode();
            if ($node instanceof Text) {
                $node->setContent($replacement);
            }
        });
        self::assertSame($replacement . "\n", $renderer->render((new CarveConverter())->parse("a\n")));
        self::assertSame(1, $calls);
    }

    public function testFallbackDispatchesRenderEventsOnce(): void
    {
        $renderer = new MarkdownRenderer();
        $converter = new CarveConverter();
        $calls = 0;
        $replacement = "\u{E009}m1x0\u{E00A}";
        $renderer->on('render.text', static function (RenderEvent $event) use (&$calls, $replacement): void {
            $calls++;
            $event->setHtml($replacement);
        });
        $document = $converter->parse("a\n\n```\n" . self::pool() . "\n```\n");
        self::assertStringContainsString($replacement, $renderer->render($document));
        self::assertSame(1, $calls);
        $renderer->off();
        self::assertSame("a\n", $renderer->render($converter->parse("a\n")));
        self::assertSame(1, $calls);
    }

    public function testFallbackChargesLiteralLabelBytes(): void
    {
        $renderer = new class extends MarkdownRenderer {
            protected function resetAbbreviationBudget(int $sourceLength): void
            {
                parent::resetAbbreviationBudget($sourceLength);
                $this->abbreviationBudget = 4;
            }
        };
        $document = (new CarveConverter())->parse("```\n" . self::pool() . "\n```\n");
        $heading = new Heading(1);
        $heading->setAttribute('id', 'target');
        $token = "\u{E009}m0x0\u{E00A}";
        $children = [];
        foreach (mb_str_split(str_repeat($token, 3)) as $character) {
            $children[] = new Text($character);
        }
        $heading->setChildren($children);
        $paragraph = new Paragraph();
        $paragraph->appendChild(new HeadingRef('target'));
        $paragraph->appendChild(new Text(' '));
        $paragraph->appendChild(new HeadingRef('target'));
        $document->setChildren([$heading, $paragraph, ...$document->getChildren()]);
        $output = $renderer->render($document);
        self::assertSame(1, substr_count($output, str_repeat($token, 3)));
        self::assertStringContainsString('[target](#', $output);
        self::assertStringContainsString('[target](#', $output);
        self::assertStringContainsString(self::pool(), $output);
    }

    public function testFallbackDispatchesNodeEventsInOrder(): void
    {
        $renderer = new MarkdownRenderer();
        $document = (new CarveConverter())->parse("```\n" . self::pool() . "\n```\n");
        $paragraph = new Paragraph();
        $paragraph->setChildren([new Text('first'), new Text('second')]);
        $document->setChildren([$paragraph, ...$document->getChildren()]);
        $calls = 0;
        $renderer->on('render.text', static function (RenderEvent $event) use (&$calls): void {
            $event->setHtml((string)++$calls);
        });
        self::assertStringStartsWith("12\n", $renderer->render($document));
        self::assertSame(2, $calls);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function clonedEventSources(): array
    {
        return [
            'cross-reference' => ["# title\n\n</#title>\n"],
            'list-table' => ["{header-rows=1}\n::: list-table\n- - A\n  - B\n- - one\n    two\n  - x\n:::\n"],
        ];
    }

    #[DataProvider('clonedEventSources')]
    public function testFallbackDispatchesEventsForClonedNodes(string $source): void
    {
        $converter = new CarveConverter();
        $render = static function (string $input) use ($converter): array {
            $renderer = new MarkdownRenderer();
            $calls = 0;
            $renderer->on('render.text', static function (RenderEvent $event) use (&$calls): void {
                $event->setHtml((string)++$calls);
            });
            $output = $renderer->render($converter->parse($input));

            return [$output, $calls];
        };
        [$ordinary, $ordinaryCalls] = $render($source);
        [$fallback, $fallbackCalls] = $render($source . "\n```\n" . self::pool() . "\n```\n");
        self::assertGreaterThan(1, $ordinaryCalls);
        self::assertSame($ordinaryCalls, $fallbackCalls);
        self::assertStringStartsWith(rtrim($ordinary) . "\n\n```\n", $fallback);
    }

    public function testFailedRenderDoesNotRetainEventState(): void
    {
        $renderer = new MarkdownRenderer();
        $converter = new CarveConverter();
        $renderer->on('render.text', static function (): void {
            throw new RuntimeException('probe listener failed');
        });
        try {
            $renderer->render($converter->parse("a\n\n```\n" . self::pool() . "\n```\n"));
            self::fail('The listener should throw.');
        } catch (RuntimeException $error) {
            self::assertSame('probe listener failed', $error->getMessage());
        }
        $renderer->off();
        self::assertSame("a\n", $renderer->render($converter->parse("a\n")));
    }

    public function testFallbackDispatchesEventsForFlattenedBlockTableCells(): void
    {
        $render = static function (bool $fallback): array {
            $document = new Document();
            $table = new Table();
            $row = new TableRow(true);
            $cell = new TableCell(true);
            $cell->setBlockContent(true);
            $paragraph = new Paragraph();
            $paragraph->appendChild(new Text('title'));
            $cell->appendChild($paragraph);
            $row->appendChild($cell);
            $table->appendChild($row);
            $document->appendChild($table);
            if ($fallback) {
                foreach ((new CarveConverter())->parse("```\n" . self::pool() . "\n```\n")->getChildren() as $block) {
                    $document->appendChild(clone $block);
                }
            }
            $renderer = new MarkdownRenderer();
            $calls = 0;
            $renderer->on('render.text', static function (RenderEvent $event) use (&$calls): void {
                $event->setHtml((string)++$calls);
            });
            $output = $renderer->render($document);

            return [$output, $calls];
        };
        [$ordinary, $ordinaryCalls] = $render(false);
        [$fallback, $fallbackCalls] = $render(true);
        self::assertSame($ordinaryCalls, $fallbackCalls);
        self::assertStringStartsWith(rtrim($ordinary) . "\n\n```\n", $fallback);
    }

    public function testRenderDoesNotDuplicateDiagnosticsForTransientNodes(): void
    {
        $renderer = new class extends MarkdownRenderer {
            /**
             * @var list<\MarkupCarve\Carve\Node\Inline\Text>
             */
            private array $diagnosticNodes = [];

            protected function renderDerivedLabel(array $nodes): string
            {
                $node = new Text('transient');
                $this->diagnosticNodes[] = $node;
                $this->recordUnspellableField($node, 'test', 'test diagnostic');

                return parent::renderDerivedLabel($nodes);
            }
        };
        $document = (new CarveConverter())->parse("# title\n\n</#title>\n\n```\n" . self::pool() . "\n```\n");
        foreach ([0, 1] as $maximum) {
            $renderer->beginConversionDiagnosticCollection($maximum);
            $renderer->render($document);
            $report = $renderer->finishConversionDiagnosticCollection();
            self::assertSame(1, $report['totalDiagnostics']);
            self::assertCount($maximum, $report['diagnostics']);
            self::assertSame($maximum === 0, $report['truncated']);
        }
    }

    public function testFallbackRendersAbbreviationsInsideCrossReferenceLabels(): void
    {
        $converter = new CarveConverter();
        $source = "# A ABB\n\n</#A-ABB> </#A-ABB>\n\n*[ABB]: expansion\n";
        $render = static function (string $input) use ($converter): string {
            $renderer = new class extends MarkdownRenderer {
                protected function resetAbbreviationBudget(int $sourceLength): void
                {
                    parent::resetAbbreviationBudget($sourceLength);
                    $this->abbreviationBudget = 40;
                }
            };

            return $renderer->render($converter->parse($input));
        };
        $ordinary = $render($source);
        self::assertStringNotContainsString('</#', $ordinary);
        self::assertStringContainsString('ABB', $ordinary);
        self::assertStringStartsWith(rtrim($ordinary) . "\n\n```\n", $render($source . "\n```\n" . self::pool() . "\n```\n"));
    }

    public function testFallbackKeepsPreparedHeadingIdsWhenAListenerMutatesText(): void
    {
        $converter = new CarveConverter();
        $render = static function (string $source) use ($converter): array {
            $renderer = new MarkdownRenderer();
            $calls = 0;
            $renderer->on('render.text', static function (RenderEvent $event) use (&$calls): void {
                $calls++;
                $node = $event->getNode();
                if ($node instanceof Text) {
                    $node->setContent('Title!');
                }
            });

            return [$renderer->render($converter->parse($source)), $calls];
        };
        $source = "# title\n\n</#title>\n";
        [$ordinary, $ordinaryCalls] = $render($source);
        [$fallback, $fallbackCalls] = $render($source . "\n```\n" . self::pool() . "\n```\n");
        self::assertSame(2, $ordinaryCalls);
        self::assertSame($ordinaryCalls, $fallbackCalls);
        self::assertStringStartsWith(rtrim($ordinary) . "\n\n```\n", $fallback);
        self::assertStringContainsString('[Title!](#title)', $fallback);
    }
}

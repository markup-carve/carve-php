<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\Code;
use MarkupCarve\Carve\Node\Inline\RawText;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\Utility\DocumentSentinels;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SourceUnicodeMarkerExhaustionTest extends TestCase
{
    public function testFootnoteGuardsStaySeparateWhenOnlyEightCharactersAreFree(): void
    {
        foreach ([0xE001, 0xE003, 0xE004, 0xE005, 0xE006] as $freeStart) {
            $pool = '';
            for ($code = 0xE000; $code <= 0xF8FF; $code++) {
                if ($code < $freeStart || $code >= $freeStart + 8) {
                    $pool .= mb_chr($code, 'UTF-8');
                }
            }
            $writer = CarveConverter::carve();
            $source = "```\n{$pool}\n```\n\nbody[^n]\n\n[^n]: > `x`\n";
            $document = $writer->parse($source);
            $stack = $document->getChildren();
            while ($stack !== []) {
                $node = array_pop($stack);
                if ($node instanceof Code) {
                    $node->setContent("x`\n[r]: /url\ntail");
                } elseif (method_exists($node, 'getChildren')) {
                    array_push($stack, ...$node->getChildren());
                }
            }
            $baseline = $writer->renderWithReport($document);
            $this->assertSame([], $baseline->losses);
            $this->assertSame(self::semanticTree((new AstCodec())->encode($document)), self::semanticTree((new AstCodec())->encode($writer->parse($baseline->value))));
            $paragraph = new Paragraph();
            $paragraph->setChildren([new Text("\u{E009}m0"), new RawText("x0\u{E00A}")]);
            $document->setChildren([$paragraph, ...$document->getChildren()]);
            $result = $writer->renderWithReport($document);
            $this->assertSame([], $result->losses);
            $this->assertStringContainsString($pool, $result->value);
            $this->assertStringContainsString("\u{E009}m0x0\u{E00A}", $result->value);
            $this->assertStringContainsString('[r]: /url', $result->value);
            $this->assertSame($result->value, $writer->convert($result->value));
        }
    }

    public function testProbeKeepsAttributeBracingIndependentOfTheOccupiedPool(): void
    {
        $pool = implode('', array_map(static fn (int $code): string => mb_chr($code, 'UTF-8'), range(0xE000, 0xF8FF)));
        $writer = CarveConverter::carve();
        foreach (["*a*{.one}\n", "/b/{.two}\n", "*/c/*{.three}\n", "[*a*]{.host}\n", "*a /b/ c*{.one}\n"] as $source) {
            $ordinary = $writer->convert($source);
            $written = $writer->convert("```\n{$pool}\n```\n\n" . $source);
            $this->assertStringEndsWith($ordinary, $written);
        }
    }

    public function testProbeDoesNotDuplicateRubyLosses(): void
    {
        $pool = implode('', array_map(static fn (int $code): string => mb_chr($code, 'UTF-8'), range(0xE000, 0xF8FF)));
        $writer = CarveConverter::carve();
        $document = $writer->parse("```\n{$pool}\n```\n");
        $paragraph = new Paragraph();
        $paragraph->setChildren([new Ruby([['base' => [new Text('base')], 'annotation' => [new Text('reading')]]])]);
        $document->setChildren([...$document->getChildren(), $paragraph]);
        $report = $writer->renderWithReport($document);
        $this->assertSame(1, $report->totalLosses);
        $this->assertCount(1, $report->losses);
        $this->assertStringContainsString('base(reading)', $report->value);
    }

    public function testAssemblyDoesNotCreateAnAuthoredTokenCollision(): void
    {
        $pool = implode('', array_map(static fn (int $code): string => mb_chr($code, 'UTF-8'), range(0xE000, 0xF8FF)));
        $converter = new CarveConverter();
        $document = $converter->parse("```\n{$pool}\n```\n");
        $paragraph = new Paragraph();
        $paragraph->setChildren([new Text("\u{E009}m0"), new RawText("x0\u{E00A}")]);
        $document->setChildren([...$document->getChildren(), $paragraph]);
        $renderer = new CarveRenderer();
        $written = $renderer->render($document);
        $this->assertStringContainsString("\u{E009}m0x0\u{E00A}", $written);
        $this->assertSame($written, $renderer->render($converter->parse($written)));
    }

    public function testCustomMultiCharacterSentinelsAreRetained(): void
    {
        $renderer = new class extends CarveRenderer {
            protected function pickVerbatimSentinels(string $text): array
            {
                return ["\u{E100}custom0\u{E101}", "\u{E100}custom1\u{E101}", "\u{E100}custom2\u{E101}", "\u{E100}custom3\u{E101}", "\u{E100}custom4\u{E101}", "\u{E100}custom5\u{E101}", "\u{E100}custom6\u{E101}", "\u{E100}custom7\u{E101}"];
            }

            public function firstToken(): string
            {
                return $this->verbatimSentinels[0];
            }
        };
        $this->assertSame("ordinary\n", $renderer->render((new CarveConverter())->parse("ordinary\n")));
        $this->assertSame("\u{E100}custom0\u{E101}", $renderer->firstToken());
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        $pool = '';
        for ($code = 0xE000; $code <= 0xF8FF; $code++) {
            $pool .= mb_chr($code, 'UTF-8');
        }
        $payload = $pool . "\u{E009}" . str_repeat("\u{E008}", 24) . "\u{E010}\u{E00A}";

        $lookalikes = '';
        for ($nonce = 0; $nonce < 64; $nonce++) {
            $lookalikes .= "\u{E009}m{$nonce}x0\u{E00A}";
        }
        $large = $pool . $lookalikes . str_repeat("\u{E008}", 1024);
        $spaces = str_repeat(' ', 1024);

        return [
            'large code whitespace' => ["```\n{$large}{$spaces}\n\nlast\t\n```\n", $large],
            'large verse whitespace' => ["| {$large}{$spaces}\n| last\n", $large],
            'footnote code block' => ["a[^n] {$pool}\n\n[^n]: x\n\n  ```\n  \u{E007}> y\n  ```\n", $pool],
            'footnote code span' => ["a[^n] {$pool}\n\n[^n]: `x\n  \u{E007}> y`\n", $pool],
            'text' => ["a{$payload}b\n", $payload],
            'code' => ["`{$payload}`\n", $payload],
            'literal' => ["!`{$payload}`\n", $payload],
            'block' => ["```\na{$payload}  \n\nb\t\n```\n", $payload],
            'image' => ["![{$payload}](image)\n", $payload],
            'link' => ["[{$payload}](/target)\n", $payload],
            'verse' => ["| a{$payload}\n| b\n", $payload],
            'quote' => ["> a{$payload}\n> b\n", $payload],
            'list' => ["- a{$payload}\n  b\n", $payload],
            'footnote code guard' => ["body[^n]\n\n[^n]: `a{$payload}\n  [r]: /target\n  tail`\n", $payload],
            'footnote literal guard' => ["body[^n]\n\n[^n]: !`a{$payload}\n  [r]: /target\n  tail`\n", $payload],
            'footnote math guard' => ["body[^n]\n\n[^n]: $`a{$payload}\n  [r]: /target\n  tail`\n", $payload],
            'footnote label' => ["a{$pool}b\n\n[^\u{E009}\u{E008}\u{E008}\u{E010}\u{E00A}]: definition\n", $pool],
        ];
    }

    public function testTokenSizeStaysBoundedForLongAuthoredRuns(): void
    {
        $rows = self::sources();
        $text = $rows['large code whitespace'][0] . str_repeat("\u{E008}", 8192);
        $tokens = DocumentSentinels::pickTokens($text, 8, 0xE001);
        self::assertCount(8, array_unique($tokens));
        foreach ($tokens as $token) {
            self::assertLessThan(64, strlen($token));
            self::assertStringNotContainsString($token, $text);
        }
    }

    /**
     * @param array<mixed> $value
     *
     * @return array<mixed>
     */
    private static function semanticTree(array $value): array
    {
        unset($value['pos'], $value['srcByteLength']);
        foreach ($value as $key => $child) {
            if (is_array($child)) {
                $value[$key] = self::semanticTree($child);
            }
        }

        return $value;
    }

    #[DataProvider('sources')]
    public function testSourceAndAstPreserveTheOccupiedPool(string $source, string $payload): void
    {
        $writer = CarveConverter::carve();
        $codec = new AstCodec();
        $document = $writer->parse($source);
        $expected = self::semanticTree($codec->encode($document));
        foreach ([$writer->convertWithReport($source, strictLosses: true), $writer->renderWithReport($document, strictLosses: true)] as $result) {
            self::assertStringContainsString($payload, $result->value);
            self::assertSame($expected, self::semanticTree($codec->encode($writer->parse($result->value))));
            self::assertSame([], $result->losses);
            self::assertSame(0, $result->totalLosses);
            self::assertFalse($result->truncated);
            self::assertSame($result->value, $writer->convert($result->value));
        }
        self::assertSame("ordinary\n", $writer->convert("ordinary\n"));
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Ast\AstMerge;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Lint\MarkdownHabitLinter;
use MarkupCarve\Carve\Lint\SourceLinter;
use MarkupCarve\Carve\Lint\SourceOffsets;
use MarkupCarve\Carve\Lint\TableColumnLinter;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class RemainingScanCostsTest extends TestCase
{
    use ScalingGuardTrait;

    public function testLintWarningCursorsPreserveUnicodeAndMultilinePositions(): void
    {
        $warnings = (new MarkdownHabitLinter())->lint("😀 **a**\nβ **b** @user #12 \u{202E}", ['platforms' => ['github']]);
        $positions = array_map(static fn ($warning) => [$warning->line, $warning->column, $warning->start], $warnings);
        $this->assertSame([[1, 3, 5], [2, 3, 14], [2, 9, 20], [2, 15, 26], [2, 19, 30]], $positions);
        $warnings = (new MarkdownHabitLinter())->lint("x\n\nβé **a** 日本 **b**");
        $this->assertSame([[3, 4], [3, 13]], array_map(static fn ($warning) => [$warning->line, $warning->column], $warnings));
    }

    #[Group('scaling')]
    public function testDenseLintWarningsScaleLinearly(): void
    {
        $linter = new MarkdownHabitLinter();
        foreach (['😀 **a** ', '😀 @user #12 ', "😀 \u{202E} "] as $unit) {
            $this->assertConversionScalesLinearly(
                static fn (string $source) => $linter->lint($source, ['platforms' => ['github']]),
                str_repeat($unit, 4000),
                str_repeat($unit, 16000),
                'dense lint warnings',
                4000,
                16000,
                maxPerByteRatio: 2.0,
            );
        }
    }

    #[Group('scaling')]
    public function testEditorBatchesScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($converter): void {
                $session = $converter->createEditorSession($source);
                $changes = [];
                $length = strlen($source);
                for ($at = 0; $at < $length; $at += 6) {
                    $changes[] = ['from' => $at, 'to' => $at + 1, 'insert' => 'bb'];
                }
                $session->update($changes);
            },
            str_repeat("a\n\n", 1000),
            str_repeat("a\n\n", 4000),
            'editor batches',
            1000,
            4000,
        );
    }

    #[Group('scaling')]
    public function testEditorAttributeMappingScalesLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->createEditorSession($source),
            str_repeat("{.a}\nword\n\n", 4000),
            str_repeat("{.a}\nword\n\n", 16000),
            'editor attribute mapping',
            4000,
            16000,
        );
    }

    public function testMalformedUtf8PrefixIndexKeepsExistingColumns(): void
    {
        foreach (["\x80 😀\xf0\x9f {{ }}\xe2", "Å\x85{{ }}", "\xe2a {{ }}", "\xe2\n{{ }}"] as $source) {
            foreach (SourceOffsets::asciiPrefixCounts($source) as $offset => $count) {
                $this->assertSame(mb_strlen(substr($source, 0, $offset), 'UTF-8'), $count);
            }
        }
        $warnings = (new SourceLinter())->lint("😀 {{ }} {{ }}\x80");
        $this->assertSame([[3, 5], [9, 11]], array_map(static fn ($warning) => [$warning->column, $warning->start], $warnings));
        $warnings = (new TableColumnLinter())->lint("Å\x80\n{widths=60,50}\n| a | b |");
        $this->assertSame(2, $warnings[0]->line);
        $this->assertSame(2, $warnings[0]->column);
        $warnings = (new TableColumnLinter())->lint("\xe2\n{widths=60,50}\n| a | b |");
        $this->assertSame(2, $warnings[0]->column);
    }

    #[Group('scaling')]
    public function testMalformedUtf8LintWarningsScaleLinearly(): void
    {
        $linter = new SourceLinter();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $linter->lint($source),
            str_repeat('😀 {{ }} ', 2000) . "\x80",
            str_repeat('😀 {{ }} ', 8000) . "\x80",
            'malformed UTF-8 lint positions',
            2000,
            8000,
        );
    }

    public function testIndexedLintPositionsPreserveUnicodeAndLineEndings(): void
    {
        $warnings = (new SourceLinter())->lint('😀 {{ }} {{ }}');
        $this->assertSame([[3, 5], [9, 11]], array_map(static fn ($warning) => [$warning->column, $warning->start], $warnings));
        foreach (["\n", "\r\n", "\r"] as $ending) {
            $source = 'Å Ⅰ 😀' . $ending . '{widths=60,50}' . $ending . '| a | b |';
            $warnings = (new TableColumnLinter())->lint($source);
            $this->assertCount(1, $warnings);
            $this->assertSame(2, $warnings[0]->line);
            $this->assertSame(2, $warnings[0]->column);
            $this->assertSame('widths', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
        }
    }

    #[Group('scaling')]
    public function testIndexedLintWarningsScaleLinearly(): void
    {
        foreach ([new SourceLinter(), new TableColumnLinter()] as $linter) {
            $unit = $linter instanceof SourceLinter ? '😀 {{ }} ' : "{widths=60,50}\n| a | b |\n\n";
            $this->assertConversionScalesLinearly(
                static fn (string $source) => $linter->lint($source),
                str_repeat($unit, 2000),
                str_repeat($unit, 8000),
                'indexed lint positions',
                2000,
                8000,
            );
        }
    }

    public function testBalancedDestinationsAndTitlesKeepTheirSpelling(): void
    {
        $converter = new CarveConverter();
        $this->assertStringContainsString('href="a(b)c" title="title)"', $converter->convert('[x](a(b)c "title)")'));
        $this->assertStringNotContainsString('<a ', $converter->convert(str_repeat('[x](', 20) . ')'));
    }

    public function testConcurrentDuplicateAdditionsKeepOccurrenceCounts(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $result = AstMerge::merge(
            $codec->encode($converter->parse('')),
            $codec->encode($converter->parse("a\n\na\n\nb\n")),
            $codec->encode($converter->parse("a\n\nb\n\nb\n")),
        );
        $this->assertTrue($result['ok']);
        $this->assertIsArray($result['ast']['children']);
        $this->assertCount(4, $result['ast']['children']);
        $this->assertSame("a\n\na\n\nb\n\nb", trim((new PlainTextRenderer())->render($codec->decode($result['ast']))));
    }

    public function testNestedLabelsKeepFollowingLinks(): void
    {
        $converter = new CarveConverter();
        $this->assertStringContainsString('<a href="w">z</a>', $converter->convert('[[x](y)](u) [z](w)'));
        $this->assertStringContainsString('<a href="w" title="q">z</a>', $converter->convert('[[x](y "t")](u "v") [z](w "q")'));
        foreach (['[x](a\\)b)', '[x](a\\\\)', '[t](/u "a\\"b)")', "[t](/u 'T)')", '[x](a"b)c)'] as $source) {
            $this->assertStringContainsString('<a ', $converter->convert($source));
        }
        $this->assertStringNotContainsString('<a ', $converter->convert('[x](u "open)'));
        foreach (['"', "'"] as $quote) {
            $source = '[t](/u ' . $quote . 'a' . str_repeat('\\', 2) . $quote . 'b' . $quote . ')';
            $this->assertStringContainsString('<a href="/u"', $converter->convert($source));
        }
    }

    #[Group('scaling')]
    public function testNestedWhitespaceDestinationsScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->parse($source),
            str_repeat('[x](a b', 1000) . str_repeat(')', 1000),
            str_repeat('[x](a b', 4000) . str_repeat(')', 4000),
            'nested whitespace destinations',
            1000,
            4000,
        );
    }

    #[Group('scaling')]
    public function testSharedUnclosedTitleScalesLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->parse($source),
            str_repeat('[x](a', 1000) . ' "' . str_repeat(')', 1000),
            str_repeat('[x](a', 4000) . ' "' . str_repeat(')', 4000),
            'shared unclosed title',
            1000,
            4000,
        );
    }

    #[Group('scaling')]
    public function testConcurrentAdditionsScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $base = $codec->encode($converter->parse(''));
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($converter, $codec, $base): void {
                AstMerge::merge($base, $codec->encode($converter->parse($source)), $codec->encode($converter->parse(str_replace('a', 'b', $source))));
            },
            str_repeat("a\n\n", 500),
            str_repeat("a\n\n", 2000),
            'concurrent merge additions',
            500,
            2000,
        );
    }

    public function testMatchingAdditionPrecedesLaterIdentityConflicts(): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $base = $codec->encode($converter->parse(''));
        $a = $codec->encode($converter->parse("{#h}\na\n"));
        $b = $codec->encode($converter->parse("{#h}\nb\n"));
        $both = $codec->encode($converter->parse("{#h}\na\n\n{#h}\nb\n"));
        $this->assertTrue(AstMerge::merge($base, $a, $both)['ok']);
        $conflict = AstMerge::merge($base, $b, $both);
        $this->assertFalse($conflict['ok']);
        $this->assertSame('concurrent-sequence-edit', $conflict['conflicts'][0]['reason']);
    }

    #[Group('scaling')]
    public function testDistantDestinationCloserScalesLinearly(): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), '[x](', ')', 'distant destination closer', 2000);
    }

    #[Group('scaling')]
    public function testNestedEmphasisKeepsTheDestinationIndex(): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), '*[x](', ')', 'nested emphasis destinations', 1000);
    }

    #[Group('scaling')]
    public function testWideCommentWalkScalesLinearly(): void
    {
        $method = new ReflectionMethod(CarveRenderer::class, 'holdsLineComment');
        $small = new Emphasis();
        $large = new Emphasis();
        for ($i = 0; $i < 16000; ++$i) {
            $large->appendChild(new Text('a'));
            if ($i < 4000) {
                $small->appendChild(new Text('a'));
            }
        }
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $method->invoke(null, strlen($source) === 4000 ? $small : $large),
            str_repeat('a', 4000),
            str_repeat('a', 16000),
            'wide comment walk',
            4000,
            16000,
        );
    }

    #[Group('scaling')]
    public function testDuplicateMergeBucketsScaleLinearly(): void
    {
        $method = new ReflectionMethod(AstMerge::class, 'matchSide');
        $this->assertConversionScalesLinearly(
            static function (string $source) use ($method): void {
                $nodes = array_fill(0, strlen($source), ['type' => 'text', 'value' => 'a']);
                $method->invoke(null, $nodes, $nodes, '/children');
            },
            str_repeat('a', 4000),
            str_repeat('a', 16000),
            'duplicate merge buckets',
            4000,
            16000,
        );
    }

    #[Group('scaling')]
    public function testFailedLintDestinationsScaleLinearly(): void
    {
        $linter = new MarkdownHabitLinter();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $linter->lint($source, ['platforms' => ['github']]),
            str_repeat('[x](', 2000) . ')',
            str_repeat('[x](', 8000) . ')',
            'lint destinations',
            2000,
            8000,
        );
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Export;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\CarveExportError;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Output\OutputWriter;
use MarkupCarve\Carve\Output\WriterRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown, plain-text and ANSI exports, with the cases of the carve-js export tests (carve-js#5076).
 */
final class ExportTest extends TestCase
{
    private static function md(string $source): string
    {
        return (new MarkdownExporter())->export(Carve::parse($source))->output;
    }

    private static function plain(string $source): string
    {
        return (new PlainTextExporter())->export(Carve::parse($source))->output;
    }

    private static function ansi(string $source): string
    {
        return (new AnsiExporter())->export(Carve::parse($source))->output;
    }

    private static function shown(string $source): string
    {
        return (string)preg_replace('/\e\[[0-9;]*m/', '', self::ansi($source));
    }

    /**
     * @param array<array<string, mixed>> $inlines
     *
     * @return \MarkupCarve\Carve\Node
     */
    private static function paragraphTree(array $inlines): Node
    {
        return Carve::decodeTree((string)json_encode([
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [['type' => 'paragraph', 'children' => $inlines]],
        ]));
    }

    public function testTheResultHoldsTheTextAndTheLosses(): void
    {
        $result = (new MarkdownExporter())->export(Carve::parse("::: x\nz\n:::\n"));
        $this->assertSame("z\n", $result->output);
        $this->assertSame(['markdown-container-flattened'], $result->codes());
        $this->assertSame('render-loss', $result->diagnostics[0]->channel);
        $this->assertSame('warning', $result->diagnostics[0]->severity);
        $this->assertSame('/children/0', $result->diagnostics[0]->path);
    }

    public function testStrictLossesThrows(): void
    {
        $this->assertSame("a\n", (new MarkdownExporter())->export(Carve::parse("a\n"), ['strictLosses' => true])->output);
        $this->expectException(CarveExportError::class);
        (new MarkdownExporter())->export(Carve::parse("```=latex\n\\x\n```\n"), ['strictLosses' => true]);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function badOptions(): array
    {
        return [
            'unknown' => [['width' => 80]],
            'not a boolean' => [['smartTypography' => 'yes']],
            'policy not a list' => [['urlPolicy' => ['deny' => 'javascript']]],
        ];
    }

    /**
     * @param array<string, mixed> $options
     */
    #[DataProvider('badOptions')]
    public function testABadOptionIsRefused(array $options): void
    {
        $this->expectException(OptionError::class);
        (new PlainTextExporter())->export(Carve::parse('a'), $options);
    }

    public function testTheGivenTreeIsNotChanged(): void
    {
        $tree = Carve::parse("= H\n\nSee </#H>.\n");
        $before = Carve::encodeTree($tree);
        (new MarkdownExporter())->export($tree);
        (new AnsiExporter())->export($tree);
        $this->assertSame($before, Carve::encodeTree($tree));
    }

    public function testSmartTypography(): void
    {
        $tree = Carve::parse("a -- b...\n");
        $this->assertSame("a \u{2013} b\u{2026}\n", (new PlainTextExporter())->export($tree)->output);
        $this->assertSame("a -- b...\n", (new PlainTextExporter())->export($tree, ['smartTypography' => false])->output);
    }

    public function testADeniedDestinationIsBlankedAndReported(): void
    {
        $result = (new MarkdownExporter())->export(Carve::parse("[a](javascript:x) [b](ftp://h/f)\n"));
        $this->assertSame("[a]() [b](ftp://h/f)\n", $result->output);
        $this->assertSame(['destination-denied'], $result->codes());
        $denied = (new MarkdownExporter())->export(Carve::parse("[b](ftp://h/f)\n"), ['urlPolicy' => ['deny' => ['ftp']]]);
        $this->assertSame("[b]()\n", $denied->output);
    }

    public function testTheRegistryReservesTheExportTargets(): void
    {
        $this->assertSame(['markdown', 'plain', 'ansi'], array_keys(WriterRegistry::RESERVED_TARGETS));
        $registry = Carve::writers();
        $this->assertSame(['html', 'carve', 'markdown', 'plain', 'ansi'], $registry->targets());
        $tree = Carve::parse("= T\n\n*b* text\n");
        $this->assertSame((new MarkdownExporter())->export($tree)->output, $registry->write('markdown', $tree));
        $this->assertSame((new PlainTextExporter())->export($tree)->output, $registry->write('plain', $tree));
        $this->assertSame((new AnsiExporter())->export($tree)->output, $registry->write('ansi', $tree));
        $this->assertSame("a\n", Carve::writeTo('plain', Carve::parse('*a*')));

        $impostor = new class implements OutputWriter {
            public function target(): string
            {
                return 'markdown';
            }

            public function write(Node $tree, array $options = []): string
            {
                return '';
            }
        };
        $this->expectException(OptionError::class);
        $this->expectExceptionMessage('the output target markdown is reserved');
        (new WriterRegistry())->register($impostor);
    }

    /**
     * @return array<array{string, string}>
     */
    public static function markdownCases(): array
    {
        return [
            ['= Head', "# Head\n"],
            ['a *b* /c/ `d`', "a **b** *c* `d`\n"],
            ["- [x] done\n- [ ] todo", "- [x] done\n- [ ] todo\n"],
            ["1. one\n2. two", "1. one\n2. two\n"],
            ['> q', "> q\n"],
            ["```js\nx\n```", "```js\nx\n```\n"],
            ["a\n\n***\n\nb", "a\n\n---\n\nb\n"],
            ["|= A |= B |\n| 1 | 2 |", "| A | B |\n| --- | --- |\n| 1 | 2 |\n"],
            ['{=mark=} {+ins+} {-del-}', "<mark>mark</mark> <ins>ins</ins> <del class=\"critic-delete\">del</del>\n"],
            ['[t](https://e.x "T")', "[t](https://e.x \"T\")\n"],
            ['![a](i.png)', "![a](i.png)\n"],
            ['<https://a.b>', "<https://a.b>\n"],
            ["x[^n]\n\n[^n]: note", "x[^n]\n\n[^n]: note\n"],
            ["::: note\nbody\n:::", "body\n"],
            ["::: |\nl1\nl2\n:::", "l1\\\nl2\n"],
            ['$`x`', '$x$' . "\n"],
            ['[x]{abbr=Y}', "<abbr title=\"Y\">x</abbr>\n"],
            [":: t\n: d", "**t**\n\nd\n"],
            ["```=html\n<b>\n```", "&lt;b&gt;\n"],
            ["a\\\nb", "a\\\nb\n"],
            ['snake_case and _x', "snake_case and \\_x\n"],
            ["= H\n\nSee </#H>.", "# H\n\nSee [H](#h).\n"],
            ["- a\n\n%% #region x\n\n\n\n- b", "- a\n\n* b\n"],
            ["- a\n  - b", "- a\n  - b\n"],
            ['x {_y_}', "x <u>y</u>\n"],
            ['``a`b``', "``a`b``\n"],
            ['x{*"y"*}', "x<strong>\u{201C}y\u{201D}</strong>\n"],
            ["````\n```\n````", "````\n```\n````\n"],
        ];
    }

    #[DataProvider('markdownCases')]
    public function testMarkdown(string $source, string $expected): void
    {
        $this->assertSame($expected, self::md($source));
    }

    public function testMarkdownKeepsTextFromReadingAsABlock(): void
    {
        $inlines = [];
        foreach (['a', '- b', '# c', '1. d', '***'] as $at => $value) {
            if ($at > 0) {
                $inlines[] = ['type' => 'soft_break'];
            }
            $inlines[] = ['type' => 'text', 'value' => $value];
        }
        $this->assertSame("a\n\\- b\n\\# c\n1\\. d\n\\*\\*\\*\n", (new MarkdownExporter())->export(self::paragraphTree($inlines))->output);
    }

    public function testMarkdownWritesACaptionAfterItsHost(): void
    {
        $result = (new MarkdownExporter())->export(Carve::parse("![p](p.png)\n^ Figure #: q\n"));
        $this->assertSame("![p](p.png)\nFigure 1: q\n", $result->output);
        $this->assertSame(['markdown-caption-flattened'], $result->codes());
    }

    /**
     * @return array<array{string, string}>
     */
    public static function plainCases(): array
    {
        return [
            ['= Head', "Head\n"],
            ['a *b* /c/ `d`', "a b c d\n"],
            ["- [x] done\n- [ ] todo", "- done\n- todo\n"],
            ['> q', "\"q\"\n"],
            ["|= A |= B |\n| 1 | 2 |", "A | B\n1 | 2\n"],
            ['{-del-} {+ins+}', "~del~ ins\n"],
            ["x[^n]\n\n[^n]: note", "x[n]\n\n[^n]: note\n"],
            ["*[HTML]: Hyper Text\n\nHTML here", "HTML (Hyper Text) here\n"],
            ['@user #tag :smile:', "@user #tag :smile:\n"],
            ["::: |\na \\\nb\n:::", "a \nb\n"],
            ["a\n%% c\nb", "a b\n"],
        ];
    }

    #[DataProvider('plainCases')]
    public function testPlainText(string $source, string $expected): void
    {
        $this->assertSame($expected, self::plain($source));
    }

    public function testPlainTextReportsDroppedRawContent(): void
    {
        $result = (new PlainTextExporter())->export(Carve::parse("```=html\n<b>\n```\n"));
        $this->assertSame("\n", $result->output);
        $this->assertSame(['raw-format-dropped'], $result->codes());
    }

    public function testAnsiStylesInlineMarkup(): void
    {
        $this->assertSame("a \e[1mb\e[0m \e[3mc\e[0m \e[93md\e[0m\n", self::ansi('a *b* /c/ `d`'));
    }

    public function testAnsiUnderlinesHeadingsAcrossTheirDisplayWidth(): void
    {
        $this->assertSame("日本\n════\n", self::shown('= 日本'));
        $this->assertSame("x\n", self::shown('=== x'));
    }

    public function testAnsiDrawsABoxTable(): void
    {
        $this->assertSame(
            "┌───┬────┐\n│ A │ B  │\n├───┼────┤\n"
            . "│ 1 │ 22 │\n└───┴────┘\n",
            self::shown("|= A |= B |\n| 1 | 22 |"),
        );
    }

    public function testAnsiMarksQuotesAndTasks(): void
    {
        $this->assertSame("│ a\n\n│ b\n", self::shown("> a\n>\n> b"));
        $this->assertSame("☑ a\n☐ b\n", self::shown("- [x] a\n- [ ] b"));
    }

    public function testAnsiDropsControlCharacters(): void
    {
        $tree = self::paragraphTree([['type' => 'text', 'value' => "a\e]0;x\u{0007}b"]]);
        $this->assertSame("a]0;xb\n", (new AnsiExporter())->export($tree)->output);
    }

    /**
     * @param array<string> $args
     * @param string $input
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function cli(array $args, string $input): array
    {
        $in = fopen('php://memory', 'w+');
        $out = fopen('php://memory', 'w+');
        $err = fopen('php://memory', 'w+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        fwrite($in, $input);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testRenderToOnTheCommandLine(): void
    {
        $this->assertSame([0, "# T\n\n**b**\n", ''], self::cli(['render', '--to', 'markdown'], "= T\n\n*b*\n"));
        $this->assertSame([0, "T\n\nb\n", ''], self::cli(['render', '--to', 'plain'], "= T\n\n*b*\n"));
        $this->assertSame([0, "\e[1mb\e[0m\n", ''], self::cli(['render', '--to=ansi'], "*b*\n"));
        $this->assertSame([0, "<p><strong>b</strong></p>\n", ''], self::cli(['render', '--to', 'html'], "*b*\n"));
        $refused = self::cli(['render', '--to', 'pdf'], 'a');
        $this->assertSame(2, $refused[0]);
        $this->assertStringContainsString('known formats: html, markdown, plain, ansi', $refused[2]);
    }
}

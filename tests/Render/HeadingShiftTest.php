<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Render;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Export\AnsiExporter;
use MarkupCarve\Carve\Export\MarkdownExporter;
use MarkupCarve\Carve\Export\PlainTextExporter;
use MarkupCarve\Carve\Extension\Navigation;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-208, C2-H-209: the clause cases of `headingShift` that the typed render runner does not take, and the engine
 * paths around them.
 */
final class HeadingShiftTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function cases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $case) {
            $number = (int)substr($case['id'], 3);
            if ($number >= 1505 && $number <= 1541 && in_array($case['kind'] ?? null, ['import', 'export', 'cli', 'format'], true)) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    public function testEveryKindIsCovered(): void
    {
        self::assertCount(15, self::cases());
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('cases')]
    public function testClauseCase(array $case): void
    {
        $expected = $case['expected'];
        match ($case['kind']) {
            'import' => $this->import($case, $expected),
            'export' => $this->export($case, $expected),
            'cli' => $this->cli($case, $expected),
            default => $this->refusal($expected, static fn () => Carve::format($case['input'], $case['options'])),
        };
    }

    public function testShiftZeroIsByteIdentical(): void
    {
        $source = "= A\n\n> == B\n\n- === C\n\n====== D\n";
        foreach ([[], ['roundtrip' => true], ['sections' => false], ['profile' => 'comment']] as $options) {
            self::assertSame(Carve::toHtml($source, $options), Carve::toHtml($source, $options + ['headingShift' => 0]));
            self::assertEquals(
                Carve::renderWithDiagnostics($source, $options),
                Carve::renderWithDiagnostics($source, $options + ['headingShift' => 0]),
            );
        }
    }

    public function testTransformsSeeTheShiftedLevels(): void
    {
        $options = ['headingShift' => 1, 'extensions' => [Navigation::headingPermalinks(levels: [2])]];
        $html = Carve::toHtml("= A\n\n== B\n", $options);
        self::assertStringContainsString('<h2>A <a', $html);
        self::assertStringNotContainsString('<h3>B <a', $html);
    }

    public function testEveryRenderEntryShiftsOnce(): void
    {
        $tree = Carve::parse("= A\n");
        $expected = "<section id=\"A\">\n  <h2>A</h2>\n</section>";
        self::assertSame($expected, Carve::toHtml("= A\n", ['headingShift' => 1]));
        self::assertSame($expected, Carve::render($tree, ['headingShift' => 1]));
        self::assertSame($expected, Carve::renderWithDiagnostics($tree, ['headingShift' => 1])->html);
        self::assertSame(1, $tree->children[0]->f['level'], 'the caller tree keeps its level');
    }

    public function testCommandLineMarkdownShiftsAndPlainRefuses(): void
    {
        [$status, $out] = self::command(['render', '--to', 'markdown', '--heading-shift=1', '-'], "= A\n");
        self::assertSame([0, "## A\n"], [$status, $out]);
        [$status, , $err] = self::command(['render', '--to', 'plain', '--heading-shift=1', '-'], "= A\n");
        self::assertSame(2, $status);
        self::assertStringStartsWith('carve[usage]:', $err);
    }

    public function testExportsRefuseABadShift(): void
    {
        $tree = Carve::parse("= A\n");
        foreach ([new PlainTextExporter(), new AnsiExporter()] as $exporter) {
            try {
                $exporter->export($tree, ['headingShift' => 0]);
                self::fail('refused');
            } catch (OptionError $error) {
                self::assertSame('/headingShift', $error->path);
            }
        }
        $this->expectException(OptionError::class);
        (new MarkdownExporter())->export($tree, ['headingShift' => 6]);
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $expected
     */
    private function import(array $case, array $expected): void
    {
        $result = Carve::importHtml($case['html'], $case['options']);
        self::assertSame($expected['carve'], $result->source);
        self::assertSame(Corpus::sorted($expected['children']), Corpus::sorted($result->document['children']));
        $this->codes($expected, $result->codes());
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $expected
     */
    private function export(array $case, array $expected): void
    {
        $exporter = match ($case['to']) {
            'markdown' => new MarkdownExporter(),
            'plain' => new PlainTextExporter(),
            default => new AnsiExporter(),
        };
        $tree = Carve::parse($case['input']);
        if (isset($expected['refusal'])) {
            $this->refusal($expected, static fn () => $exporter->export($tree, $case['options']));

            return;
        }
        $result = $exporter->export($tree, $case['options']);
        self::assertSame($expected['output'], $result->output);
        $this->codes($expected, array_map(static fn ($d): string => $d->code, $result->diagnostics));
        foreach ($expected['diagnostics'] as $item) {
            if (is_array($item) && isset($item['line'])) {
                $entry = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === $item['code']))[0];
                $prefix = substr($case['input'], 0, $entry->startOffset);
                $lineStart = (int)strrpos("\n" . $prefix, "\n");
                self::assertSame([$item['line'], $item['column']], [substr_count($prefix, "\n") + 1, $entry->startOffset - $lineStart + 1]);
            }
        }
    }

    /**
     * @param array<string, mixed> $case
     * @param array<string, mixed> $expected
     */
    private function cli(array $case, array $expected): void
    {
        $directory = sys_get_temp_dir() . '/carve-heading-shift-' . bin2hex(random_bytes(8));
        mkdir($directory);
        $previous = (string)getcwd();
        chdir($directory);
        try {
            foreach ($case['files'] ?? [] as $name => $content) {
                file_put_contents($name, $content['text']);
            }
            [$status, $out, $err] = self::command($case['args'], $case['stdin'] ?? '');
            foreach (array_keys($case['files'] ?? []) as $name) {
                unlink($name);
            }
        } finally {
            chdir($previous);
            rmdir($directory);
        }
        self::assertSame($expected['status'], $status);
        if (isset($expected['stdout'])) {
            self::assertSame($expected['stdout'], $out);
        }
        if (isset($expected['stderrCategory'])) {
            self::assertStringStartsWith('carve[' . $expected['stderrCategory'] . ']:', $err);
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param \Closure(): mixed $call
     */
    private function refusal(array $expected, Closure $call): void
    {
        try {
            $call();
            self::fail('refused');
        } catch (OptionError $error) {
            self::assertSame($expected['refusal']['path'], $error->path);
        }
    }

    /**
     * @param array<string, mixed> $expected
     * @param array<string> $codes
     */
    private function codes(array $expected, array $codes): void
    {
        $want = array_map(static fn ($item): string => is_string($item) ? $item : $item['code'], $expected['diagnostics'] ?? []);
        foreach ($want as $code) {
            self::assertContains($code, $codes);
        }
        if ($expected['diagnosticsComplete'] ?? false) {
            self::assertCount(count($want), $codes);
        }
    }

    /**
     * @param array<string> $args
     * @param string $stdin
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function command(array $args, string $stdin): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $stdin);
        rewind($in);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Exercises the `bin/carve` CLI's output-format flags end to end by piping
 * Carve source on stdin and capturing stdout for each format.
 */
class CliTest extends TestCase
{
    /**
     * @var string
     */
    private const SRC = "# Hi\n\n_em_ *strong* `code`\n";

    /**
     * Run bin/carve with $args, feeding self::SRC on stdin; returns stdout.
     */
    private function runCli(array $args): string
    {
        return $this->runCliInput($args, self::SRC)['out'];
    }

    /**
     * Run bin/carve with $args and a given stdin; returns ['out', 'err', 'exit'].
     *
     * @return array{out: string, err: string, exit: int}
     */
    private function runCliInput(array $args, string $stdin): array
    {
        $bin = dirname(__DIR__, 2) . '/bin/carve';
        $cmd = array_merge([PHP_BINARY, $bin], $args);
        $process = proc_open(
            $cmd,
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], $stdin);
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);
        $exit = proc_close($process);

        return ['out' => (string)$out, 'err' => (string)$err, 'exit' => $exit];
    }

    /**
     * That `--version` prints the constant is this test's whole subject. Whether
     * the constant is the version that actually shipped is ReleaseVersionTest's,
     * which compares it against the NEWEST cut changelog section - the check
     * that used to live here only required the changelog to CONTAIN a section
     * naming the constant, which every past release leaves behind, so a constant
     * lagging the release passed it.
     */
    public function testVersionPrintsTheLibraryVersion(): void
    {
        $result = $this->runCliInput(['--version'], '');

        $this->assertSame(0, $result['exit']);
        $this->assertSame('carve-php version ' . CarveConverter::LIB_VERSION . "\n", $result['out']);
        $this->assertSame('', $result['err']);
    }

    /**
     * The provenance marker `carve fmt --stamp` writes carries LIB_VERSION, so a
     * documented example spelling a different version documents output the tool
     * does not produce. That second home for the value is how the constant sat
     * three releases behind without anyone noticing.
     *
     * EVERY shipped page is read, not just the README. The examples lived in the
     * README when this was written and now live in docs/, and a guard that names
     * one file stops guarding the moment its subject moves - which is exactly
     * what happened: this test failed on the move rather than following it.
     */
    public function testDocumentedStampExamplesCarryTheCurrentVersion(): void
    {
        $root = dirname(__DIR__, 2);
        $pages = array_merge([$root . '/README.md'], glob($root . '/docs/*.md') ?: []);

        $versions = [];
        foreach ($pages as $page) {
            $text = file_get_contents($page);
            $this->assertIsString($text);

            if (preg_match_all('/carve-php (\d+\.\d+\.\d+)/', $text, $matches) > 0) {
                $versions = array_merge($versions, $matches[1]);
            }
        }

        $this->assertNotEmpty(
            $versions,
            'No shipped page documents a stamp version to check.',
        );
        $this->assertSame(
            [CarveConverter::LIB_VERSION],
            array_values(array_unique($versions)),
        );
    }

    public function testRendersHtmlByDefault(): void
    {
        $out = $this->runCli([]);
        $this->assertStringContainsString('<h1>Hi</h1>', $out);
        $this->assertStringContainsString('<strong>strong</strong>', $out);
    }

    public function testRendersMarkdown(): void
    {
        $out = $this->runCli(['--markdown']);
        $this->assertStringContainsString('# Hi', $out);
        $this->assertStringContainsString('**strong**', $out);
        $this->assertStringNotContainsString('<h1>', $out);
    }

    public function testRendersPlainText(): void
    {
        $out = $this->runCli(['--plain']);
        $this->assertStringContainsString('Hi', $out);
        $this->assertStringNotContainsString('<h1>', $out);
        $this->assertStringNotContainsString('**', $out);
    }

    public function testRendersAnsi(): void
    {
        $out = $this->runCli(['--ansi']);
        // An SGR escape sequence was emitted.
        $this->assertStringContainsString("\033[", $out);
    }

    public function testStrictNonHtmlExitsOneNotFatal(): void
    {
        // An error-level warning under --strict --markdown must report and exit
        // 1, not throw an uncaught ParseException (PHP fatal, exit 255).
        $res = $this->runCliInput(['--markdown', '--strict'], "```\nunclosed\n");
        $this->assertSame(1, $res['exit']);
    }

    public function testStrictNonHtmlCleanInputExitsZero(): void
    {
        $res = $this->runCliInput(['--plain', '--strict'], "# ok\n");
        $this->assertSame(0, $res['exit']);
    }

    public function testStampInfoReportsProvenanceAndExitsZero(): void
    {
        $res = $this->runCliInput(['--stamp-info'], "text\n\n%% carve-version: 0.0.9; generated-by: carve-js 0.0.9\n");

        $this->assertSame(0, $res['exit']);
        $this->assertStringContainsString('carve-version: 0.0.9', $res['out']);
        $this->assertStringContainsString('generated-by: carve-js 0.0.9', $res['out']);
    }

    public function testStampInfoSaysSoWhenThereIsNoMarker(): void
    {
        $res = $this->runCliInput(['--stamp-info'], "text\n");

        $this->assertSame(0, $res['exit']);
        $this->assertStringContainsString('unstamped', $res['out']);
    }

    public function testStampCheckFailsForAnOlderOrUnknownDocument(): void
    {
        // Usable as a CI gate over a directory of stored documents.
        $older = $this->runCliInput(['--stamp-check'], "text\n\n%% carve-version: 0.0.9; generated-by: x\n");
        $unstamped = $this->runCliInput(['--stamp-check'], "text\n");

        $this->assertSame(1, $older['exit']);
        $this->assertStringContainsString('[behavior]', $older['err']);
        $this->assertSame(1, $unstamped['exit']);
    }

    public function testStampCheckPassesForACurrentDocument(): void
    {
        $current = "text\n\n%% carve-version: "
            . CarveConverter::SPEC_VERSION
            . "; generated-by: x\n";

        $this->assertSame(0, $this->runCliInput(['--stamp-check'], $current)['exit']);
    }

    public function testLintPlatformReportsGithubAutolinks(): void
    {
        $result = $this->runCliInput(['lint', '--platform', 'github'], "See @param and #42\n");

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('-:1:5 platform-mention-token', $result['out']);
        $this->assertStringContainsString('-:1:16 platform-issue-reference', $result['out']);
    }

    /**
     * The CLI half of the default-off invariant. `carve lint` on the same
     * document that reports two findings with the flag must report none
     * without it, and exit clean.
     */
    public function testLintWithoutPlatformReportsNoHostAutolinks(): void
    {
        $result = $this->runCliInput(['lint'], "See @param and #42\n");

        $this->assertSame(0, $result['exit']);
        $this->assertStringNotContainsString('platform-', $result['out']);
    }

    public function testLintPlatformEqualsFormReportsGithubAutolinks(): void
    {
        $result = $this->runCliInput(['lint', '--platform=github'], "See #42\n");

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('platform-issue-reference', $result['out']);
    }

    /**
     * `carve lint` runs the AST pass too, and interleaves its findings with the
     * source scan's in document order rather than appending one pass after the
     * other.
     */
    public function testLintReportsSemanticAttributeRules(): void
    {
        $result = $this->runCliInput(
            ['lint'],
            "Press [Ctrl]{kbd=\"Control\"} now.\n\nA `c`{kbd} span and **bold**.\n",
        );

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('-:1:7 semantic-attribute-value-ignored', $result['out']);
        $this->assertStringContainsString('-:3:3 semantic-attribute-outside-span', $result['out']);
        $this->assertLessThan(
            strpos($result['out'], 'markdown-strong-asterisks'),
            strpos($result['out'], 'semantic-attribute-outside-span'),
            'findings on one line are ordered by column, across both passes',
        );
    }

    /**
     * The CLI reads a CORE render, so a name the SemanticSpan extension carries
     * is an ordinary attribute here and nothing is reported - and `cite` on a
     * quote is valid HTML, which is the exception the rule must not report.
     */
    public function testLintReadsACoreRenderAndSpareTheValidQuoteAttribute(): void
    {
        $result = $this->runCliInput(
            ['lint'],
            "[x]{cite=\"https://example.org/d\"}\n\n{cite=\"https://example.org/d\"}\n> q\n",
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame('', $result['out']);
    }

    /**
     * `carve lint` runs the retired-spelling pass too, and orders its findings
     * with the others rather than appending the pass at the end.
     */
    public function testLintReportsARetiredSpelling(): void
    {
        $result = $this->runCliInput(['lint'], "|{#x}< content |\n\n**bold**\n");

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('-:1:2 table-cell-attribute-before-marker', $result['out']);
        $this->assertLessThan(
            strpos($result['out'], 'markdown-strong-asterisks'),
            strpos($result['out'], 'table-cell-attribute-before-marker'),
            'findings are ordered by position, across all three passes',
        );
    }

    /**
     * CONTROL. The migration TARGET reports nothing: a block that already sits
     * after the marker run is the spelling the rule points at.
     */
    public function testLintSparesTheMigrationTarget(): void
    {
        $result = $this->runCliInput(['lint'], "|<{#x} content |\n");

        $this->assertSame(0, $result['exit']);
        $this->assertSame('', $result['out']);
    }

    public function testLintRejectsUnknownPlatform(): void
    {
        $result = $this->runCliInput(['lint', '--platform', 'gihub'], "See #42\n");

        $this->assertSame(1, $result['exit']);
        $this->assertSame('', $result['out']);
        $this->assertStringContainsString('--platform', $result['err']);
        $this->assertStringContainsString('github', $result['err']);
    }

    public function testHelpListsFormatFlags(): void
    {
        $out = $this->runCli(['--help']);
        $this->assertStringContainsString('--ansi', $out);
        $this->assertStringContainsString('--markdown', $out);
        $this->assertStringContainsString('--json', $out);
        $this->assertStringContainsString('--from-json', $out);
        $this->assertStringContainsString('--stamp-check', $out);
        $this->assertStringContainsString('--platform', $out);
    }

    public function testJsonEmitsTheEncodedAst(): void
    {
        $out = $this->runCli(['--json']);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($out, true, 512, JSON_THROW_ON_ERROR);

        $this->assertSame('document', $decoded['type']);
        $this->assertArrayNotHasKey('ast', $decoded, 'the reference root carries no envelope (PART 12 §3)');
        $this->assertSame('heading', $decoded['children'][0]['type']);
    }

    public function testFromJsonRendersTheSameHtmlAsTheSource(): void
    {
        // The point of the pair: a tree produced anywhere renders identically to
        // parsing the source it came from.
        $json = $this->runCli(['--json']);

        $viaJson = $this->runCliInput(['--from-json'], $json)['out'];
        $direct = $this->runCli([]);

        $this->assertSame($direct, $viaJson);
    }

    public function testFromJsonFeedsEveryOtherFormat(): void
    {
        $json = $this->runCli(['--json']);

        $this->assertSame(
            $this->runCli(['--markdown']),
            $this->runCliInput(['--from-json', '--markdown'], $json)['out'],
        );
    }

    public function testFromJsonReportsBadInputWithoutAStackTrace(): void
    {
        foreach (
            [
                '{"ast": 99, "type": "document"}' => 'AST encoding version',
                '{not json' => 'Syntax error',
                // PART 12 §12(d): the schema enumerates the vocabulary, so an
                // unlisted type is a schema violation and reaches the user with
                // the way to register one of an application's own.
                '{"type": "document", "srcByteLength": 0, "children": [{"type": "nope"}]}'
                    => 'the schema does not list',
                // §12(d) again, on a type this time rather than a name.
                '{"type": "document", "srcByteLength": 0, "children": [{"type": "paragraph", '
                . '"children": [{"type": "text", "value": 7}]}]}'
                    => 'where the schema requires string',
                // PART 12 §12(a). The refusal has to reach the user as a
                // documented failure, like every other one in this list.
                '{"type": "document", "children": []}' => 'missing `srcByteLength`',
                '{"type": "document", "srcByteLength": 0}' => 'missing `children`',
            ] as $input => $expected
        ) {
            $res = $this->runCliInput(['--from-json'], (string)$input);

            $this->assertSame(1, $res['exit'], 'malformed input must exit 1, not fatal');
            $this->assertStringContainsString($expected, $res['err']);
            $this->assertStringNotContainsString('Stack trace', $res['err']);
        }
    }

    public function testSmartTypographySourceOnHtml(): void
    {
        // The CLI is the machine-facing case the switch exists for, and it is
        // how the spec's optional corpus fixture 29-smart-typography-off is
        // driven against this engine.
        $result = $this->runCliInput(['--html', '--smart-typography', 'source'], "a...b -- c\n");

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertSame('<p>a...b -- c</p>', trim($result['out']));
    }

    public function testSmartTypographySourceOnMarkdown(): void
    {
        $result = $this->runCliInput(['--markdown', '--smart-typography', 'source'], "a...b -- c\n");

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertSame('a...b -- c', trim($result['out']));
    }

    public function testSmartTypographyDefaultsToTheGlyph(): void
    {
        $implicit = $this->runCliInput(['--html'], "a...b -- c\n");
        $explicit = $this->runCliInput(['--html', '--smart-typography', 'glyph'], "a...b -- c\n");

        $this->assertSame($implicit['out'], $explicit['out']);
        $this->assertStringContainsString('…', $implicit['out']);
    }

    public function testSmartTypographyRejectsAnUnknownMode(): void
    {
        // Falling back to the default silently is the failure this switch
        // keeps hitting: output that looks configured and is not.
        $result = $this->runCliInput(['--html', '--smart-typography', 'bogus'], "a...b\n");

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('expected glyph|source', $result['err']);
    }

    public function testSmartTypographyRequiresAMode(): void
    {
        $result = $this->runCliInput(['--html', '--smart-typography'], "a...b\n");

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('requires a mode', $result['err']);
    }

    public function testStructuralMergeJsonEnvelopeAndConflictExit(): void
    {
        $paths = [];
        foreach (["alpha\n\nbeta\n", "alpha\n", "alpha\n\nbeta edited\n"] as $source) {
            $path = tempnam(sys_get_temp_dir(), 'carve-merge-');
            $this->assertIsString($path);
            file_put_contents($path, $source);
            $paths[] = $path;
        }

        try {
            $result = $this->runCliInput(['merge', '--json', ...$paths], '');
        } finally {
            foreach ($paths as $path) {
                unlink($path);
            }
        }

        $this->assertSame(1, $result['exit']);
        $decoded = json_decode($result['out'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertFalse($decoded['ok']);
        $this->assertSame('delete-edit', $decoded['conflicts'][0]['reason']);
        $this->assertTrue($decoded['conflicts'][0]['deleted']['ours']);
    }

    public function testStructuralMergeHelp(): void
    {
        $result = $this->runCliInput(['merge', '--help'], '');

        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('Usage: carve merge', $result['out']);
    }

    /**
     * Every importer the library ships is reachable from `migrate --from`, not
     * just the HTML one the command started out with.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    public static function migrateSourceFormats(): array
    {
        return [
            'html' => ['html', '<p><b>bold</b> and <i>em</i></p>', "*bold* and /em/\n"],
            'markdown' => ['markdown', "**bold** and _em_\n", "*bold* and /em/\n"],
            'markdown short name' => ['md', "**bold** and _em_\n", "*bold* and /em/\n"],
            'djot' => ['djot', "*bold* and _em_\n", "*bold* and /em/\n"],
            'bbcode' => ['bbcode', "[b]bold[/b] and [i]em[/i]\n", "*bold* and /em/\n"],
        ];
    }

    #[DataProvider('migrateSourceFormats')]
    public function testMigrateConvertsEverySupportedSourceFormat(
        string $from,
        string $source,
        string $expected,
    ): void {
        $result = $this->runCliInput(['migrate', '--from', $from], $source);

        $this->assertSame(0, $result['exit']);
        $this->assertSame('', $result['err']);
        $this->assertStringContainsString($expected, $result['out']);
    }

    public function testMigrateRejectsAnUnknownSourceFormat(): void
    {
        $result = $this->runCliInput(['migrate', '--from', 'rst'], "hi\n");

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('unknown source format rst', $result['err']);
    }

    public function testMigrateNamesEverySourceFormatWhenFromIsMissing(): void
    {
        $result = $this->runCliInput(['migrate'], "hi\n");

        $this->assertSame(2, $result['exit']);
        $this->assertStringContainsString('html, markdown, djot or bbcode', $result['err']);
    }

    /**
     * The help's HTML import vocabulary and the one the CLI accepts are the
     * same set.
     *
     * Both halves are measured rather than written down: the advertised names
     * come out of `--help`, the accepted ones out of running each candidate.
     * So the assertion compares two readings of the running CLI and cannot
     * pass on a mode name that only exists in the help text.
     */
    public function testHelpAdvertisesExactlyTheHtmlImportModesTheCliAccepts(): void
    {
        $help = $this->runCli(['--help']);
        $this->assertSame(
            1,
            preg_match('/^  migrate --mode MODE\s+(.*)$/m', $help, $matches),
            'the help documents migrate --mode',
        );

        $advertised = [];
        $accepted = [];
        foreach (['safe', 'semantic', 'roundtrip', 'raw'] as $mode) {
            if (preg_match('/\b' . $mode . '\b/', $matches[1]) === 1) {
                $advertised[] = $mode;
            }
            $result = $this->runCliInput(
                ['migrate', '--from', 'html', '--mode', $mode],
                "<p>hi</p>\n",
            );
            if ($result['exit'] === 0) {
                $accepted[] = $mode;
            }
        }

        $this->assertSame(['safe', 'semantic', 'roundtrip'], $accepted);
        $this->assertSame($accepted, $advertised);
    }

    /**
     * The loss report is the HTML importer's alone - the other three parse
     * their source whole - so a non-HTML migration ignores it rather than
     * failing on it.
     */
    public function testMigrateIgnoresTheHtmlOnlyOptionsForOtherFormats(): void
    {
        $result = $this->runCliInput(
            ['migrate', '--from', 'markdown', '--mode', 'raw', '--check-loss'],
            "**bold**\n",
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame("*bold*\n", $result['out']);
    }

    public function testRenderLossWarningDoesNotContaminateStdout(): void
    {
        $result = $this->runCliInput(['--html'], "`x`{=latex}\n");

        $this->assertSame(0, $result['exit']);
        $this->assertSame("<p></p>\n", $result['out']);
        $this->assertStringContainsString('<stdin>:1:1 raw-format-dropped', $result['err']);
    }

    public function testStrictRenderLossRefusesOutput(): void
    {
        $result = $this->runCliInput(['--strict-losses'], "`x`{=latex}\n");

        $this->assertSame(1, $result['exit']);
        $this->assertSame('', $result['out']);
    }

    public function testAllowedRenderLossIsExplicitAndSilent(): void
    {
        $result = $this->runCliInput(
            ['--strict-losses', '--allow-loss', 'raw-format-dropped'],
            "`x`{=latex}\n",
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame('', $result['err']);
        $this->assertSame("<p></p>\n", $result['out']);
    }
}

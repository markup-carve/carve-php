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

    public function testAlignedTableFormattingAndCheck(): void
    {
        $canonical = "| a | bb |\n| ccc | d |\n";
        $aligned = "| a   | bb |\n| ccc | d  |\n";
        $this->assertSame($aligned, $this->runCliInput(['fmt', '--align-tables'], $canonical)['out']);
        $path = tempnam(sys_get_temp_dir(), 'carve-align-');
        $this->assertNotFalse($path);
        try {
            file_put_contents($path, $canonical);
            $this->assertSame(1, $this->runCliInput(['fmt', '--align-tables', '--check', $path], '')['exit']);
            file_put_contents($path, $aligned);
            $this->assertSame(0, $this->runCliInput(['fmt', '--align-tables', '--check', $path], '')['exit']);
            $this->assertSame(1, $this->runCliInput(['fmt', '--check', $path], '')['exit']);
        } finally {
            unlink($path);
        }
    }

    public function testRoundTripPreservesMarkdownContainers(): void
    {
        $result = $this->runCliInput(['--markdown', '--roundtrip'], "::: aside\nText.\n:::\n");
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('<!-- carve: ::: aside -->', $result['out']);
    }

    public function testCarryMarkersIsNotAnOption(): void
    {
        $result = $this->runCliInput(['--markdown', '--carry-markers'], self::SRC);

        $this->assertSame(1, $result['exit']);
        $this->assertStringContainsString('Unknown option: --carry-markers', $result['err']);
    }

    public function testRoundTripRejectsPlainOutput(): void
    {
        $result = $this->runCliInput(['--plain', '--roundtrip'], self::SRC);
        $this->assertSame(1, $result['exit']);
        $this->assertSame("Error: --roundtrip applies to --html or --markdown output only\n", $result['err']);
    }

    public function testRoundTripPreservesHtmlBreakCharacters(): void
    {
        $result = $this->runCliInput(['--roundtrip'], "***\n");
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('data-char="*"', $result['out']);
    }

    public function testAnUnspellableAstReportsAnErrorWithoutAStackTrace(): void
    {
        $ast = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table', 'rows' => [
                        ['type' => 'table_row', 'cells' => []],
                        [
                            'type' => 'table_row', 'cells' => [
                                ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'a']]],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $result = $this->runCliInput(['--from-json', '--carve'], json_encode($ast, JSON_THROW_ON_ERROR));
        $this->assertSame(1, $result['exit']);
        $this->assertSame('', $result['out']);
        $this->assertStringContainsString('cannot spell table_row', $result['err']);
        $this->assertStringNotContainsString('Stack trace', $result['err']);
        $this->assertStringNotContainsString('Fatal error', $result['err']);
    }

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

    public function testADocumentThatRendersToNothingStillEndsInOneNewline(): void
    {
        // carve-js and carve-rs both do this, and the difference is not
        // cosmetic: a tool that emits zero bytes leaves the next shell prompt
        // glued to the command, and a fixture comparison differed by a byte
        // depending on which engine produced it (#1940).
        $result = $this->runCliInput([], "%% comment only\n");

        $this->assertSame("\n", $result['out']);
        $this->assertSame(0, $result['exit']);
    }

    public function testAnEmptyInputAlsoEndsInOneNewline(): void
    {
        $result = $this->runCliInput([], '');

        $this->assertSame("\n", $result['out']);
    }

    public function testOrdinaryOutputKeepsExactlyOneTrailingNewline(): void
    {
        // The other half of the rule: the newline is ENSURED, not appended, so
        // output that already ends in one does not gain a second.
        $result = $this->runCliInput([], "x\n");

        $this->assertSame("<p>x</p>\n", $result['out']);
    }

    /**
     * That `--version` prints the constant is this test's whole subject. Whether
     * the constant is the version that actually shipped is ReleaseVersionTest's,
     * which compares it against the NEWEST cut changelog section - the check
     * that used to live here only required the changelog to CONTAIN a section
     * naming the constant, which every past release leaves behind, so a constant
     * lagging the release passed it.
     */
    #[DataProvider('versionFlags')]
    public function testVersionPrintsTheLibraryVersion(string $flag): void
    {
        $result = $this->runCliInput([$flag], '');

        $this->assertSame(0, $result['exit']);
        $this->assertSame('carve-php ' . CarveConverter::LIB_VERSION . "\n", $result['out']);
        $this->assertSame('', $result['err']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function versionFlags(): array
    {
        return [
            'long' => ['--version'],
            'short' => ['-V'],
        ];
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
        // Between releases LIB_VERSION is `X.Y.Z-dev`; the docs show the release.
        preg_match('/^## \[(\d+\.\d+\.\d+)\]/m', (string)file_get_contents($root . '/CHANGELOG.md'), $released);
        $this->assertSame(
            [$released[1] ?? null],
            array_values(array_unique($versions)),
        );
    }

    public function testRendersHtmlByDefault(): void
    {
        $out = $this->runCli([]);
        $this->assertStringContainsString('<h1>Hi</h1>', $out);
        $this->assertStringContainsString('<strong>strong</strong>', $out);
    }

    public function testLintChecksReferencesPlacementOnlyWhenCitationsAreSelected(): void
    {
        $source = "See [@x].\n\n> ::: references\n> :::\n\n[@x]: Source\n";
        $plain = $this->runCliInput(['lint'], $source);
        $this->assertSame(0, $plain['exit']);
        $this->assertSame('', $plain['out']);

        $selected = $this->runCliInput(['lint', '--extension', 'citations'], $source);
        $this->assertSame(1, $selected['exit']);
        $this->assertStringContainsString('references-placement-in-container', $selected['out']);
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
            strpos($result['out'], 'markdown-strong-double-star'),
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
            strpos($result['out'], 'markdown-strong-double-star'),
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

    public function testSmartTypographyQuotesSourceOnEveryTarget(): void
    {
        foreach (['--html', '--markdown', '--plain', '--ansi'] as $target) {
            $result = $this->runCliInput([$target, '--smart-typography', 'quotes-source'], "He said \"hi\"... a--b\n");
            $this->assertSame(0, $result['exit'], $result['err']);
            $this->assertStringContainsString('He said "hi"… a–b', $result['out']);
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

    public function testMigrateMarkdownDirectoryCopiesAssetsAndReportsLoss(): void
    {
        $root = sys_get_temp_dir() . '/carve-folder-' . bin2hex(random_bytes(8));
        $source = $root . '/source';
        $output = $root . '/output';
        mkdir($source . '/nested', 0777, true);
        file_put_contents($source . '/nested/clean.md', "# Clean\n");
        file_put_contents($source . '/loss.markdown', "A footnote[^a].\n\n[^a]: Note.\n");
        file_put_contents($source . '/asset.svg', '<svg/>');
        file_put_contents($source . '/404', 'asset');
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $output, '--check-loss'], '');
            $this->assertSame(1, $result['exit'], $result['err']);
            $this->assertStringContainsString('# Clean', (string)file_get_contents($output . '/nested/clean.crv'));
            $this->assertSame('<svg/>', file_get_contents($output . '/asset.svg'));
            $report = json_decode((string)file_get_contents($output . '/migration-report.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertCount(2, $report['files']);
            $this->assertSame(['loss.markdown'], $report['review']);
            $this->assertContains('404', $report['assets']);
            $existing = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $output], '');
            $this->assertSame(2, $existing['exit']);
            $this->assertFileExists($output . '/nested/clean.crv');
            $nested = $source . '/new/out';
            $inside = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $nested], '');
            $this->assertSame(2, $inside['exit']);
            $this->assertDirectoryDoesNotExist($source . '/new');
            $missingTraversal = $root . '/zzz/../source/new/out';
            $inside = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $missingTraversal], '');
            $this->assertSame(2, $inside['exit']);
            $this->assertDirectoryDoesNotExist($source . '/new');
            $traversal = $source . '/../source/new/out';
            $inside = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $traversal], '');
            $this->assertSame(2, $inside['exit']);
            $this->assertDirectoryDoesNotExist($source . '/new');
            if (PHP_OS_FAMILY !== 'Windows') {
                $alias = $root . '/alias';
                symlink($source, $alias);
                $inside = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $alias . '/new/out'], '');
                $this->assertSame(2, $inside['exit']);
                $this->assertDirectoryDoesNotExist($source . '/new');
                unlink($alias);
            }
            file_put_contents($source . '/migration-report.json', 'source');
            $collision = $root . '/collision';
            $reportCollision = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $collision], '');
            $this->assertSame(2, $reportCollision['exit']);
            $this->assertDirectoryDoesNotExist($collision);
        } finally {
            if (is_link($root . '/alias')) {
                unlink($root . '/alias');
            }
            foreach (['output/nested/clean.crv', 'output/migration-report.json', 'output/loss.crv', 'output/asset.svg', 'output/404', 'source/nested/clean.md', 'source/loss.markdown', 'source/asset.svg', 'source/404', 'source/migration-report.json'] as $path) {
                if (file_exists($root . '/' . $path)) {
                    unlink($root . '/' . $path);
                }
            }
            foreach (['output/nested', 'output', 'source/nested', 'source', ''] as $path) {
                if (is_dir($root . '/' . $path)) {
                    rmdir($root . '/' . $path);
                }
            }
        }
    }

    public function testMigrateMarkdownDirectoryChecksLinksAndFragments(): void
    {
        $root = sys_get_temp_dir() . '/carve-links-' . bin2hex(random_bytes(8));
        $source = $root . '/source';
        $output = $root . '/output';
        mkdir($source . '/chapters', 0777, true);
        file_put_contents($source . '/index.md', "# Home\n\n[good](chapters/intro.md#Intro) [self](#Home) [query](?view=1) [encoded](chapters/a%23b.md#A) [bad](chapters/intro.md#intro) [gone](missing.md) [route](/docs/intro) ![image](lost.png) [asset](manual.pdf#page=2)\n");
        file_put_contents($source . '/chapters/intro.md', "# Intro\n\n[home](../index.md#Home)\n");
        file_put_contents($source . '/chapters/a#b.md', "# A\n");
        file_put_contents($source . '/manual.pdf', 'pdf');
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $output, '--check-loss'], '');
            $this->assertSame(1, $result['exit'], $result['err']);
            $this->assertStringContainsString('(chapters/intro.crv#Intro)', (string)file_get_contents($output . '/index.crv'));
            $this->assertStringContainsString('(chapters/a%23b.crv#A)', (string)file_get_contents($output . '/index.crv'));
            $this->assertStringContainsString('(../index.crv#Home)', (string)file_get_contents($output . '/chapters/intro.crv'));
            $report = json_decode((string)file_get_contents($output . '/migration-report.json'), true, flags: JSON_THROW_ON_ERROR);
            $this->assertSame(['index.md'], $report['review']);
            $this->assertContains(['target' => 'lost.png', 'status' => 'unresolved', 'reason' => 'target missing from output'], $report['files'][2]['links']);
            $this->assertContains(['target' => '#Home', 'status' => 'valid'], $report['files'][2]['links']);
            $this->assertContains(['target' => '?view=1', 'status' => 'valid'], $report['files'][2]['links']);
            $this->assertContains(['target' => 'manual.pdf#page=2', 'status' => 'unresolved', 'reason' => 'asset fragment needs site check'], $report['files'][2]['links']);
        } finally {
            foreach (['output/chapters/intro.crv', 'output/chapters/a#b.crv', 'output/index.crv', 'output/manual.pdf', 'output/migration-report.json', 'source/chapters/intro.md', 'source/chapters/a#b.md', 'source/index.md', 'source/manual.pdf'] as $path) {
                if (is_file($root . '/' . $path)) {
                    unlink($root . '/' . $path);
                }
            }
            foreach (['output/chapters', 'output', 'source/chapters', 'source', ''] as $path) {
                if (is_dir($root . '/' . $path)) {
                    rmdir($root . '/' . $path);
                }
            }
        }
    }

    public function testMigrateMarkdownDirectoryDoesNotTreatDataIdAsAnAnchor(): void
    {
        $root = sys_get_temp_dir() . '/carve-link-id-' . bin2hex(random_bytes(8));
        $source = $root . '/source';
        $output = $root . '/output';
        mkdir($source, 0777, true);
        file_put_contents($source . '/page.md', "# Page\n\n[false anchor](other.md#shadow) [false code](other.md#ghost) [legacy](other.md#legacy)\n\n<a href=\" other.md \">raw</a>\n\n<img src=\" chart.png \" srcset=\"missing-responsive.png 2x, chart,v2.png 1x\">\n\n<link rel=\"preload\" as=\"image\" imagesrcset=\"chart,v2.png 2x\">\n\n<video poster=\"missing-poster.png\"></video>\n");
        file_put_contents($source . '/other.md', "# Other\n\n<div data-id=\"shadow\">Text</div>\n\n`id=\"ghost\"`\n\n<a name=\"legacy\"></a>\n");
        file_put_contents($source . '/chart.png', 'png');
        file_put_contents($source . '/chart,v2.png', 'png');
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $output], '');
            $this->assertSame(0, $result['exit'], $result['err']);
            $report = json_decode((string)file_get_contents($output . '/migration-report.json'), true, flags: JSON_THROW_ON_ERROR);
            $page = array_values(array_filter($report['files'], static fn (array $file): bool => $file['source'] === 'page.md'))[0];
            $this->assertContains(['target' => 'other.md#shadow', 'status' => 'unresolved', 'reason' => 'fragment missing from target'], $page['links']);
            $this->assertContains(['target' => 'other.md#ghost', 'status' => 'unresolved', 'reason' => 'fragment missing from target'], $page['links']);
            $this->assertContains(['target' => 'other.md#legacy', 'status' => 'rewritten'], $page['links']);
            $this->assertContains(['target' => 'other.md', 'status' => 'unresolved', 'reason' => 'raw HTML link needs manual rewrite'], $page['links']);
            $this->assertContains(['target' => 'chart.png', 'status' => 'valid'], $page['links']);
            $this->assertCount(2, array_filter($page['links'], static fn (array $link): bool => $link['target'] === 'chart,v2.png' && $link['status'] === 'valid'));
            $this->assertContains(['target' => 'missing-responsive.png', 'status' => 'unresolved', 'reason' => 'target missing from output'], $page['links']);
            $this->assertContains(['target' => 'missing-poster.png', 'status' => 'unresolved', 'reason' => 'target missing from output'], $page['links']);
        } finally {
            foreach (['output/page.crv', 'output/other.crv', 'output/chart.png', 'output/chart,v2.png', 'output/migration-report.json', 'source/page.md', 'source/other.md', 'source/chart.png', 'source/chart,v2.png'] as $path) {
                if (is_file($root . '/' . $path)) {
                    unlink($root . '/' . $path);
                }
            }
            foreach (['output', 'source', ''] as $path) {
                if (is_dir($root . '/' . $path)) {
                    rmdir($root . '/' . $path);
                }
            }
        }
    }

    public function testMigrateMarkdownDirectoryKeepsSourcePermissions(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions only.');
        }
        $root = sys_get_temp_dir() . '/carve-folder-mode-' . bin2hex(random_bytes(8));
        $source = $root . '/source';
        $output = $root . '/output';
        mkdir($source, 0777, true);
        file_put_contents($source . '/private.md', "# Private\n");
        file_put_contents($source . '/public.md', "# Public\n");
        file_put_contents($source . '/secret.png', 'png');
        file_put_contents($source . '/tool.sh', 'sh');
        chmod($source . '/private.md', 0600);
        chmod($source . '/public.md', 0644);
        chmod($source . '/secret.png', 0600);
        chmod($source . '/tool.sh', 0755);
        $previousUmask = umask(0022);
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $source, '--output', $output], '');
            $this->assertSame(0, $result['exit'], $result['err']);
            clearstatcache();
            $this->assertSame(0600, fileperms($output . '/private.crv') & 0777);
            $this->assertSame(0644, fileperms($output . '/public.crv') & 0777);
            $this->assertSame(0600, fileperms($output . '/secret.png') & 0777);
            $this->assertSame(0755, fileperms($output . '/tool.sh') & 0777);
            $this->assertSame(['output', 'source'], array_values(array_diff((array)scandir($root), ['.', '..'])));
        } finally {
            umask($previousUmask);
            foreach (['output/private.crv', 'output/public.crv', 'output/secret.png', 'output/tool.sh', 'output/migration-report.json', 'source/private.md', 'source/public.md', 'source/secret.png', 'source/tool.sh'] as $path) {
                if (file_exists($root . '/' . $path)) {
                    unlink($root . '/' . $path);
                }
            }
            foreach (['output', 'source', ''] as $path) {
                if (is_dir($root . '/' . $path)) {
                    rmdir($root . '/' . $path);
                }
            }
        }
    }

    /**
     * @return array<string, array{string}>
     */
    public static function illFormedMigrateFormats(): array
    {
        return ['html' => ['html'], 'markdown' => ['markdown'], 'djot' => ['djot'], 'bbcode' => ['bbcode']];
    }

    #[DataProvider('illFormedMigrateFormats')]
    public function testMigrateReplacesIllFormedBytes(string $format): void
    {
        $result = $this->runCliInput(['migrate', '--from', $format], "a\xED\xA0\x80b\n");

        $this->assertSame(0, $result['exit'], $result['err']);
        $this->assertSame(1, preg_match('//u', $result['out']));
        $this->assertStringContainsString("a\u{FFFD}\u{FFFD}\u{FFFD}b", $result['out']);
    }

    public function testMigrateMarkdownDirectoryReplacesIllFormedBytes(): void
    {
        $root = sys_get_temp_dir() . '/carve-folder-utf8-' . bin2hex(random_bytes(8));
        mkdir($root . '/source', 0777, true);
        file_put_contents($root . '/source/bad.md', "a\xC3b\n");
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $root . '/source', '--output', $root . '/output'], '');
            $this->assertSame(0, $result['exit'], $result['err']);
            $this->assertSame("a\u{FFFD}b\n", file_get_contents($root . '/output/bad.crv'));
        } finally {
            foreach (['output/bad.crv', 'output/migration-report.json', 'source/bad.md'] as $path) {
                if (file_exists($root . '/' . $path)) {
                    unlink($root . '/' . $path);
                }
            }
            foreach (['output', 'source', ''] as $path) {
                if (is_dir($root . '/' . $path)) {
                    rmdir($root . '/' . $path);
                }
            }
        }
    }

    public function testMigrateMarkdownDirectoryNamesAnUnreadableFile(): void
    {
        if (PHP_OS_FAMILY === 'Windows' || (function_exists('posix_geteuid') && posix_geteuid() === 0)) {
            $this->markTestSkipped('Needs POSIX permissions that bind the current user.');
        }
        $root = sys_get_temp_dir() . '/carve-folder-unreadable-' . bin2hex(random_bytes(8));
        mkdir($root . '/source', 0777, true);
        file_put_contents($root . '/source/locked.md', "# Locked\n");
        chmod($root . '/source/locked.md', 0000);
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $root . '/source', '--output', $root . '/output'], '');
            $this->assertSame(2, $result['exit']);
            $this->assertStringContainsString('cannot read locked.md', $result['err']);
            $this->assertDirectoryDoesNotExist($root . '/output');
            $this->assertSame(['source'], array_values(array_diff((array)scandir($root), ['.', '..'])));
        } finally {
            chmod($root . '/source/locked.md', 0600);
            unlink($root . '/source/locked.md');
            rmdir($root . '/source');
            rmdir($root);
        }
    }

    public function testMigrateMarkdownDirectoryRewritesALinkInAReadOnlySource(): void
    {
        if (PHP_OS_FAMILY === 'Windows') {
            $this->markTestSkipped('POSIX permissions only.');
        }
        $root = sys_get_temp_dir() . '/carve-folder-readonly-' . bin2hex(random_bytes(8));
        mkdir($root . '/source', 0777, true);
        file_put_contents($root . '/source/index.md', "# Home\n\n[link](target.md)\n");
        file_put_contents($root . '/source/target.md', "# Target\n");
        chmod($root . '/source/index.md', 0444);
        try {
            $result = $this->runCliInput(['migrate', '--from', 'markdown', '--directory', $root . '/source', '--output', $root . '/output'], '');
            $this->assertSame(0, $result['exit'], $result['err']);
            $this->assertStringContainsString('[link](target.crv)', (string)file_get_contents($root . '/output/index.crv'));
            clearstatcache();
            $this->assertSame(0444, fileperms($root . '/output/index.crv') & 0777);
        } finally {
            foreach (['output/index.crv', 'output/target.crv', 'output/migration-report.json', 'source/index.md', 'source/target.md'] as $path) {
                if (file_exists($root . '/' . $path)) {
                    chmod($root . '/' . $path, 0600);
                    unlink($root . '/' . $path);
                }
            }
            foreach (['output', 'source', ''] as $path) {
                if (is_dir($root . '/' . $path)) {
                    rmdir($root . '/' . $path);
                }
            }
        }
    }

    public function testMigrateWritesAListTableUnderTheListTableFlag(): void
    {
        $html = '<table><tr><td><p>a</p><p>b</p></td></tr></table>';
        $result = $this->runCliInput(['migrate', '--from', 'html', '--list-table'], $html);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("::: list-table\n- - a\n\n    b\n:::\n", $result['out']);
    }

    /**
     * @return array<string, array{list<string>, string}>
     */
    public static function fmtCaseMigration(): array
    {
        return [
            'without --migrate the spelling stays' => [['fmt'], "# Plan\n\nSee </#plan> and [plan][].\n"],
            'with --migrate it takes the target spelling' => [['fmt', '--migrate'], "# Plan\n\nSee </#Plan> and [Plan][].\n"],
        ];
    }

    /**
     * @param list<string> $args
     * @param string $expected
     */
    #[DataProvider('fmtCaseMigration')]
    public function testFmtMigrateRespellsACaseOnlyReference(array $args, string $expected): void
    {
        $result = $this->runCliInput($args, "# Plan\n\nSee </#plan> and [plan][].\n");

        $this->assertSame(0, $result['exit']);
        $this->assertSame($expected, $result['out']);
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

    public function testMigrateFailsClosedForAnUnverifiedImporter(): void
    {
        $result = $this->runCliInput(
            ['migrate', '--from', 'markdown', '--mode', 'raw', '--check-loss'],
            "<code>http://example.org</code>\n",
        );

        $this->assertSame(1, $result['exit']);
        $this->assertSame("`<code>`{=html}[http://example.org](http://example.org)`</code>`{=html}\n", $result['out']);
    }

    public function testMigrateWritesTheVersionedReportForEveryImporter(): void
    {
        $result = $this->runCliInput(
            ['migrate', '--from', 'markdown', '--report', '-'],
            "**bold**\n",
        );

        $this->assertSame(0, $result['exit']);
        $report = json_decode($result['err'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame(2, $report['schemaVersion']);
        $this->assertSame('markdown', $report['sourceFormat']);
        $this->assertSame('preserved', $report['diagnostics'][0]['fidelity']);
    }

    public function testMigrateLossCheckUsesLiteralTextEvidence(): void
    {
        foreach (['markdown', 'djot', 'bbcode'] as $format) {
            foreach ([['hello', 0], ['<code>http://example.org</code>', 1]] as [$source, $expected]) {
                $result = $this->runCliInput(['migrate', '--from', $format, '--check-loss', '--report', '-'], $source);
                $this->assertSame($expected, $result['exit']);
                $report = json_decode($result['err'], true, flags: JSON_THROW_ON_ERROR);
                $this->assertSame($expected === 0 ? 'literal-text-verified' : 'fidelity-unverified', $report['diagnostics'][0]['code']);
            }
        }
    }

    public function testMigrateReportsDroppedBbcodeQuoteUserId(): void
    {
        $result = $this->runCliInput(
            ['migrate', '--from', 'bbcode', '--report', '-'],
            '[quote="alice" post_id=12 user_id=3]q[/quote]',
        );
        $this->assertSame(0, $result['exit']);
        $this->assertStringContainsString('^ alice #12', $result['out']);
        $report = json_decode($result['err'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertSame('bbcode-quote-field-dropped', $report['diagnostics'][1]['code']);
        $this->assertSame('line:1', $report['diagnostics'][1]['path']);
    }

    public function testMigrateReportsHtmlDiagnosticTruncationAsLoss(): void
    {
        $source = str_repeat('<p onclick="x()">x</p>', 1100);
        $reportPath = tempnam(sys_get_temp_dir(), 'carve-report-');
        $this->assertNotFalse($reportPath);
        try {
            $result = $this->runCliInput(['migrate', '--from', 'html', '--check-loss', '--report', $reportPath], $source);
            $reportJson = file_get_contents($reportPath);
        } finally {
            unlink($reportPath);
        }

        $this->assertSame(1, $result['exit']);
        $this->assertNotSame('', $result['out']);
        $this->assertNotFalse($reportJson);
        $report = json_decode($reportJson, true, flags: JSON_THROW_ON_ERROR);
        $this->assertCount(1000, $report['diagnostics']);
        $this->assertSame('diagnostics-truncated', $report['diagnostics'][999]['code']);
        $this->assertSame('dropped', $report['diagnostics'][999]['fidelity']);
    }

    public function testMigrateLossCheckFailsForOpaquePreservedHtml(): void
    {
        $result = $this->runCliInput(
            ['migrate', '--from', 'html', '--mode', 'roundtrip', '--check-loss', '--report', '-'],
            '<fieldset id="f"><p>a</p></fieldset>',
        );

        $this->assertSame(1, $result['exit']);
        $report = json_decode($result['err'], true, flags: JSON_THROW_ON_ERROR);
        $this->assertNotSame([], $report['diagnostics']);
        foreach ($report['diagnostics'] as $diagnostic) {
            $this->assertContains($diagnostic['fidelity'], ['preserved', 'degraded']);
        }
        $this->assertContains('degraded', array_column($report['diagnostics'], 'fidelity'));
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

    public function testIngestedCitationGroupFallsBackInStrictMode(): void
    {
        $json = '{"type":"document","srcByteLength":0,"children":[{"type":"paragraph","children":[{"type":"citation_group","raw":"[@x]","items":[{"type":"citation","key":"x","suppressAuthor":false}]}]}]}';
        $result = $this->runCliInput(['--from-json', '--strict-losses'], $json);

        $this->assertSame(0, $result['exit']);
        $this->assertSame("<p>[@x]</p>\n", $result['out']);
        $this->assertSame('', $result['err']);
    }

    public function testConversionDiagnosticsAreSeparateFromRenderLosses(): void
    {
        $ast = '{"type":"document","srcByteLength":0,"children":[{"type":"section","children":['
            . '{"type":"paragraph","children":[{"type":"text","value":"Part"}]}]}]}';
        $result = $this->runCliInput(
            ['--from-json', '--carve', '--report-conversion-diagnostics', '-', '--max-conversion-diagnostics', '0'],
            $ast,
        );

        $this->assertSame(0, $result['exit']);
        $this->assertSame("Part\n", $result['out']);
        $report = json_decode($result['err'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame([], $report['diagnostics']);
        $this->assertSame(1, $report['totalDiagnostics']);
        $this->assertTrue($report['truncated']);

        $wrongTarget = $this->runCliInput(['--from-json', '--html', '--report-conversion-diagnostics', '-'], $ast);
        $this->assertSame(2, $wrongTarget['exit']);
        $this->assertSame('', $wrongTarget['out']);
    }

    public function testStrictLossesRefusesEachMarkdownHeaderLossUnlessItIsAllowed(): void
    {
        foreach (["| A | B |\n|= C |= D |\n" => 'table-header-row-hoisted', "| A |= B |\n| C | D |\n" => 'table-header-cell-demoted'] as $source => $code) {
            $strict = $this->runCliInput(['--markdown', '--strict-losses'], $source);
            $this->assertSame(1, $strict['exit'], $code);
            $this->assertSame('', $strict['out'], $code);
            $this->assertStringContainsString($code, $strict['err']);
            $allowed = $this->runCliInput(['--markdown', '--strict-losses', '--allow-loss', $code], $source);
            $this->assertSame(0, $allowed['exit'], $code);
            $this->assertNotSame('', $allowed['out'], $code);
        }
        $this->assertSame(0, $this->runCliInput(['--markdown', '--strict-losses'], "|= A |= B |\n| C | D |\n")['exit']);
    }

    /**
     * `--allow-loss` gates the `CARVE-P2-024` report and grows no name for a
     * conversion-diagnostics code (PART 11 §1d), so the dropped field passes
     * `--strict-losses` with nothing allowed and the old code is refused outright.
     */
    public function testTableSectionAttributesAreNoRenderLossAndTheirOldCodeIsRefused(): void
    {
        $ast = '{"type":"document","srcByteLength":0,"children":[{"type":"table","rows":[],"rowGroups":{"headRows":0,"footRows":0,"bodies":[],"headAttrs":{"id":"head"}}}]}';
        $strict = $this->runCliInput(['--from-json', '--plain', '--strict-losses', '--max-render-losses', '0'], $ast);
        $refused = $this->runCliInput(['--from-json', '--plain', '--allow-loss', 'table-section-attributes-dropped'], $ast);

        $this->assertSame(0, $strict['exit']);
        $this->assertSame('', $strict['err']);
        $this->assertSame(2, $refused['exit']);
        $this->assertStringContainsString(
            '--allow-loss expects raw-format-dropped, ruby-flattened, editorial-comment-flattened'
            . ', carrier-attribute-dropped, list-style-dropped, list-delimiter-changed'
            . ', table-span-flattened, table-header-row-demoted, table-header-row-hoisted or table-header-cell-demoted',
            $refused['err'],
        );
    }

    public function testATextTargetWritesTheDroppedSectionFieldToTheDiagnosticsChannel(): void
    {
        $ast = '{"type":"document","srcByteLength":0,"children":[{"type":"table","rows":[],"rowGroups":{"headRows":0,"footRows":0,"bodies":[],"headAttrs":{"id":"head"}}}]}';
        $result = $this->runCliInput(['--from-json', '--plain', '--report-conversion-diagnostics', '-'], $ast);

        $this->assertSame(0, $result['exit']);
        $report = json_decode($result['err'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame(1, $report['totalDiagnostics']);
        $this->assertSame(
            ['code' => 'field-unspellable', 'node' => 'table', 'message' => 'Carve source cannot spell table section attributes', 'field' => 'rowGroups.headAttrs'],
            $report['diagnostics'][0],
        );
    }

    public function testRubyLossCanBeAllowedWithAZeroRowLimit(): void
    {
        $ast = json_encode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'paragraph',
                    'children' => [
                        [
                            'type' => 'ruby',
                            'pairs' => [['base' => [['type' => 'text', 'value' => 'x']], 'annotation' => [['type' => 'text', 'value' => 'a']]]],
                        ],
                    ],
                ],
            ],
        ], JSON_THROW_ON_ERROR);
        $denied = $this->runCliInput(['--from-json', '--plain', '--strict-losses', '--max-render-losses', '0'], $ast);
        $allowed = $this->runCliInput(['--from-json', '--plain', '--strict-losses', '--max-render-losses', '0', '--allow-loss', 'ruby-flattened'], $ast);

        $this->assertSame(1, $denied['exit']);
        $this->assertSame('', $denied['out']);
        $this->assertSame(0, $allowed['exit']);
        $this->assertSame("x(a)\n", $allowed['out']);
        $this->assertSame('', $allowed['err']);
    }

    public function testEditorialCommentLossCanBeAllowed(): void
    {
        $denied = $this->runCliInput(['--plain', '--strict-losses'], "a {#b#} c\n");
        $allowed = $this->runCliInput(['--plain', '--strict-losses', '--allow-loss', 'editorial-comment-flattened'], "a {#b#} c\n");

        $this->assertSame(1, $denied['exit']);
        $this->assertSame('', $denied['out']);
        $this->assertSame(0, $allowed['exit']);
        $this->assertSame("a b c\n", $allowed['out']);
        $this->assertSame('', $allowed['err']);
    }

    public function testNestedDefinitionListCarriesNoLoss(): void
    {
        $source = ":: Outer\n: body\n\n  :: Inner\n  : inner body\n";
        $allowed = $this->runCliInput(['--markdown', '--roundtrip', '--strict-losses'], $source);
        $invalid = $this->runCliInput(['--markdown', '--allow-loss', 'destination-denied'], $source);

        $this->assertSame(0, $allowed['exit']);
        $this->assertNotSame('', $allowed['out']);
        $this->assertSame(2, $invalid['exit']);
    }

    public function testListStyleLossCanBeAllowed(): void
    {
        $source = "a. one\n";
        $denied = $this->runCliInput(['--markdown', '--strict-losses'], $source);
        $allowed = $this->runCliInput(['--markdown', '--strict-losses', '--allow-loss', 'list-style-dropped'], $source);

        $this->assertSame(1, $denied['exit']);
        $this->assertSame(0, $allowed['exit']);
        $this->assertSame("1. one\n", $allowed['out']);
        $this->assertSame('', $allowed['err']);
    }

    public function testListDelimiterLossCanBeAllowed(): void
    {
        $source = "a) one\n\n1) two\n";
        $denied = $this->runCliInput(['--markdown', '--strict-losses', '--allow-loss', 'list-style-dropped'], $source);
        $allowed = $this->runCliInput(['--markdown', '--strict-losses', '--allow-loss', 'list-style-dropped', '--allow-loss', 'list-delimiter-changed'], $source);

        $this->assertSame(1, $denied['exit']);
        $this->assertStringContainsString('list-delimiter-changed', $denied['err']);
        $this->assertSame(0, $allowed['exit']);
        $this->assertSame("1) one\n\n1. two\n", $allowed['out']);
        $this->assertSame('', $allowed['err']);
    }
}

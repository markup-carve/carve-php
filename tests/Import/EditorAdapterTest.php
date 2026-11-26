<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Import\HtmlImporter;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Conformance\ImportClauseCasesTest;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The editor adapters of C2-M-102 to C2-M-110: the pinned `import` and `cli` clause cases.
 */
final class EditorAdapterTest extends TestCase
{
    /**
     * @return array<string, array{0: array<string, mixed>}>
     */
    public static function adapterCases(): array
    {
        $out = [];
        foreach (Corpus::clauseCases() as $id => $case) {
            if (isset(ImportClauseCasesTest::KNOWN_FAIL[$id])) {
                continue;
            }
            if (preg_grep('/^C2-M-1(?:0[2-9]|10)$/', $case['clauses']) !== []) {
                $out[$id] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('adapterCases')]
    public function testPinnedAdapterCase(array $case): void
    {
        if ($case['kind'] === 'cli') {
            $this->cliCase($case);

            return;
        }
        $expected = $case['expected'];
        if (isset($expected['refusal'])) {
            try {
                HtmlImporter::import($case['html'], $case['options']);
                $this->fail('no OptionError');
            } catch (OptionError) {
                $this->addToAssertionCount(1);
            }

            return;
        }
        $result = HtmlImporter::import($case['html'], $case['options']);
        $this->assertSame(Corpus::sorted($expected['children']), Corpus::sorted($result->document['children']));
        foreach ($expected['diagnostics'] ?? [] as $code) {
            $this->assertContains(is_array($code) ? $code['code'] : $code, $result->codes());
        }
        $this->assertSame($expected['carve'], $result->source);
    }

    public function testTheHubRefusesAnAdapter(): void
    {
        $this->assertSame("a\n", Carve::importDocument('a', ['from' => 'markdown', 'adapter' => 'generic'])->source);
        $this->expectException(OptionError::class);
        Carve::importDocument('a', ['from' => 'gfm', 'adapter' => 'google-docs']);
    }

    public function testParseRefusesTheImportOption(): void
    {
        $this->expectException(OptionError::class);
        Carve::parse('a', ['adapter' => 'word']);
    }

    /**
     * C2-M-104, C2-M-107: a reference repeated outside the notes binds to the same note, and the keys skip a key that a
     * footnote section of the input gives.
     */
    public function testRepeatedReferenceAndTakenKey(): void
    {
        $html = '<p>a<a href="#n" id="r">1</a> b<a href="#n">1</a> c<a role="doc-noteref" href="#fn1">x</a></p>'
            . '<div><p><a href="#r" id="n">1</a> N</p></div>'
            . '<section role="doc-endnotes"><ol><li id="fn1"><p>Old</p></li></ol></section>';
        $result = HtmlImporter::import($html, ['adapter' => 'word']);
        $this->assertStringStartsWith("a[^2] b[^2] c[^1]\n", $result->source);
        $this->assertStringEndsWith("[^1]: Old\n\n[^2]: N\n", $result->source);
    }

    /**
     * @param array<string, mixed> $case
     */
    private function cliCase(array $case): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        $this->assertNotFalse($in);
        $this->assertNotFalse($out);
        $this->assertNotFalse($err);
        fwrite($in, $case['stdin'] ?? '');
        rewind($in);
        $status = Command::main($case['args'], $in, $out, $err);
        rewind($out);
        rewind($err);
        $expected = $case['expected'];
        $this->assertSame($expected['status'], $status);
        if (isset($expected['stdout'])) {
            $this->assertSame($expected['stdout'], (string)stream_get_contents($out));
        }
        if (isset($expected['stderrCategory'])) {
            $this->assertStringStartsWith('carve[' . $expected['stderrCategory'] . ']:', (string)stream_get_contents($err));
        }
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use FilesystemIterator;
use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class DirectoryImportTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/carve-dir-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        mkdir($this->root . '/src');
    }

    protected function tearDown(): void
    {
        $walk = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($walk as $entry) {
            $entry->isDir() && !$entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
        }
        rmdir($this->root);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private static function call(array $args): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        $status = Command::main(['import', ...$args], $in, $out, $err);
        rewind($out);
        rewind($err);
        $result = [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
        fclose($in);
        fclose($out);
        fclose($err);

        return $result;
    }

    public function testDirectoryImportPrintsReadableCounts(): void
    {
        file_put_contents($this->root . '/src/a.md', "# A\n");
        [$status, $out, $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status);
        self::assertSame('', $err);
        self::assertSame(
            '1 file(s) converted; 0 asset(s) copied; 0 file(s) need review; 0 entry(s) skipped. Report: '
                . $this->root . "/dst/migration-report.json\n",
            $out,
        );
    }

    public function testConvertsTreeRewritesLinksCopiesAssetsAndReports(): void
    {
        mkdir($this->root . '/src/sub');
        file_put_contents($this->root . '/src/a.md', "Hello\n\n[Next](sub/b.md)\n\n[Missing](missing.md)\n");
        file_put_contents($this->root . '/src/sub/b.md', "# Next\n");
        file_put_contents($this->root . '/src/sub/picture.png', 'asset bytes');
        [$status, $out, $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status, $err);
        self::assertSame('', $err);
        self::assertStringContainsString('[Next](sub/b.crv)', (string)file_get_contents($this->root . '/dst/a.crv'));
        self::assertSame("= Next\n", file_get_contents($this->root . '/dst/sub/b.crv'));
        self::assertSame('asset bytes', file_get_contents($this->root . '/dst/sub/picture.png'));
        self::assertSame(
            '2 file(s) converted; 1 asset(s) copied; 1 file(s) need review; 0 entry(s) skipped. Report: '
                . $this->root . "/dst/migration-report.json\n",
            $out,
        );
        $report = json_decode((string)file_get_contents($this->root . '/dst/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['a.md'], $report['review']);
        self::assertSame('rewritten', $report['files'][0]['links'][0]['status']);
        self::assertSame('unresolved', $report['files'][0]['links'][1]['status']);
    }

    public function testChecksFragmentsAndRawHtmlLinks(): void
    {
        $source = "[Good](b.md#Next)\n\n[Bad](b.md#Absent)\n\n[Encoded](b%2Emd)\n\n"
            . "<a href=\"b.md\">raw</a>\n";
        file_put_contents($this->root . '/src/a.md', $source);
        file_put_contents($this->root . '/src/b.md', "# Next\n");
        [$status, , $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status, $err);
        $report = json_decode((string)file_get_contents($this->root . '/dst/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['a.md'], $report['review']);
        self::assertSame('rewritten', $report['files'][0]['links'][0]['status']);
        self::assertSame('fragment missing from target', $report['files'][0]['links'][1]['reason']);
        self::assertSame('encoded extension needs manual rewrite', $report['files'][0]['links'][2]['reason']);
        self::assertSame('raw HTML link needs manual rewrite', $report['files'][0]['links'][3]['reason']);
    }

    public function testRecasesHeadingFragmentAsLegacyMigrationDid(): void
    {
        file_put_contents($this->root . '/src/a.md', "[Next](b.md#next)\n");
        file_put_contents($this->root . '/src/b.md', "# Next\n");
        [$status, , $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status, $err);
        self::assertSame("[Next](b.crv#Next)\n", file_get_contents($this->root . '/dst/a.crv'));
        $report = json_decode((string)file_get_contents($this->root . '/dst/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $report['review']);
    }

    public function testFragmentIdsComeFromHtmlElementsNotComments(): void
    {
        file_put_contents($this->root . '/src/a.md', "[Real](b.md#real)\n\n[Fake](b.md#fake)\n");
        file_put_contents($this->root . '/src/b.md', "<!-- id=\"fake\" -->\n\n<span id='real'>real</span>\n");
        [$status, , $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status, $err);
        $report = json_decode((string)file_get_contents($this->root . '/dst/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('rewritten', $report['files'][0]['links'][0]['status']);
        self::assertSame('fragment missing from target', $report['files'][0]['links'][1]['reason']);
    }

    public function testChecksRawHtmlSrcsetAndObjectData(): void
    {
        $source = "<img srcset=\"b.md 1x\">\n\n<object data=\"b.md\"></object>\n";
        file_put_contents($this->root . '/src/a.md', $source);
        file_put_contents($this->root . '/src/b.md', 'target');
        [$status, , $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status, $err);
        $report = json_decode((string)file_get_contents($this->root . '/dst/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['a.md'], $report['review']);
        self::assertSame('raw HTML link needs manual rewrite', $report['files'][0]['links'][0]['reason']);
        self::assertSame('raw HTML link needs manual rewrite', $report['files'][0]['links'][1]['reason']);
    }

    public function testIgnoresRawHtmlLinksInsideTemplate(): void
    {
        file_put_contents($this->root . '/src/a.md', "<template>\n<a href=\"missing.md\">x</a>\n</template>\n");
        [$status, , $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst',
        ]);
        self::assertSame(0, $status, $err);
        $report = json_decode((string)file_get_contents($this->root . '/dst/migration-report.json'), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([], $report['files'][0]['links']);
    }

    public function testRefusesNestedTargetAndCaseCollision(): void
    {
        file_put_contents($this->root . '/src/A.md', 'one');
        file_put_contents($this->root . '/src/a.markdown', 'two');
        [$status] = self::call(['--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/src/dst']);
        self::assertSame(2, $status);
        [$status, , $err] = self::call(['--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/dst']);
        self::assertSame(2, $status);
        self::assertStringContainsString('same output', $err);
        self::assertDirectoryDoesNotExist($this->root . '/dst');
        [$status, , $err] = self::call([
            '--from', 'gfm', '--directory', $this->root . '/src', '--output', $this->root . '/src/missing/../dst',
        ]);
        self::assertSame(2, $status);
        self::assertStringContainsString('output', $err);
    }
}

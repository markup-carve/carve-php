<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Import\HtmlImporter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdapterBacklinkWhitespaceTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function noteBodies(): array
    {
        return [
            'direct backlink' => ['<a href="#r" id="n">1</a> N', 'N'],
            'wrapped backlink' => ['<sup><a href="#r" id="n">1</a></sup> N', 'N'],
            'interior backlink' => ['Before <a href="#r" id="n">1</a> after', 'Before  after'],
        ];
    }

    #[DataProvider('noteBodies')]
    public function testAdapterDoesNotReportLayoutExposedByLeadingBacklink(string $noteBody, string $expectedBody): void
    {
        $html = '<p>a<a href="#n" id="r">1</a></p><div><p>' . $noteBody . '</p></div>';
        $result = HtmlImporter::import($html, ['adapter' => 'word']);
        $this->assertSame("a[^1]\n\n[^1]: " . $expectedBody . "\n", $result->source);
        $this->assertSame([], $result->diagnostics);
    }

    public function testImportCommandKeepsStderrEmptyForAdapterNavigation(): void
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, '<p>a<a href="#n" id="r">1</a></p><div><p><a href="#r" id="n">1</a> N</p></div>');
        rewind($in);
        $status = Command::main(['import', '--from', 'html', '--adapter', 'word', '-'], $in, $out, $err);
        rewind($out);
        rewind($err);
        $this->assertSame(0, $status);
        $this->assertSame("a[^1]\n\n[^1]: N\n", stream_get_contents($out));
        $this->assertSame('', stream_get_contents($err));
    }

    public function testBacklinkInsideRetainedSpanKeepsSpaceAfterPriorProse(): void
    {
        $html = '<p>a<a href="#n" id="r">1</a></p>'
            . '<div><p>Before<span><a href="#r" id="n">1</a> after</span></p></div>';
        $result = HtmlImporter::import($html, ['adapter' => 'word']);
        $this->assertSame("a[^1]\n\n[^1]: Before[ after]{}\n", $result->source);
        $this->assertSame([], $result->diagnostics);
    }
}

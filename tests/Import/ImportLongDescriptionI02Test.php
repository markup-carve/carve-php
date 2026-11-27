<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Cli\Command;
use MarkupCarve\Carve\Import\HtmlImporter;
use PHPUnit\Framework\TestCase;

final class ImportLongDescriptionI02Test extends TestCase
{
    /**
     * @param array<string> $args
     * @param string $html
     *
     * @return array{int, string, string}
     */
    private static function cli(array $args, string $html): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertNotFalse($in);
        self::assertNotFalse($out);
        self::assertNotFalse($err);
        fwrite($in, $html);
        rewind($in);
        $status = Command::main(['import', '--from', 'html', ...$args], $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testLongDescriptionReportsUnspellableImageField(): void
    {
        $html = '<img src="photo.jpg" alt="A dog" longdesc="details.html">';
        $result = HtmlImporter::import($html);
        $lost = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'field-unspellable'));
        self::assertCount(1, $lost);
        self::assertSame('/children/0/children/0/longDescription', $lost[0]->path);
        [$status, $out, $err] = self::cli([], $html);
        self::assertSame(1, $status);
        self::assertSame('', $out);
        self::assertStringStartsWith('carve[writer-refused]:', $err);
    }
}

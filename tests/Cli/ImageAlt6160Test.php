<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Cli;

use MarkupCarve\Carve\Cli\Command;
use PHPUnit\Framework\TestCase;

final class ImageAlt6160Test extends TestCase
{
    /**
     * @param list<string> $args
     *
     * @return array{int, string, string}
     */
    private static function call(array $args): array
    {
        $in = fopen('php://memory', 'r+');
        $out = fopen('php://memory', 'r+');
        $err = fopen('php://memory', 'r+');
        self::assertIsResource($in);
        self::assertIsResource($out);
        self::assertIsResource($err);
        $status = Command::main($args, $in, $out, $err);
        rewind($out);
        rewind($err);

        return [$status, (string)stream_get_contents($out), (string)stream_get_contents($err)];
    }

    public function testUnknownImageAltUsesSharedLossMessage(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'carve-cli03-');
        self::assertIsString($path);
        file_put_contents($path, '<p><img src="x"></p>');
        try {
            $message = 'Carve source cannot spell an unknown alt text; the image is written with an empty one';
            self::assertSame(
                [0, "![](x)\n", $path . ': warning image-alt-unknown ' . $message . "\n"],
                self::call(['import', '--from', 'html', $path]),
            );
        } finally {
            unlink($path);
        }
    }
}

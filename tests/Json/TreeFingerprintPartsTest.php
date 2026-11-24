<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeEquality;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeFingerprintPartsTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function sources(): array
    {
        return [
            'paragraphs in a quote' => ["> a {^b^}\n>\n> c *d*\n"],
            'list and table' => ["- a\n\n  > b\n\n|= x |= y |\n| 1 | 2 |\n"],
            'span marks' => ["|= a |= b |\n| 1 |\n| 2 | 3 |\n"],
            'adjacent texts' => ["a\\*b \\{c\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testPartsAreTheFingerprintsOfTheirNodes(string $source): void
    {
        $tree = TreeEncoder::decodedForWriter(Carve::parse($source));
        [$whole, $parts] = TreeEquality::fingerprintParts($tree, ['emphasis' => true, 'superscript' => true]);

        self::assertSame(TreeEquality::fingerprint($tree), $whole);
        self::assertNotSame([], $parts);
        foreach ($parts as $path => $print) {
            $node = $tree;
            foreach ($path === '' ? [] : explode('/', substr($path, 1)) as $step) {
                $node = $node[$step];
            }
            self::assertSame(TreeEquality::fingerprint($node), $print, $path);
        }
    }
}

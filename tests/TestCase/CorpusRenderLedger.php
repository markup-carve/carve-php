<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use RuntimeException;
use function basename;
use function dirname;
use function explode;
use function file_get_contents;
use function glob;
use function hash;
use function implode;
use function ksort;
use function preg_match;
use function sort;
use function str_starts_with;
use function substr;

/**
 * Reading and writing `tests/fixtures/corpus-render-ledger.txt`.
 *
 * Shared by CorpusRenderLedgerTest and `bin/render-ledger.php` so the
 * format has one definition rather than one per caller.
 */
final class CorpusRenderLedger
{
    /**
     * @var list<string>
     */
    public const TARGETS = ['md', 'txt', 'ansi'];

    /**
     * @var string
     */
    public const LINE = '/^(\S+) md:([0-9a-f]{16}) txt:([0-9a-f]{16}) ansi:([0-9a-f]{16})$/';

    /**
     * @var list<string>
     */
    private const HEADER = [
        '# carve-php non-HTML render ledger.',
        '# <slug> md:<digest> txt:<digest> ansi:<digest>, sha-256 truncated to 16 hex.',
        '# Regenerate with `php bin/render-ledger.php`.',
    ];

    public static function path(): string
    {
        return dirname(__DIR__) . '/fixtures/corpus-render-ledger.txt';
    }

    public static function corpusDir(): string
    {
        return dirname(__DIR__) . '/spec/tests/corpus';
    }

    /**
     * @return list<string>
     */
    public static function slugs(): array
    {
        $slugs = [];
        foreach (glob(self::corpusDir() . '/*.crv') ?: [] as $path) {
            $slugs[] = basename($path, '.crv');
        }
        sort($slugs);

        return $slugs;
    }

    /**
     * The output every renderer produces right now, keyed by slug.
     *
     * @return array<string, array<string, string>>
     */
    public static function render(): array
    {
        $md = CarveConverter::markdown();
        $txt = CarveConverter::plainText();
        $ansi = CarveConverter::ansi();

        $rows = [];
        foreach (self::slugs() as $slug) {
            $source = (string)file_get_contents(self::corpusDir() . '/' . $slug . '.crv');
            $rows[$slug] = [
                'md' => self::digest($md->convert($source)),
                'txt' => self::digest($txt->convert($source)),
                'ansi' => self::digest($ansi->convert($source)),
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, array<string, string>> $rows
     */
    public static function serialize(array $rows): string
    {
        ksort($rows);
        $lines = self::HEADER;
        foreach ($rows as $slug => $row) {
            $lines[] = $slug . ' md:' . $row['md'] . ' txt:' . $row['txt'] . ' ansi:' . $row['ansi'];
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @throws \RuntimeException
     *
     * @return array{rows: array<string, array<string, string>>, unparsable: list<string>}
     */
    public static function read(): array
    {
        $raw = @file_get_contents(self::path());
        if ($raw === false) {
            throw new RuntimeException('No render ledger at ' . self::path() . ': run `php bin/render-ledger.php`');
        }

        $rows = [];
        $unparsable = [];
        foreach (explode("\n", $raw) as $line) {
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match(self::LINE, $line, $match) !== 1) {
                $unparsable[] = $line;

                continue;
            }
            $rows[$match[1]] = ['md' => $match[2], 'txt' => $match[3], 'ansi' => $match[4]];
        }

        return ['rows' => $rows, 'unparsable' => $unparsable];
    }

    private static function digest(string $value): string
    {
        return substr(hash('sha256', $value), 0, 16);
    }
}

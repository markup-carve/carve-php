<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class DjotClosingQuoteAfterSlashTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function quotes(): array
    {
        return [
            'reported short case' => ['In "a/" end.', '<p>In “a/” end.</p>'],
            'quoted path' => ['Exclude "/trunk/app/config/".', '<p>Exclude “/trunk/app/config/”.</p>'],
            'opening after slash' => ['/"hello"', '<p>/“hello”</p>'],
            'opening and closing after slash' => ['/"b/"', '<p>/“b/”</p>'],
            'word before opening slash quote' => ['a/"b/"', '<p>a/“b/”</p>'],
            'adjacent empty double quote' => ['"a/""', '<p>“a/”“</p>'],
            'adjacent double quote pair' => ['"a/""b"', '<p>“a/”“b”</p>'],
            'adjacent single quote pair' => ["\"a/\"'b'", '<p>“a/”‘b’</p>'],
            'adjacent unpaired single quote' => ["\"a/\"'b", '<p>“a/”’b</p>'],
            'two adjacent single quote pairs' => ["\"a/\"'b''c'", '<p>“a/”‘b’‘c’</p>'],
            'adjacent unmatched and paired single quotes' => ["\"a/\"''b'", '<p>“a/”’‘b’</p>'],
            'nested adjacent single quotes' => ["\"a/\"''b''", '<p>“a/”‘‘b’’</p>'],
            'adjacent quotes do not pair' => ['a/""', '<p>a/““</p>'],
            'code span inside quotes' => ['In "a`x`/" end.', '<p>In “a<code>x</code>/” end.</p>'],
            'soft wrap inside quotes' => ["\"a\nb/\"", "<p>“a\nb/”</p>"],
            'nested double quotes' => ['"a/ "b" c/"', '<p>“a/ “b” c/”</p>'],
            'single quotes' => ["'a/'", '<p>‘a/’</p>'],
            'mid-line single quotes' => ["x 'a/' y", '<p>x ‘a/’ y</p>'],
            'single quotes in parentheses' => ["x('a/') y", '<p>x(‘a/’) y</p>'],
            'mid-line double quotes' => ['x"a/" y', '<p>x“a/” y</p>'],
            'discard nested double opener with single closer' => ["'a \"b' c/\"", '<p>‘a “b’ c/“</p>'],
            'discard double opener within emphasis' => ['_a "b_ c/"', '<p><em>a “b</em> c/“</p>'],
            'quote outside paired emphasis' => ['"_a_/"', '<p>“<em>a</em>/”</p>'],
            'quote outside paired strong' => ['"*a*/"', '<p>“<strong>a</strong>/”</p>'],
            'quote outside link' => ['"[a](u)/"', '<p>“<a href="u">a</a>/”</p>'],
            'unmatched closing bracket' => ['"a] b/"', '<p>“a] b/”</p>'],
            'forced single quote opener' => ["{'a/'", '<p>‘a/‘</p>'],
            'nested pairs retain text' => ["\"he said 'a/' ok/\" z", '<p>“he said ‘a/’ ok/” z</p>'],
            'initial quote ignores final slash' => ["'a b' c \"d/\" e/", '<p>‘a b’ c “d/” e/</p>'],
            'later emphasis does not hide first quote' => ['"a/" _b_', '<p>“a/” <em>b</em></p>'],
            'blank line ends quote pairing' => ["In \"a\n\nb/\" end.", "<p>In “a</p>\n<p>b/“ end.</p>"],
        ];
    }

    #[DataProvider('quotes')]
    public function testDjotQuoteDirectionIsKept(string $djot, string $expected): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        self::assertSame($expected, trim((new CarveConverter())->convert($carve)));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function boundaries(): array
    {
        return [
            'blank line in blockquote' => ["> \"a\n>\n> b/\"", '<p>b/“</p>'],
            'new list item' => ["- \"a\n- b/\"", 'b/“</li>'],
            'new table cell' => ['| "a | b/" |', '<td>b/“</td>'],
        ];
    }

    #[DataProvider('boundaries')]
    public function testQuotePairingStopsAtBlockBoundaries(string $djot, string $expectedFragment): void
    {
        $html = (new CarveConverter())->convert((new DjotToCarve())->convert($djot));
        self::assertStringContainsString($expectedFragment, $html);
    }
}

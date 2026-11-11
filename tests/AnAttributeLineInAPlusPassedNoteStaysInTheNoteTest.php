<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * An attribute line at a `+`-passed note's body column is the note's (PART 9 §15 A4) and
 * ends its paragraph (§15 A1), whatever the host's content column is (carve-php#5152).
 */
final class AnAttributeLineInAPlusPassedNoteStaysInTheNoteTest extends TestCase
{
    /**
     * @var string
     */
    private const NOTE = '<li id="fn1"><p>n</p><p y="">q<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p></li>';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function sources(): iterable
    {
        foreach (['- +' => 'ul', '1. +' => 'ol', '10. +' => 'ol start="10"'] as $host => $list) {
            yield "$host single" => ["$host\n[^m]: n\n  {y}\n  q\n\np[^m]\n", $list];
            yield "$host stacked" => ["$host\n[^m]: n\n  {y}\n  {y}\n  q\n\np[^m]\n", $list];
            yield "$host deeper body line" => ["$host\n[^m]: n\n  {y}\n   q\n\np[^m]\n", $list];
        }
    }

    #[DataProvider('sources')]
    public function testTheAttributeLabelsTheNotesParagraph(string $source, string $list): void
    {
        $html = preg_replace('/>\s+</', '><', (new CarveConverter())->convert($source));

        self::assertStringContainsString("<$list><li></li></" . substr($list, 0, 2) . '><p>p<a id="fnref1"', (string)$html, $source);
        self::assertStringContainsString(self::NOTE, (string)$html, $source);
    }
}

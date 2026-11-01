<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ListHostedNestedNoteBodyTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        foreach (['1.' => '     ', '-' => '    '] as $marker => $bodyIndent) {
            foreach (["\n", " \n"] as $blank) {
                yield $marker . ($blank === "\n" ? 'empty blank' : 'space blank') => [
                    $marker . " [^outer]: intro\n\n" . $bodyIndent . "[^inner]: note\n"
                    . $blank . $bodyIndent . "see[^inner]\n\n- see[^outer]\n",
                ];
            }
        }
    }

    #[DataProvider('sources')]
    public function testPlainLineAfterNestedNoteBelongsToOuterNote(string $source): void
    {
        $html = (new CarveConverter())->convert($source);

        self::assertStringContainsString('<li></li>', $html);
        self::assertStringContainsString('<p>intro</p>', $html);
        self::assertStringContainsString('<p>see<a id="fnref2" href="#fn2" role="doc-noteref"><sup>2</sup></a>', $html);
        self::assertStringContainsString('<p>note<a href="#fnref2" role="doc-backlink"', $html);
        self::assertLessThan(strpos($html, '<p>note<a href="#fnref2"'), strpos($html, '<p>see<a id="fnref2"'));
    }
}

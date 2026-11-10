<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

// CARVE-P2-035: the authored brace column governs a marker-line run.
class AMarkerLineNoteCommentPastItsBraceTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function sources(): iterable
    {
        yield 'one column past the brace' => ["[^n]: {% a\n       {k=v}\n       z %} y\n\nr[^n]\n"];
        yield 'two columns past the brace' => ["[^n]: {% a\n        {k=v}\n        z %} y\n\nr[^n]\n"];
        yield 'tab past the brace' => ["[^n]: {% a\n\t\t{k=v}\n\t\tz %} y\n\nr[^n]\n"];
        yield 'quoted note' => ["> [^n]: {% a\n>        {k=v}\n>        z %} y\n\nr[^n]\n"];
        yield 'list note' => ["- [^n]: {% a\n         {k=v}\n         z %} y\n\nr[^n]\n"];
        yield 'description note' => [":: t\n: [^n]: {% a\n         {k=v}\n         z %} y\n\nr[^n]\n"];
    }

    #[DataProvider('sources')]
    public function testRunContinues(string $source): void
    {
        $html = (new CarveConverter())->convert($source);
        self::assertStringContainsString(
            '<p> y<a href="#fnref1" role="doc-backlink" aria-label="Back to reference">↩</a></p>',
            $html,
        );
        self::assertStringNotContainsString('{% a', $html);
    }
}

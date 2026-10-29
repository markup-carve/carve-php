<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MarkerNoteMarkerContinuationTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function rows(): iterable
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/marker_note_marker_continuation.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('rows')]
    public function testAMarkerAtAMarkerLineNotesColumnIsTheNotes(string $source, string $expected): void
    {
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $restored = $codec->decode($codec->encode($converter->parse($source)));
        foreach ([$converter->convert($source), $converter->render($restored)] as $html) {
            $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
        }
    }
}

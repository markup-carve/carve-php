<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Two rulings, one line between them: which characters are CONTENT and which
 * are layout (markup-carve/carve#1628 and markup-carve/carve#1621, clauses in
 * markup-carve/carve#1631, this engine's half in carve-php#1633).
 *
 * PART 11 §7 already drew that line for U+00A0 on the way out - a trailing
 * no-break space IS content. These are the inbound face of the same line, and
 * the writer's face of it.
 */
class AWhitespaceOnlyImportKeepsWhatPart11CallsContentTest extends TestCase
{
    protected HtmlToCarve $converter;

    protected function setUp(): void
    {
        parent::setUp();
        $this->converter = new HtmlToCarve();
    }

    /**
     * The divider is the two-character `whitespace` terminal - a space and a
     * tab - and NOTHING else.
     *
     * @return array<string, array{0: string, 1: string}>
     */
    public static function contentCharacterProvider(): array
    {
        return [
            'a no-break space' => ['&nbsp;', "\u{00A0}"],
            'a narrow no-break space' => ['&#8239;', "\u{202F}"],
            'an ideographic space' => ['&#12288;', "\u{3000}"],
        ];
    }

    /**
     * @param string $entity
     * @param string $char
     */
    #[DataProvider('contentCharacterProvider')]
    public function testAContentCharacterIsKeptVerbatim(string $entity, string $char): void
    {
        $report = $this->converter->convertWithReport('<p>' . $entity . '</p>');

        // Verbatim means verbatim: normalizing it to U+0020 would put it back
        // on the layout side of the line, where the block trim removes it.
        $this->assertSame($char . "\n", $this->converter->convert('<p>' . $entity . '</p>'));
        $this->assertSame([], $report->diagnostics);
    }

    /**
     * @param string $entity
     * @param string $char
     */
    #[DataProvider('contentCharacterProvider')]
    public function testAContentCharacterReadsBackAsAParagraph(string $entity, string $char): void
    {
        // The premise the keep half rests on, asserted rather than assumed. If
        // this ever changes, the character moved across the line and the rule
        // above moves with it.
        $this->assertStringContainsString('<p>', (new CarveConverter())->convert($char . "\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function layoutOnlyProvider(): array
    {
        return [
            'a space' => ['<p> </p>'],
            'a tab' => ['<p>&#9;</p>'],
        ];
    }

    /**
     * @param string $html
     */
    #[DataProvider('layoutOnlyProvider')]
    public function testALayoutOnlyBlockWritesNothingAndSaysSo(string $html): void
    {
        $report = $this->converter->convertWithReport($html);

        // The bytes were already right; the silence was not.
        $this->assertSame("\n", $this->converter->convert($html));
        $this->assertSame(['element-dropped'], array_map(static fn ($row): string => $row->code, $report->diagnostics));
        $this->assertSame(
            'Dropped whitespace-only <p> holding no content character',
            $report->diagnostics[0]->message,
        );
        $this->assertSame('/p[1]', $report->diagnostics[0]->path);
    }

    public function testAnEmptyBlockIsNotThisShape(): void
    {
        // §7 weighs the characters a block HOLDS, and this one holds none -
        // there is nothing for the clause to call layout. Named so the boundary
        // of the change is a decision rather than an accident.
        $this->assertSame([], $this->converter->convertWithReport('<p></p>')->diagnostics);
    }

    public function testTheSharedFixtureReadsTheWayTheFixtureSaysItDoes(): void
    {
        // The spec fixture's own input, asserted here because the shared runner
        // cannot see it until this engine's pin advances past the clause.
        $html = '<ul><li>a</li></ul><p>&nbsp;</p><ul><li>b</li></ul><p> </p><p>c</p>'
            . '<p>&#9;</p><p>&#8239;</p><p>&#12288;</p>';
        $report = $this->converter->convertWithReport($html);

        $this->assertSame(
            "- a\n\n\u{00A0}\n\n- b\n\nc\n\n\u{202F}\n\n\u{3000}\n",
            $this->converter->convert($html),
        );
        $this->assertSame(
            ['/p[4]', '/p[6]'],
            array_map(static fn ($row): string => $row->path, $report->diagnostics),
        );
    }
}

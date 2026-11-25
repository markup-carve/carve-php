<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Import;

use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-H-043 (carve#6762): the verse whitespace a line block renders as `&nbsp;` imports back as spaces. A tab renders
 * as columns, so it comes back as spaces; the render of the imported document is the same HTML.
 */
final class LineBlockVerseImportTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function sources(): array
    {
        return [
            'CC-002' => ["- > a\n  > \t::: |\n  > \tx\n  > \t:::\n"],
            'CC-2143' => ["::: |\ntab\tgap\nwide\t\tgap\n\tlead\nwide  two\na b\n:::\n"],
            'CC-2144' => ["::: |\n/a  b/ c\n:::\n"],
            'CC-2145' => ["::: |\n`a  b\tc`  d\n:::\n"],
            '41-line-blocks-9' => ["::: |\ntab\tgap\nwide\t\tgap\n\tlead\n:::\n"],
            'edge 1042' => ["> - a\n>   - a\n>   \t::: |\n>     \t z\n>   \t:::\n"],
            'edge 1043' => ["> - a\n>   - a\n>   \t::: |\n> \t\tz\n>   \t:::\n"],
            'edge 1071' => ["- a\n  ::: |\n  \tx\n  :::\n"],
            'interior runs' => ["::: |\n  a  b   c\n[x  y](u)  *s  t*\n:::\n"],
        ];
    }

    #[DataProvider('sources')]
    public function testRenderImportRender(string $source): void
    {
        $this->assertRoundTrip($source);
    }

    public function testSpacesComeBackByteIdentical(): void
    {
        $source = "::: |\n  a  b   c\n[x  y](u)  *s  t*\n /a  b/ c\n:::\n";

        $this->assertSame(Carve::format($source), $this->back($source));
    }

    public function testTabComesBackAsItsColumns(): void
    {
        $this->assertSame("::: |\ntab gap\nwide        gap\n    lead\n:::\n", $this->back("::: |\ntab\tgap\nwide\t\tgap\n\tlead\n:::\n"));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function authored(): array
    {
        return [
            'one inside a line' => ["::: |\na\u{00A0}b\n:::\n"],
            'one beside a space' => ["::: |\na \u{00A0}b\n:::\n"],
            'one leading a space run' => ["::: |\n\u{00A0} a\n:::\n"],
            'two beside a space' => ["::: |\na\u{00A0}\u{00A0} b\n:::\n"],
        ];
    }

    #[DataProvider('authored')]
    public function testAuthoredNoBreakSpaceStays(string $source): void
    {
        $this->assertSame(Carve::format($source), $this->back($source));
    }

    /**
     * Generated lines: runs of 1 to 8 spaces and tabs leading, inside and trailing, with inline markup around words.
     */
    public function testGeneratedLineBlocks(): void
    {
        mt_srand(6762);
        $words = ['a', 'bc', 'äö', 'x&y', '/em/', '*st*', '[l](u)', '`c  d`', '/a  b/', '[p  q](u)', '$m$'];
        for ($n = 0; $n < 1000; $n++) {
            $lines = [];
            $count = mt_rand(1, 4);
            for ($l = 0; $l < $count; $l++) {
                $line = mt_rand(0, 2) === 0 ? self::spaceRun() : '';
                $parts = mt_rand(1, 4);
                for ($p = 0; $p < $parts; $p++) {
                    $line .= ($p > 0 ? self::spaceRun() : '') . $words[mt_rand(0, count($words) - 1)];
                }
                $lines[] = $line . (mt_rand(0, 3) === 0 ? self::spaceRun() : '');
            }
            $source = "::: |\n" . implode("\n", $lines) . "\n:::\n";
            $this->assertRoundTrip($source);
            if (!str_contains($source, "\t")) {
                $this->assertSame(Carve::format($source), $this->back($source), $source);
            }
        }
    }

    private static function spaceRun(): string
    {
        $run = '';
        $length = mt_rand(1, 8);
        for ($i = 0; $i < $length; $i++) {
            $run .= mt_rand(0, 2) === 0 ? "\t" : ' ';
        }

        return $run;
    }

    private function assertRoundTrip(string $source): void
    {
        $html = Carve::toHtml($source, ['roundtrip' => true]);
        $back = $this->back($source);

        $this->assertStringNotContainsString("\u{00A0}", $back, $source);
        $this->assertSame($html, Carve::toHtml($back, ['roundtrip' => true]), $source);
    }

    private function back(string $source): string
    {
        return Carve::write(Carve::importHtml(Carve::toHtml($source, ['roundtrip' => true]))->tree);
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * The definition prepass opens a region only where the block parser opens one.
 *
 * The prepass matched the fence RUN alone while the block parser applied PART
 * 7's slot rules, so every line the info-string rules refuse still opened an
 * opaque region here. A region the block parser never opened has no closer
 * ahead either, so it ran to the end of the document: the definitions below it
 * stopped being collected while the block parser went on consuming them, and
 * the author's line rendered nowhere AND defined nothing (carve-php#1348).
 *
 * The tab spelling is the one that was reported. PART 7 divides that line in
 * two and the halves never overlap (markup-carve/carve#1295): a run BEFORE
 * content is a SEPARATOR, whose slot is a literal space a tab cannot satisfy,
 * so the fence does not open; a run at END OF LINE is TRAILING whitespace,
 * which PART 2 drops, so the fence opens normally. Both halves are pinned
 * below, because a prepass that refused the trailing form would be wrong in
 * the other direction.
 */
class PrepassFenceOpenerTest extends TestCase
{
    private function convert(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refusedOpenerProvider(): array
    {
        $fence = str_repeat('`', 3);
        $tilde = str_repeat('~', 3);

        return [
            'a tab in the separator slot' => [$fence . "\tphp"],
            'a tab in the separator slot, tilde fence' => [$tilde . "\tphp"],
            'two spaces in the separator slot' => [$fence . '  php'],
            'a tab before a raw block format' => [$fence . "\t=html"],
            'a tab inside a raw block opener' => [$fence . "=html\tx"],
        ];
    }

    /**
     * A line the block parser reads as prose opens no region, so a definition
     * under it is still collected.
     */
    #[DataProvider('refusedOpenerProvider')]
    public function testALineTheBlockParserRefusesOpensNoRegion(string $opener): void
    {
        $html = $this->convert($opener . "\n[r]: /u\n\nsee [t][r]\n");

        $this->assertStringContainsString('href="/u"', $html, 'the definition below must still register');
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedOpenerProvider(): array
    {
        $fence = str_repeat('`', 3);
        $tilde = str_repeat('~', 3);

        return [
            'a bare fence' => [$fence],
            'a fence with a trailing tab' => [$fence . "\t"],
            'a fence with a trailing space run' => [$fence . '   '],
            'one space then an info string' => [$fence . ' php'],
            'a tilde fence' => [$tilde],
            'a raw block opener' => [$fence . '=html'],
        ];
    }

    /**
     * And a line it DOES open a block on still hides the definition inside it,
     * so narrowing the opener cannot have gone the other way.
     */
    #[DataProvider('acceptedOpenerProvider')]
    public function testALineTheBlockParserAcceptsStillHidesItsBody(string $opener): void
    {
        $fence = str_repeat('`', 3);
        $html = $this->convert($opener . "\n[r]: /u\n" . $fence . "\n\nsee [t][r]\n");

        $this->assertStringNotContainsString('href="/u"', $html, 'a sample defines nothing');
    }

    /**
     * The opener is asked of the block parser now rather than matched with a
     * regex, and the block parser reads the whole info string. A document that
     * is nothing but candidate openers therefore has to stay flat per byte:
     * the info parse is bounded by the line, not by the document.
     */
    #[Group('scaling')]
    public function testAskingTheBlockParserPerOpenerStaysFlatPerByte(): void
    {
        $fence = str_repeat('`', 3);
        $build = static function (int $n) use ($fence): string {
            $out = '';
            for ($i = 0; $i < $n; $i++) {
                $out .= $fence . "\t" . str_repeat('x', 40) . "\n\n";
            }

            return $out . "[r]: /u\n\nsee [t][r]\n";
        };

        $small = $build(2000);
        $large = $build(4000);

        // Warm up so autoloading and JIT are not attributed to the first sample.
        (new CarveConverter())->convert($small);

        $perByte = static function (string $src): float {
            $best = INF;
            for ($run = 0; $run < 3; $run++) {
                $start = hrtime(true);
                (new CarveConverter())->convert($src);
                $best = min($best, (float)(hrtime(true) - $start));
            }

            return $best / strlen($src);
        };

        $ratio = $perByte($large) / max($perByte($small), 1e-9);

        $this->assertLessThan(
            1.3,
            $ratio,
            sprintf('Expected flat cost per byte; ratio was %.2f.', $ratio),
        );
    }
}

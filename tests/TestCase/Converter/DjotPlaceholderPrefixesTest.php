<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotPlaceholderPrefix;
use MarkupCarve\Carve\Converter\DjotToCarve;
use MarkupCarve\Carve\Converter\HeadingId\HeadingIdSource;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DjotPlaceholderPrefixesTest extends TestCase
{
    /**
     * @return array<string, array{string, string, int}>
     */
    public static function placeholders(): array
    {
        $rows = [];
        foreach (["\0DJOTSTRONG", "\0DJOTWORD", "\0DJOTORPHAN\0", "\0DJOTEMPTYTERM\0", "\0DJOTALT\0", "\0DJOTLITERAL\0"] as $base) {
            $tokens = [
                'overlap' => $base . "0\0" . substr($base, 1) . "1\0",
                'leading-zero' => $base . "00\0",
                'long-run' => $base . str_repeat("\0", 32768),
                'reserved-series' => implode('', array_map(static fn (int $n): string => $base . $n . "\0", range(0, 127))),
            ];
            foreach ($tokens as $mode => $token) {
                $rows[str_replace("\0", '', $base) . '-' . $mode] = [$token, $base, $mode === 'overlap' ? 2 : ($mode === 'reserved-series' ? 128 : 0)];
            }
        }

        return $rows;
    }

    #[DataProvider('placeholders')]
    public function testUserPlaceholdersArePreserved(string $token, string $base, int $namespace): void
    {
        self::assertSame($base . $namespace . "\0", DjotPlaceholderPrefix::choose($token, $base));
        $source = $token . " w{x}{.c} ![*alt*](u)\n\na {.o} b\n\n{.orphan}\n\n: ```\n  payload\n  ```\n\n{+unclosed\n";
        $converted = (new DjotToCarve())->convert($source);
        self::assertStringContainsString($token, $converted);
        self::assertStringNotContainsString("\0DJOT", str_replace($token, '', $converted));
        $html = (new CarveConverter())->convert($converted);
        self::assertStringContainsString('class="c"', $html);
        self::assertStringContainsString('alt="alt"', $html);
        self::assertStringContainsString('<dd>', $html);
        self::assertStringContainsString('a  b', $html);
        self::assertStringContainsString('{+unclosed', $html);
    }

    public function testFrontmatterKeepsUserPlaceholderText(): void
    {
        $prefix = "---\nlabel: \0DJOTWORD0\0\n---\n\n";
        $converted = (new DjotToCarve())->convert($prefix . "\0DJOTWORD0\0 w{x}{.c}");
        self::assertStringStartsWith($prefix, $converted);
        self::assertStringContainsString("\0DJOTWORD0\0", $converted);
        self::assertStringContainsString('class="c"', (new CarveConverter())->convert($converted));
    }

    public function testUserMarkersFormedByOrphanRemovalArePreserved(): void
    {
        foreach ([0, 7] as $index) {
            $token = "\0DJOTWORD0\0" . $index . "\0";
            $source = "\0{.a}DJOTWORD0\0" . $index . "\0 w{.c}";
            self::assertSame($token . ' [w]{.c}', (new DjotToCarve())->convert($source));
        }
    }

    public function testEmptyTermUserMarkerStaysInImageAltText(): void
    {
        $token = "\0DJOTEMPTYTERM\0" . "0\0";
        self::assertStringContainsString($token, (new DjotToCarve())->convert('![' . $token . '](x)'));
    }

    public function testUserMarkersSurviveFlattenedEmphasis(): void
    {
        foreach (['DJOTWORD0', "DJOTALT\0" . '0', "DJOTLITERAL\0" . '0', 'DJOTUSERNUL'] as $base) {
            $source = "{*a \0{*" . $base . "\0" . "0\0*} b*} w{.c}";
            $converted = (new DjotToCarve())->convert($source);
            self::assertStringContainsString("\0" . $base . "\0" . "0\0", $converted);
            self::assertSame(1, substr_count((new CarveConverter())->convert($converted), 'class="c"'));
        }
    }

    public function testUserTextMatchingTheNulShieldIsPreserved(): void
    {
        $token = "\0U\0";
        self::assertStringContainsString($token, (new DjotToCarve())->convert($token . ' w{.c}'));
    }

    public function testLongBackslashRunStaysInsideTheAttributedWord(): void
    {
        $source = 'a' . str_repeat('\\', 32768) . 'b{.c}';
        $converted = (new DjotToCarve())->convert($source);
        $expected = '<p><span class="c">a' . str_repeat('\\', 16384) . 'b</span></p>';
        self::assertSame($expected, trim((new CarveConverter())->convert($converted)));
    }

    public function testNulCharactersInImportedDataAreRestored(): void
    {
        foreach (["# a\0b", "[a](x\0y)", "![a](x\0y)", "[a]{key=\"x\0y\"}", "<x:a\0b>"] as $source) {
            self::assertSame($source, (new DjotToCarve())->convert($source));
        }
    }

    public function testOriginalNulIsRestoredBeforeFlatteningAFormattedImageLabel(): void
    {
        self::assertSame("![a\u{FFFD}](x)", (new DjotToCarve())->convert("![*a*\0](x)"));
    }

    public function testEscapedNulStaysInsideItsAttributedWord(): void
    {
        $converted = (new DjotToCarve())->convert("\\\0{.c}");
        self::assertStringContainsString('<span class="c">', (new CarveConverter())->convert($converted));
    }

    public function testSingleIndexUserMarkersSurviveSyntaxRemoval(): void
    {
        foreach (['DJOTINVALIDATTR0', "DJOTINVALIDATTR\0" . '0', 'DJOTNOTEATTR0', "DJOTNOTEATTR\0" . '0'] as $base) {
            $token = "\0" . $base . "\0";
            foreach (["\0{.a}" . $base . "\0 {x y}", "{*a \0{*" . $base . "\0*} b*} {x y}"] as $source) {
                $converted = (new DjotToCarve())->convert($source . "\n\n[^n]: note\n\n  {.c}\n\n[^n]");
                self::assertStringContainsString($token, $converted);
                self::assertStringNotContainsString("\0DJOT", str_replace($token, '', $converted));
            }
        }
    }

    public function testPublishedHeadingIdsReceiveOriginalNulText(): void
    {
        $ids = new class implements HeadingIdSource {
            public string $seen = '';

            public function idsInOrder(string $djotSource): array
            {
                $this->seen = $djotSource;

                return ['published'];
            }
        };
        $source = "# a\0b";
        $converted = (new DjotToCarve())->preserveHeadingIds($ids)->convert($source);
        self::assertSame($source, $ids->seen);
        self::assertStringContainsString('{#published}', $converted);
        self::assertStringNotContainsString("\0U\0", $converted);
    }
}

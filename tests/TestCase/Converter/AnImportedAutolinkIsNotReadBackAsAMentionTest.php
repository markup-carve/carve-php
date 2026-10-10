<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Converter\DjotToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve-php#3037: the Djot importer wrote an autolink body with a
 * bare `@`, and Carve read that `@` as a mention rather than as part of the
 * address, so the importer produced Carve it could not read back.
 *
 * THE ASSERTION IS THE RE-READ, NOT THE BYTES. Each imported document is parsed
 * again and its HTML must still carry the address; a `class="mention"` anywhere
 * in it is the defect. The escape is word-bounded, so an `@` after a word
 * character stays bare and a real mention outside an autolink keeps working.
 */
class AnImportedAutolinkIsNotReadBackAsAMentionTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function mentionShapedAutolinks(): array
    {
        return [
            // The two rows that were the last byte difference between this
            // engine and carve-js across 2,197 imported outputs.
            'on its own' => ["<mailto:a{ }@b.c>\n", "<mailto:a{%%}\\@b.c>\n"],
            'inside a paragraph' => ["para <mailto:a{ }@b.c> tail\n", "para <mailto:a{%%}\\@b.c> tail\n"],
        ];
    }

    #[DataProvider('mentionShapedAutolinks')]
    public function testAnAtSignAfterANonWordCharacterIsEscaped(string $djot, string $carve): void
    {
        self::assertSame($carve, (new DjotToCarve())->convert($djot));
    }

    #[DataProvider('mentionShapedAutolinks')]
    public function testTheImportedCarveStillReadsAsTheAddress(string $djot, string $carve): void
    {
        $imported = (new DjotToCarve())->convert($djot);
        $html = (new CarveConverter())->convert($imported);
        self::assertStringNotContainsString('class="mention"', $html, 'the imported autolink read back as a mention');
        self::assertStringContainsString('mailto:a@b.c', $html);
        self::assertSame($carve, $imported);
    }

    /**
     * The control on over-application. An autolink the reader accepts has an
     * opaque body, so no mention opens inside it whatever precedes the `@`, and
     * an escape there would cost the link: `<a--@b.c>` would read as text with
     * an en dash. carve-js escapes these too and loses the link, which is a
     * defect on that side rather than a spelling to copy.
     *
     * @return array<string, array{0: string}>
     */
    public static function bareAutolinks(): array
    {
        return [
            'an at sign with no preceding brace comment' => ["<mailto:a@b.c>\n"],
            'a bare address' => ["<a@b.c>\n"],
            'userinfo in a url' => ["<http://u@x/y>\n"],
            'an at sign after a dash run' => ["<a--@b.c>\n"],
            'an at sign after a single dash' => ["<a-@b.c>\n"],
        ];
    }

    #[DataProvider('bareAutolinks')]
    public function testAnAtSignInAReadableAutolinkStaysBare(string $djot): void
    {
        $carve = (new DjotToCarve())->convert($djot);
        self::assertSame($djot, $carve);
        self::assertStringNotContainsString('\\@', $carve, 'the escape was applied where no mention opens');
        $html = (new CarveConverter())->convert($carve);
        self::assertStringNotContainsString('class="mention"', $html);
        self::assertStringContainsString('<a href="', $html, 'the autolink did not read back as a link');
    }

    /**
     * The escape lives in the importer, so authored Carve is untouched: a
     * mention outside an autolink still opens one. Djot spells no mention of
     * its own, so an imported `@alice` is escaped exactly as before.
     */
    public function testARealMentionOutsideAnAutolinkStillReads(): void
    {
        self::assertStringContainsString('class="mention"', (new CarveConverter())->convert("Hi @alice there\n"));
        self::assertSame("Hi \\@alice there\n", (new DjotToCarve())->convert("Hi @alice there\n"));
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The Markdown writer probes the destination it will actually emit.
 *
 * It normalizes a destination on the way out - it drops control characters, and
 * its consumer decodes character references - so probing the authored form and
 * normalizing afterwards lets the writer manufacture a live `javascript:` URL
 * out of one the probe had already dismissed (carve-php#1062).
 */
class MarkdownDestinationProbeTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function characterReferenceProvider(): array
    {
        return [
            'decimal' => ["[t](&#106;avascript:alert1)\n"],
            'hexadecimal' => ["[t](&#x6A;avascript:alert1)\n"],
            'named colon' => ["[t](javascript&colon;alert1)\n"],
            'numeric colon' => ["[t](javascript&#58;alert1)\n"],
            'image' => ["![t](&#106;avascript:alert1)\n"],
        ];
    }

    /**
     * A consumer decodes references inside a destination, so what it decodes to
     * has to be what was probed. The emitted ampersand is escaped, which decodes
     * back to the authored bytes rather than to a scheme.
     */
    #[DataProvider('characterReferenceProvider')]
    public function testACharacterReferenceDoesNotSmuggleADeniedScheme(string $source): void
    {
        $output = CarveConverter::markdown()->convert($source);

        $this->assertStringNotContainsString('(&#', $output);
        $this->assertStringNotContainsString('&colon;a', $output);
        $this->assertStringNotContainsString('&#58;a', $output);
        $this->assertStringContainsString('&amp;', $output);
    }

    /**
     * The OTHER half of this finding does not reproduce here and must not start
     * to: `blankDangerousScheme()` strips `\p{Cc}` inside the probe, so a DEL or
     * a C1 control cannot hide a scheme - which is where carve-js and carve-rs
     * were wrong. The probe character is built from an escape and asserted
     * present before use.
     *
     * @return array<string, array{0: int}>
     */
    public static function controlCharacterProvider(): array
    {
        return [
            'DEL' => [0x7f],
            'C1 first' => [0x80],
            'C1 last' => [0x9f],
        ];
    }

    #[DataProvider('controlCharacterProvider')]
    public function testAControlCharacterStillCannotHideAScheme(int $codePoint): void
    {
        $hidden = mb_chr($codePoint, 'UTF-8');
        $source = '[t](java' . $hidden . "script:alert1)\n";
        $this->assertStringContainsString($hidden, $source, 'the probe character was lost');

        $output = CarveConverter::markdown()->convert($source);

        $this->assertStringNotContainsString('javascript:', $output);
        $this->assertStringContainsString('[t]()', $output);
    }

    /**
     * CONTROL: an ordinary destination is emitted byte for byte. An ampersand
     * that opens nothing is not a character reference, and a query string must
     * survive intact - percent-encoding it was the tempting fix and it is the
     * wrong one.
     */
    public function testAnOrdinaryDestinationIsUntouched(): void
    {
        $output = CarveConverter::markdown()->convert(
            "[a](http://x/?a=1&b=2)\n\n[c](mailto:x@y.z)\n\n![i](p.png \"t\")\n",
        );

        $this->assertStringContainsString('[a](http://x/?a=1&b=2)', $output);
        $this->assertStringContainsString('[c](mailto:x@y.z)', $output);
        $this->assertStringContainsString('![i](p.png "t")', $output);
    }

    /**
     * CONTROL: the denylist still decides the plain cases.
     */
    public function testTheDenylistStillDecidesThePlainCases(): void
    {
        $converter = CarveConverter::markdown();

        $this->assertStringContainsString('[t]()', $converter->convert("[t](javascript:alert1)\n"));
        $this->assertStringContainsString('[t]()', $converter->convert("[t](vbscript:x)\n"));
        $this->assertStringContainsString(
            '(https://example.org/)',
            $converter->convert("[t](https://example.org/)\n"),
        );
    }
}

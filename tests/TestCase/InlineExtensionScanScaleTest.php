<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * A `:` that cannot open an inline extension must not scan the rest of the text.
 *
 * PCRE looks for the extension pattern's required `]` before it tries the
 * anchored match, so every colon in a long paragraph without one read to the
 * end of the paragraph. Unclosed and deeply nested colon fences hit it hardest:
 * past the nesting cap their lines become one long paragraph of colons.
 */
class InlineExtensionScanScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * 8x rather than the trait's 4x, with a tighter bound: the scan is a C-level
     * memchr, so at these sizes the defect reads ~3.2x per byte and the fix ~1.0x.
     */
    #[Group('scaling')]
    public function testColonRunScalesLinearly(): void
    {
        $converter = new CarveConverter();
        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            str_repeat('::', 20000) . 'x',
            str_repeat('::', 160000) . 'x',
            'colons without a closing bracket',
            20000,
            160000,
            maxPerByteRatio: 2.0,
        );
    }

    public function testExtensionsStillParse(): void
    {
        $html = (new CarveConverter())->convert(":a[b] :_[c]{.k} :9[d] :-[e] :f[g\n");

        self::assertSame(
            "<p><span class=\"ext-a\">b</span> <span class=\"ext-_ k\">c</span> :9[d] :-[e] :f[g</p>\n",
            $html,
        );
    }
}

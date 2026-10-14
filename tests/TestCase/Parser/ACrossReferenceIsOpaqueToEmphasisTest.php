<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * PART 8 ranks cross-references with autolinks, above emphasis: the `/` of
 * `</#id>` is never a closer for a bare marker opened before it.
 */
class ACrossReferenceIsOpaqueToEmphasisTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * @return array<string, array{string, string}>
     */
    public static function shapes(): array
    {
        return [
            'an unclosed slash before an unresolved cross-reference' => [
                "x /a </#nothing> y\n",
                '<p>x /a &lt;/#nothing&gt; y</p>',
            ],
            'emphasis around a cross-reference' => [
                "/a </#nothing> b/\n",
                '<p><em>a &lt;/#nothing&gt; b</em></p>',
            ],
            'a marker inside the target' => [
                "*a </#x*> b*\n",
                '<p><strong>a &lt;/#x*&gt; b</strong></p>',
            ],
            'the autolink control' => [
                "x /a <https://x.y/b/> y\n",
                '<p>x /a <a href="https://x.y/b/">https://x.y/b/</a> y</p>',
            ],
        ];
    }

    #[DataProvider('shapes')]
    public function testACrossReferenceHidesItsSlashFromEmphasis(string $source, string $expected): void
    {
        $this->assertSame($expected, trim((new CarveConverter())->convert($source)));
    }

    public function testAnUnclosedSlashStaysLiteralBeforeAResolvedCrossReference(): void
    {
        $html = (new CarveConverter())->convert("{#intro}\n# Intro\n\nx /a </#intro> y\n");

        $this->assertStringContainsString('<p>x /a <a href="#intro">Intro</a> y</p>', $html);
    }

    #[Group('scaling')]
    #[DataProvider('scalingShapes')]
    public function testTheCrossReferenceSkipScalesLinearly(string $fragment, string $suffix): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), $fragment, $suffix, "'{$fragment}' + '{$suffix}'");
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function scalingShapes(): array
    {
        return [
            'unclosed cross-reference openers' => ['/x </#a', ' >'],
        ];
    }
}

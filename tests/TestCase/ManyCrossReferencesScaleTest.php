<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Recognizing a `</#id>` cross-reference must not re-read the rest of the
 * paragraph, so a paragraph of many references parses in linear time.
 */
class ManyCrossReferencesScaleTest extends TestCase
{
    use ScalingGuardTrait;

    public function testACrossReferenceAmongTextStillParses(): void
    {
        $this->assertSame(
            '<p>x &lt;/#a&gt; x &lt;/#ü&gt; y</p>',
            trim((new CarveConverter())->convert("x </#a> x </#ü> y\n")),
        );
    }

    #[Group('scaling')]
    #[DataProvider('shapes')]
    public function testManyCrossReferencesScaleLinearly(string $fragment): void
    {
        $this->assertScanScalesLinearly(new CarveConverter(), $fragment, '', "'{$fragment}'");
    }

    /**
     * @return array<string, array{string}>
     */
    public static function shapes(): array
    {
        return [
            'references between words' => ['x </#a> '],
            'references glued to words' => ['xx</#a> '],
        ];
    }
}

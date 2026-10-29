<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Parser\Utility\LayoutWork;
use MarkupCarve\Carve\Test\RuleATodo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FenceAdmissionRegressionsTest extends TestCase
{
    use RuleATodo;

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function paragraphs(): iterable
    {
        /** @var array<array{id:string, source:string, html:string}> $rows */
        $rows = json_decode(
            (string)file_get_contents(__DIR__ . '/../../fixtures/fence_admission_regressions.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        foreach ($rows as $row) {
            yield $row['id'] => [$row['source'], $row['html']];
        }
    }

    #[DataProvider('paragraphs')]
    public function testFenceAdmissionPreservesParagraphAndPayload(string $source, string $expected): void
    {
        $expected = trim((string)preg_replace('/>\s+</', '><', $expected));
        $converter = new CarveConverter();
        $codec = new AstCodec();
        $restored = $codec->decode($codec->encode($converter->parse($source)));
        foreach ([$converter->convert($source), $converter->render($restored)] as $html) {
            $this->assertSame($expected, trim((string)preg_replace('/>\s+</', '><', $html)));
        }
    }

    public function testRepeatedFloorCloserQueriesShareBoundedWork(): void
    {
        $source = "- a\n  ```\n  # h\n" . str_repeat("    ~~~\n", 400) . "  ~~~\n";
        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            (new CarveConverter())->convert($source);
        } finally {
            LayoutWork::$on = false;
        }
        $this->assertGreaterThan(0, LayoutWork::total());
        $this->assertLessThanOrEqual(10 * strlen($source), LayoutWork::total());
    }

    public function testInfoStringFenceMissesShareBoundedWork(): void
    {
        $source = "- a\n  ```\n" . str_repeat("    ~~~ x\n", 400);
        for ($column = 0; $column < 20; $column++) {
            for ($width = 0; $width < 20; $width++) {
                $source .= str_repeat(' ', 3 + $column) . str_repeat('~', 3 + $width) . " x\n";
            }
        }
        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            (new CarveConverter())->convert($source);
        } finally {
            LayoutWork::$on = false;
        }
        $this->assertGreaterThan(0, LayoutWork::total());
        $this->assertLessThanOrEqual(10 * strlen($source), LayoutWork::total());
    }

    public function testItemFenceIndexesAreSharedAcrossOneFrame(): void
    {
        $source = str_repeat("- a\n  ```\n  x\n  ```\n", 400);
        LayoutWork::reset();
        LayoutWork::$on = true;
        try {
            (new CarveConverter())->convert($source);
        } finally {
            LayoutWork::$on = false;
        }
        $this->assertGreaterThan(0, LayoutWork::total());
        $this->assertLessThanOrEqual(10 * strlen($source), LayoutWork::total());
    }

    public function testUnresolvedSameColumnDivMarkerKeepsItsPublishedOwnership(): void
    {
        foreach ([3, 8, 20] as $column) {
            $prefix = str_repeat(' ', $column);
            $source = "- a\n  ```\n" . $prefix . "::: note\n" . $prefix . "- x\n" . $prefix . "::: \n";
            $expected = "<ul><li>a\n<code>\n::: note\n- x\n:::</code></li></ul>";
            $html = (new CarveConverter())->convert($source);
            $this->assertSame($expected, trim((string)preg_replace('/>\\s+</', '><', $html)));
        }
    }

    public function testPostFenceDivMarkerKeepsItsPublishedOwnership(): void
    {
        $source = "- a\n  ```\n  ~~~\n  code\n  ~~~\n    ::: note\n    text\n   - x\n    :::\n";
        $expected = "<ul>\n  <li>a\n<code></code>\n    <pre><code>code\n</code></pre>\n    ::: note\ntext\n- x\n:::\n  </li>\n</ul>\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertSame(
            trim((string)preg_replace('/>\\s+</', '><', $expected)),
            trim((string)preg_replace('/>\\s+</', '><', $html)),
        );
    }

    public function testPostHeadingDivMarkerKeepsItsPublishedOwnership(): void
    {
        $source = "- a\n  ```\n  # h\n    ::: note\n    ~~~\n   - x\n    :::\n";
        $expected = "<ul>\n  <li>a\n<code></code>\n    <h1 id=\"h\">h</h1>\n    ::: note\n~~~\n- x\n:::\n  </li>\n</ul>\n";
        $html = (new CarveConverter())->convert($source);
        $this->assertSame(
            trim((string)preg_replace('/>\\s+</', '><', $expected)),
            trim((string)preg_replace('/>\\s+</', '><', $html)),
        );
    }
}

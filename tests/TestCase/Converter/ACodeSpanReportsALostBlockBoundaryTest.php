<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * markup-carve/carve#2441: a code span's value is verbatim text, so blocks
 * flattened into it join with NO separator and the lost boundary is reported
 * instead.
 */
class ACodeSpanReportsALostBlockBoundaryTest extends TestCase
{
    /**
     * @var array<string, string>
     */
    private const BOUNDARY = [
        'code' => 'structure-unspellable',
        'message' => "A code span's value cannot hold the block boundary inside <code>",
        'severity' => 'warning',
        'fidelity' => 'dropped',
        'confidence' => 'exact',
        'path' => '/code[1]',
    ];

    /**
     * @param string $tag
     * @param string $path
     *
     * @return array<string, mixed>
     */
    private static function unwrapped(string $tag, string $path): array
    {
        return [
            'code' => 'element-unwrapped',
            'message' => 'Unwrapped <' . $tag . '> inside <code>',
            'severity' => 'info',
            'fidelity' => 'degraded',
            'confidence' => 'exact',
            'path' => $path,
        ];
    }

    /**
     * @return array<string, array{string, string, list<array<string, mixed>>}>
     */
    public static function shapes(): array
    {
        return [
            'two blocks' => [
                '<code><div>foo</div><div>bar</div></code>',
                "`foobar`\n",
                [self::BOUNDARY, self::unwrapped('div', '/code[1]/div[1]'), self::unwrapped('div', '/code[1]/div[2]')],
            ],
            'one block, no boundary' => [
                '<code><div>foo</div></code>',
                "`foo`\n",
                [self::unwrapped('div', '/code[1]/div[1]')],
            ],
            'text before a block' => [
                '<code>foo<div>bar</div></code>',
                "`foobar`\n",
                [self::BOUNDARY, self::unwrapped('div', '/code[1]/div[2]')],
            ],
            'text after a block' => [
                '<code><div>foo</div>bar</code>',
                "`foobar`\n",
                [self::BOUNDARY, self::unwrapped('div', '/code[1]/div[1]')],
            ],
            'a block contributing nothing is not a side' => [
                '<code><div>foo</div><div></div><div>bar</div></code>',
                "`foobar`\n",
                [
                    self::BOUNDARY,
                    self::unwrapped('div', '/code[1]/div[1]'),
                    [
                        'code' => 'element-dropped',
                        'message' => 'Dropped empty <div> element',
                        'severity' => 'warning',
                        'fidelity' => 'dropped',
                        'confidence' => 'exact',
                        'path' => '/code[1]/div[2]',
                    ],
                    self::unwrapped('div', '/code[1]/div[3]'),
                ],
            ],
        ];
    }

    /**
     * @param string $html
     * @param string $carve
     * @param list<array<string, mixed>> $rows
     */
    #[DataProvider('shapes')]
    public function testTheBytesStayAndTheBoundaryIsReported(string $html, string $carve, array $rows): void
    {
        $importer = new HtmlToCarve();
        $result = $importer->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $this->assertSame($rows, $result->report()['diagnostics']);
        $this->assertSame($result->report(), $importer->convertToAstWithReport($html)->report());
    }

    /**
     * The boundary is lost wherever the code span sits. `directAstInlineFlattens()`
     * defers to the cell and caption flatten paths, so a predicate built on it
     * reported nothing here.
     *
     * @return array<string, array{string, string, string}>
     */
    public static function slots(): array
    {
        return [
            'in a table cell' => [
                '<table><tr><td><code><div>a</div><div>b</div></code></td></tr></table>',
                "| `ab` |\n",
                '/table[1]/tr[1]/td[1]/code[1]',
            ],
            'in a figure caption' => [
                '<figure><img src="/i" alt="x"><figcaption><code><div>a</div><div>b</div></code></figcaption></figure>',
                "![x](/i)\n^ `ab`\n",
                '/figure[1]/figcaption[2]/code[1]',
            ],
            'behind an inline element' => [
                '<code><span><div>a</div><div>b</div></span></code>',
                "`ab`\n",
                '/code[1]',
            ],
        ];
    }

    #[DataProvider('slots')]
    public function testEverySlotReportsTheBoundary(string $html, string $carve, string $path): void
    {
        $result = (new HtmlToCarve())->convertWithReport($html);

        $this->assertSame($carve, $result->value);
        $rows = $result->report()['diagnostics'];
        $this->assertSame('structure-unspellable', $rows[0]['code']);
        $this->assertSame($path, $rows[0]['path']);
        $this->assertSame(self::BOUNDARY['message'], $rows[0]['message']);
        $this->assertSame(
            ['Unwrapped <div> inside <code>', 'Unwrapped <div> inside <code>'],
            array_column(array_slice($rows, 1), 'message'),
        );
    }

    /**
     * A `<code>` BESIDE a block is not a slot the block landed in.
     */
    public function testACodeSpanNextToABlockIsNoBoundary(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<span><code>a</code><div>b</div></span>');

        $rows = $result->report()['diagnostics'];
        $this->assertSame(['element-unwrapped'], array_column($rows, 'code'));
        $this->assertSame('Unwrapped unsupported <div> element', $rows[0]['message']);
    }

    /**
     * A fenced block's value can hold the newline, so nothing is lost there and
     * the ruling does not reach it.
     */
    public function testACodeBlockTakesNoBoundaryRow(): void
    {
        $result = (new HtmlToCarve())->convertWithReport('<pre><code><div>a</div><div>b</div></code></pre>');

        $this->assertSame("```\nab\n```\n", $result->value);
        $rows = $result->report()['diagnostics'];
        $this->assertSame(['element-unwrapped', 'element-unwrapped'], array_column($rows, 'code'));
        $this->assertSame(['/pre[1]/code[1]/div[1]', '/pre[1]/code[1]/div[2]'], array_column($rows, 'path'));
        $this->assertSame([
            'Unwrapped <div> inside <pre>',
            'Unwrapped <div> inside <pre>',
        ], array_column($rows, 'message'));
    }

    /**
     * The inline-only slots keep the separator AND the generic message; only the
     * verbatim slot is the one this ruling is about.
     */
    public function testAnInlineOnlySlotIsUnchanged(): void
    {
        $result = (new HtmlToCarve())->convertWithReport(
            '<details><summary><div class="t">Baseline</div><div class="s">Wide</div></summary><p>b</p></details>',
        );

        $rows = $result->report()['diagnostics'];
        $this->assertSame([], array_values(array_filter(
            array_column($rows, 'code'),
            static fn (string $code): bool => $code === 'structure-unspellable',
        )));
        $this->assertContains('Unwrapped unsupported <div> element', array_column($rows, 'message'));
    }
}

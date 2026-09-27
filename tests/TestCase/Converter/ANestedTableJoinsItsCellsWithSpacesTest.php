<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ANestedTableJoinsItsCellsWithSpacesTest extends TestCase
{
    public function testNestedTableAndReportOrder(): void
    {
        $html = '<table><tr><th>A</th><th>B</th></tr><tr><td>P</td><td>'
            . '<table><tr><td>J</td><td>F</td></tr></table></td></tr></table>';
        $result = (new HtmlToCarve())->convertWithReport($html);
        $this->assertSame("|= A |= B |\n| P | J F |\n", $result->value);
        $prefix = '/table[1]/tr[2]/td[2]/table[1]';
        $expected = [];
        foreach (['table' => '', 'tr' => '/tr[1]', 'td' => '/tr[1]/td[1]'] as $tag => $suffix) {
            $expected[] = [
                'code' => 'element-unwrapped',
                'message' => 'Unwrapped unsupported <' . $tag . '> element',
                'severity' => 'info',
                'fidelity' => 'degraded',
                'confidence' => 'exact',
                'path' => $prefix . $suffix,
            ];
        }
        $expected[] = array_replace($expected[2], ['path' => $prefix . '/tr[1]/td[2]']);
        $this->assertSame($expected, array_map(static fn ($row) => $row->toArray(), $result->diagnostics));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function tableProvider(): array
    {
        return [
            'inline markup across rows' => [
                '<table><tr><td><table><tr><td><b>J</b></td><td>F</td></tr><tr><td>K</td></tr></table>'
                    . '</td></tr></table>',
                "| *J* F K |\n",
            ],
            'caption' => [
                '<figure><img src="/i" alt="x"><figcaption><table><tr><td>J</td><td>F</td></tr>'
                    . '</table></figcaption></figure>',
                "![x](/i)\n^ J F\n",
            ],
        ];
    }

    #[DataProvider('tableProvider')]
    public function testTableProjection(string $html, string $expected): void
    {
        $this->assertSame($expected, (new HtmlToCarve())->convert($html));
    }
}

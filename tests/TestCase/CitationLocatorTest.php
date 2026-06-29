<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CitationLocatorTest extends TestCase
{
    private ParseLocatorTestable $ext;

    protected function setUp(): void
    {
        $this->ext = new ParseLocatorTestable();
    }

    /**
     * @return array<string, array{0: string, 1: array{label?: string, value?: string, suffixText?: string}}>
     */
    public static function locatorProvider(): array
    {
        return [
            'page abbrev' => ['p. 4', ['label' => 'page', 'value' => '4']],
            'page range with suffix' => ['pp. 33-35, 38 and passim', ['label' => 'page', 'value' => '33-35, 38', 'suffixText' => 'and passim']],
            'chapter abbrev' => ['chap. 2', ['label' => 'chapter', 'value' => '2']],
            'section symbol with space' => ["\xC2\xA7 5", ['label' => 'section', 'value' => '5']],
            'section symbol direct' => ["\xC2\xA75", ['label' => 'section', 'value' => '5']],
            'paragraph double symbol with space' => ["\xC2\xB6\xC2\xB6 2", ['label' => 'paragraph', 'value' => '2']],
            'section double symbol with space' => ["\xC2\xA7\xC2\xA7 3", ['label' => 'section', 'value' => '3']],
            'digit defaults to page' => ['4', ['label' => 'page', 'value' => '4']],
            'roman no label' => ['iv', ['suffixText' => 'iv']],
            'pageant boundary fail' => ['pageant', ['suffixText' => 'pageant']],
            'vol.2 digit boundary' => ['vol.2', ['label' => 'volume', 'value' => '2']],
            'voli letter boundary' => ['voli', ['suffixText' => 'voli']],
            'p. iv value' => ['p. iv', ['label' => 'page', 'value' => 'iv']],
            'trailing comma trim' => ['p. 4, see also', ['label' => 'page', 'value' => '4', 'suffixText' => 'see also']],
            'empty value page' => ['p.', ['label' => 'page']],
            'chapter with inline suffix' => ['chap. *two*', ['label' => 'chapter', 'suffixText' => '*two*']],
            'pages full word' => ['pages 5-10', ['label' => 'page', 'value' => '5-10']],
            'chapter full word boundary' => ['chapter 3', ['label' => 'chapter', 'value' => '3']],
            'volume abbrev' => ['vol. 2', ['label' => 'volume', 'value' => '2']],
            'section abbrev' => ['sec. 4', ['label' => 'section', 'value' => '4']],
            'figure abbrev' => ['fig. 7', ['label' => 'figure', 'value' => '7']],
            'note abbrev' => ['n. 3', ['label' => 'note', 'value' => '3']],
            'empty input' => ['', []],
            'roman only no prefix' => ['xiv', ['suffixText' => 'xiv']],
        ];
    }

    /**
     * @param string $input
     * @param array{label?: string, value?: string, suffixText?: string} $expected
     */
    #[DataProvider('locatorProvider')]
    public function testParseLocator(string $input, array $expected): void
    {
        $result = $this->ext->parseLocatorPublic($input);
        $this->assertSame($expected, $result);
    }
}

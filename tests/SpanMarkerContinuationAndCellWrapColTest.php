<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

class SpanMarkerContinuationAndCellWrapColTest extends TestCase
{
    /**
     * @throws \UnexpectedValueException
     *
     * @return iterable<string, array{array<mixed>}>
     */
    public static function astCases(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/fixtures/span-marker-wrap-reference.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($cases)) {
            throw new UnexpectedValueException('Expected an array of AST cases');
        }
        foreach ($cases as $index => $case) {
            if (!is_array($case)) {
                throw new UnexpectedValueException('Expected an AST case');
            }

            yield (string)$index => [$case];
        }
    }

    /**
     * @param array<string, mixed> $case
     *
     * @throws \UnexpectedValueException
     */
    #[DataProvider('astCases')]
    public function testAstAndCanonicalCarve(array $case): void
    {
        if (!is_string($case['s'] ?? null) || !is_array($case['ast'] ?? null) || !is_string($case['fmt'] ?? null)) {
            throw new UnexpectedValueException('Invalid AST case');
        }
        $document = CarveConverter::create()->parse($case['s']);
        $actual = (new AstCodec())->encode($document);
        self::stripPositions($actual);
        $expected = $case['ast'];
        self::sortObjectKeys($actual);
        self::sortObjectKeys($expected);
        self::assertSame($expected, $actual);
        self::assertSame($case['fmt'], (new CarveRenderer())->render($document));
    }

    /**
     * @throws \UnexpectedValueException
     *
     * @return iterable<string, array{array<mixed>}>
     */
    public static function htmlCases(): iterable
    {
        $cases = json_decode((string)file_get_contents(__DIR__ . '/fixtures/span-marker-wrap-html.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($cases)) {
            throw new UnexpectedValueException('Expected an array of HTML cases');
        }
        foreach ($cases as $index => $case) {
            if (!is_array($case) || !is_string($case['s'] ?? null) || !is_string($case['html'] ?? null)) {
                throw new UnexpectedValueException('Invalid HTML case');
            }

            yield (string)$index => [$case];
        }
    }

    /**
     * @param array<string, string> $case
     */
    #[DataProvider('htmlCases')]
    public function testHtml(array $case): void
    {
        self::assertSame($case['html'], trim(CarveConverter::create()->convert($case['s'])));
    }

    public function testTwoPartsCannotNameOneContinuationCell(): void
    {
        $ast = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [
                [
                    'type' => 'table',
                    'rows' => [
                        [
                            'type' => 'table_row',
                            'continuationLines' => 1,
                            'cells' => [
                                [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'children' => [
                                        ['type' => 'text', 'value' => 'a'],
                                        ['type' => 'cell_wrap', 'line' => 1],
                                        ['type' => 'text', 'value' => 'x'],
                                        ['type' => 'cell_wrap', 'line' => 1],
                                        ['type' => 'text', 'value' => 'y'],
                                    ],
                                    'colspan' => 2,
                                ], [
                                    'type' => 'table_cell',
                                    'header' => false,
                                    'children' => [],
                                    'span' => 'colspan',
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $document = (new AstCodec())->decode($ast);

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render($document);
    }

    public function testCodePayloadKeepsTheMarkerColumn(): void
    {
        $source = "| a | < |\n+ x | `y |\n+ z | w` |\n";
        $codec = new AstCodec();
        $ast = $codec->encode(CarveConverter::create()->parse($source));
        $json = json_encode($ast, JSON_THROW_ON_ERROR);

        self::assertMatchesRegularExpression('/"type":"code"[^}]*"cellWraps":\[\{"offset":1,"line":2,"col":1\}\]/', $json);
        self::assertSame($source, (new CarveRenderer())->render($codec->decode($ast)));
    }

    public function testAColumnOutsideTheOriginCellCannotMoveTextIntoItsNeighbor(): void
    {
        $ast = [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [[
                'type' => 'table',
                'rows' => [[
                    'type' => 'table_row',
                    'continuationLines' => 1,
                    'cells' => [[
                        'type' => 'table_cell',
                        'header' => false,
                        'children' => [
                            ['type' => 'text', 'value' => 'a'],
                            ['type' => 'cell_wrap', 'line' => 1, 'col' => 1],
                            ['type' => 'text', 'value' => 'x'],
                        ],
                    ], [
                        'type' => 'table_cell',
                        'header' => false,
                        'children' => [['type' => 'text', 'value' => 'b']],
                    ]],
                ]],
            ]],
        ];

        $this->expectException(SourceUnspellableException::class);
        (new CarveRenderer())->render((new AstCodec())->decode($ast));
    }

    /**
     * @param array<mixed> $value
     */
    private static function stripPositions(array &$value): void
    {
        unset($value['pos'], $value['srcByteLength']);
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::stripPositions($child);
            }
        }
    }

    /**
     * @param array<mixed> $value
     */
    private static function sortObjectKeys(array &$value): void
    {
        if (!array_is_list($value)) {
            ksort($value);
        }
        foreach ($value as &$child) {
            if (is_array($child)) {
                self::sortObjectKeys($child);
            }
        }
    }
}

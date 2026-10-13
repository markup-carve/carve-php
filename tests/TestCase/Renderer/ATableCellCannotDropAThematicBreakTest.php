<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ATableCellCannotDropAThematicBreakTest extends TestCase
{
    public static function cells(): array
    {
        $break = ['type' => 'thematic_break'];
        $paragraph = static fn (string $value): array => [
            'type' => 'paragraph',
            'children' => [['type' => 'text', 'value' => $value]],
        ];

        return [
            'lone break' => [[$break], false],
            'between paragraphs' => [[$paragraph('before'), $break, $paragraph('after')], false],
            'another nonempty cell' => [[$break], true],
        ];
    }

    #[DataProvider('cells')]
    public function testWriterRefusesAndReportsTheBreak(array $blocks, bool $anotherCell): void
    {
        $cells = [['type' => 'table_cell', 'header' => false, 'blocks' => $blocks]];
        if ($anotherCell) {
            $cells[] = ['type' => 'table_cell', 'header' => false, 'children' => [['type' => 'text', 'value' => 'next']]];
        }
        $document = (new AstCodec())->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'table', 'rows' => [['type' => 'table_row', 'cells' => $cells]]]],
        ]);
        $writer = new CarveRenderer();
        $writer->beginConversionDiagnosticCollection();

        try {
            $writer->render($document);
            self::fail('Expected a source refusal.');
        } catch (SourceUnspellableException $exception) {
            self::assertSame('thematic_break', $exception->nodeType);
            self::assertSame('a thematic break has no inline spelling in a table cell', $exception->reason);
        }

        $diagnostics = $writer->finishConversionDiagnosticCollection()['diagnostics'];
        self::assertContains(
            ['code' => 'structure-unspellable', 'node' => 'thematic_break'],
            array_map(static fn (array $diagnostic): array => [
                'code' => $diagnostic['code'],
                'node' => $diagnostic['node'],
            ], $diagnostics),
        );
    }

    public function testCliRefusesWithoutOutputOrTrace(): void
    {
        $json = '{"type":"document","srcByteLength":0,"children":[{"type":"table","rows":[{"type":"table_row","cells":[{"type":"table_cell","header":false,"blocks":[{"type":"thematic_break"}]}]}]}]}';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 3) . '/bin/carve', '--from-json', '--carve'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fwrite($pipes[0], $json);
        fclose($pipes[0]);
        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        self::assertSame(1, proc_close($process));
        self::assertSame('', $stdout);
        self::assertStringContainsString('cannot spell thematic_break: a thematic break has no inline spelling in a table cell', $stderr);
        self::assertStringNotContainsString('Stack trace', $stderr);
        self::assertStringNotContainsString('Fatal error', $stderr);
    }
}

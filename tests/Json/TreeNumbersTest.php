<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeNumbers;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * carve#6099, C2-A-219: a tree number is a finite binary64 value that keeps its value through encodeTree.
 */
final class TreeNumbersTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function tokens(): array
    {
        return [
            '2^53' => ['9007199254740992', true],
            '2^53 + 1' => ['9007199254740993', false],
            '-(2^53 + 1)' => ['-9007199254740993', false],
            '2^53 + 2' => ['9007199254740994', true],
            '20 digits' => ['12345678901234567890', false],
            '2^60 exact digits' => ['1152921504606846976', false],
            '2^60 shortest' => ['1152921504606847000', true],
            '21-digit fraction' => ['0.10000000000000000001', false],
            'exact decimal of 0.1' => ['0.1000000000000000055511151231257827021181583404541015625', false],
            'underflow' => ['2e-324', false],
            'overflow' => ['1e400', false],
            'negative overflow' => ['-1e400', false],
            '1.0' => ['1.0', true],
            '1E2' => ['1E2', true],
            '-0' => ['-0', true],
            '-0.0e5' => ['-0.0e5', true],
            '0.1' => ['0.1', true],
            '1e21' => ['1e21', true],
            '1e-7' => ['1e-7', true],
            'smallest subnormal' => ['5e-324', true],
            'largest finite' => ['1.7976931348623157e308', true],
            '-1.5E-7' => ['-1.5E-7', true],
            '100.000' => ['100.000', true],
            '0.30000000000000004' => ['0.30000000000000004', true],
            '0.3000000000000000444' => ['0.3000000000000000444', false],
        ];
    }

    #[DataProvider('tokens')]
    public function testKeeps(string $token, bool $keeps): void
    {
        $this->assertSame($keeps, TreeNumbers::keeps($token));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function refused(): array
    {
        $doc = static fn (string $body): string => '{"type":"document","astVersion":"2.0","children":[' . $body . ']}';
        $data = static fn (string $value): string => $doc(
            '{"type":"paragraph","data":{"carve.x":{"a/b":[1,"9007199254740993",' . $value . ']}},'
            . '"children":[{"type":"text","value":"t"}]}',
        );

        return [
            'data list item' => [$data('1e400'), '/children/0/data/carve.x/a~1b/2'],
            'after a string with a quote and digits' => [$data('0.10000000000000000001'), '/children/0/data/carve.x/a~1b/2'],
            'envelope' => [
                '{"astVersion":"2.0","document":{"type":"document","astVersion":"2.0","children":[],'
                . '"metadata":{"fields":{"n":{"kind":"number","value":2e-324}}}}}',
                '/document/metadata/fields/n/value',
            ],
        ];
    }

    #[DataProvider('refused')]
    public function testRefusedAtItsPath(string $json, string $path): void
    {
        try {
            TreeReader::read($json);
        } catch (TreeError $e) {
            $this->assertSame('number', $e->reason);
            $this->assertSame($path, $e->pointer);

            return;
        }
        $this->fail('accepted');
    }

    public function testCheckRefusesToo(): void
    {
        $this->expectException(TreeError::class);
        TreeReader::check(
            '{"type":"document","astVersion":"2.0","children":[],"metadata":{"fields":{"n":{"kind":"number","value":9007199254740993}}}}',
        );
    }

    public function testAcceptedSpellingsAreWrittenCanonically(): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":[],"metadata":{"fields":{"n":{"kind":"list","value":['
            . '{"kind":"number","value":1.0},{"kind":"number","value":1E2},{"kind":"number","value":-0},'
            . '{"kind":"number","value":1e21},{"kind":"number","value":5e-324},{"kind":"number","value":-1.5E-7}]}}}}';
        $out = TreeEncoder::json(TreeReader::read($json), null);
        $this->assertStringContainsString('"value":1}', $out);
        $this->assertStringContainsString('"value":100}', $out);
        $this->assertStringContainsString('"value":0}', $out);
        $this->assertStringContainsString('"value":1e+21}', $out);
        $this->assertStringContainsString('"value":5e-324}', $out);
        $this->assertStringContainsString('"value":-1.5e-7}', $out);
    }

    public function testSuppliedTreeWithAnIntegerBeyondBinary64IsRefused(): void
    {
        $tree = [
            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [],
            'metadata' => ['fields' => ['n' => ['kind' => 'number', 'value' => 9007199254740993]]],
        ];
        $this->expectException(TreeError::class);
        Carve::render($tree);
    }

    /**
     * @return array<string, array{0: int, 1: bool}>
     */
    public static function transformedIntegers(): array
    {
        return [
            '2^53' => [9007199254740992, false],
            '2^53 + 1' => [9007199254740993, true],
            '-(2^53 + 1)' => [-9007199254740993, true],
        ];
    }

    /**
     * A document transform's view takes the full check for an integer beyond 2^53.
     */
    #[DataProvider('transformedIntegers')]
    public function testTransformedViewWithAnIntegerBeyondBinary64Fails(int $value, bool $fails): void
    {
        $set = static function (Node $view) use ($value): Node {
            $view->f['metadata'] = ['fields' => ['n' => ['kind' => 'number', 'value' => $value]]];

            return $view;
        };
        $result = Carve::renderWithDocumentTransforms("Text.\n", [new DocumentTransform('number', $set)]);
        $failed = array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'extension-transform-failed');
        $this->assertSame($fails, $failed !== []);
    }
}

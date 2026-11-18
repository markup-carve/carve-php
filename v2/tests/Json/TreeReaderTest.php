<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Test\Json;

use MarkupCarve\Carve2\Carve2;
use MarkupCarve\Carve2\Json\TreeError;
use MarkupCarve\Carve2\Json\TreeReader;
use MarkupCarve\Carve2\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class TreeReaderTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function accepted(): array
    {
        $out = [];
        foreach (glob(dirname(__DIR__, 2) . '/spec/trees/{nodes,examples-*}.json', GLOB_BRACE) ?: [] as $file) {
            $data = json_decode((string)file_get_contents($file), true);
            foreach ($data['trees'] as $entry) {
                $out[basename($file) . ':' . $entry['id']] = [json_encode($entry['tree'], JSON_THROW_ON_ERROR)];
            }
        }

        return $out;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function refused(): array
    {
        $data = json_decode((string)file_get_contents(dirname(__DIR__, 2) . '/spec/trees/refused.json'), true);
        $out = [];
        foreach ($data['trees'] as $entry) {
            $out[$entry['id']] = [json_encode($entry['tree'], JSON_THROW_ON_ERROR)];
        }

        return $out;
    }

    #[DataProvider('accepted')]
    public function testAcceptedTrees(string $json): void
    {
        self::assertInstanceOf(Node::class, TreeReader::read($json));
    }

    #[DataProvider('refused')]
    public function testRefusedTrees(string $json): void
    {
        $this->expectException(TreeError::class);
        TreeReader::read($json);
    }

    public function testC2A013EnvelopeVersionIsRefused(): void
    {
        $this->assertReason('envelope-version', '{"astVersion":"2.1","document":{"type":"document","astVersion":"2.0","children":[]}}');
    }

    public function testC2A017DuplicateDeclarationIsRefused(): void
    {
        $declaration = '{"name":"org.example.x","version":"1","required":false,"slots":[]}';
        $this->assertReason(
            'duplicate-extension-declaration',
            '{"type":"document","astVersion":"2.0","children":[],"extensions":[' . $declaration . ',' . $declaration . ']}',
        );
    }

    public function testC2A018RequiredExtensionIsRefused(): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":[],"extensions":[{"name":"'
            . 'org.example.x","version":"1","required":true,"slots":[]}]}';
        $this->assertReason('required-extension', $json);
        self::assertInstanceOf(Node::class, TreeReader::read($json, ['extensions' => ['org.example.x' => '1']]));
    }

    public function testC2A029UndeclaredDataIsRefused(): void
    {
        $this->assertReason('data-undeclared', '{"type":"document","astVersion":"2.0","children":[],"data":{"org.example.x":1}}');
    }

    public function testC2A035DuplicateAttributeKeyIsRefused(): void
    {
        $this->assertReason('duplicate-attribute-key', '{"type":"document","astVersion":"2.0","children":[],"attrs":{"keyValues":[['
            . '"X","1"],["x","2"]]}}');
    }

    public function testC2A171DepthIsRefused(): void
    {
        $child = '{"type":"paragraph","children":[]}';
        for ($i = 0; $i < 201; $i++) {
            $child = '{"type":"block_quote","children":[' . $child . ']}';
        }
        $this->assertReason('depth', '{"type":"document","astVersion":"2.0","children":[' . $child . ']}');
    }

    public function testC2A171ParserTreesAtBothLimitsAreAccepted(): void
    {
        $source = str_repeat('> ', 200) . 'x' . "\n\n" . str_repeat('- ', 100) . 'y' . "\n\n" . str_repeat('{_', 200) . 'z'
            . str_repeat('_}', 200);
        self::assertInstanceOf(Node::class, TreeReader::read(Carve2::toJson($source)));
    }

    public function testC2A171InlineDepthIsRefused(): void
    {
        $child = '{"type":"text","value":"x"}';
        for ($i = 0; $i < 201; $i++) {
            $child = '{"type":"' . ($i % 2 === 0 ? 'emphasis' : 'link') . '","children":[' . $child . ']' . ($i % 2 === 0
                ? '' : ',"href":"u"') . '}';
        }
        $this->assertReason('depth', '{"type":"document","astVersion":"2.0","children":[{"type":"paragraph","children":['
            . $child . ']}]}');
    }

    public function testC2A171ListAndInlineNoteLevelsAreCounted(): void
    {
        $block = '{"type":"paragraph","children":[]}';
        for ($i = 0; $i < 101; $i++) {
            $block = '{"type":"list","marker":{"kind":"bullet","char":"-"},"spacing":"tight","children":['
                . '{"type":"list_item","children":[' . $block . ']}]}';
        }
        $this->assertReason('depth', '{"type":"document","astVersion":"2.0","children":[' . $block . ']}');

        $block = '{"type":"paragraph","children":[]}';
        for ($i = 0; $i < 101; $i++) {
            $block = '{"type":"definition_list","children":['
                . '{"type":"definition_term","children":[{"type":"text","value":"x"}]},'
                . '{"type":"definition_description","children":[' . $block . ']}]}';
        }
        $this->assertReason('depth', '{"type":"document","astVersion":"2.0","children":[' . $block . ']}');

        $inline = '{"type":"text","value":"x"}';
        for ($i = 0; $i < 201; $i++) {
            $inline = '{"type":"inline_footnote","children":[' . $inline . ']}';
        }
        $this->assertReason('depth', '{"type":"document","astVersion":"2.0","children":['
            . '{"type":"paragraph","children":[' . $inline . ']}]}');
    }

    public function testC2A171IncludeChildrenStartAgainAtLevelZero(): void
    {
        $quotes = static function (int $n, string $inner): string {
            for ($i = 0; $i < $n; $i++) {
                $inner = '{"type":"block_quote","children":[' . $inner . ']}';
            }

            return $inner;
        };
        $include = '{"type":"include","path":"a.crv","file":"a.crv","children":[' . $quotes(
            150,
            '{"type":"paragraph","children":[]}',
        ) . ']}';
        self::assertInstanceOf(Node::class, TreeReader::read('{"type":"document","astVersion":"2.0","children":['
            . $quotes(150, $include) . ']}'));
    }

    public function testC2A181ReversedRangeIsRefused(): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":[{"type":"include","path":"a","options":{"rows":"9-2"}}]}';
        $this->assertReason('include-range', $json);
    }

    public function testC2A189NodeInDataIsRefused(): void
    {
        $json = '{"type":"document","astVersion":"2.0","children":[],'
            . '"extensions":[{"name":"org.example.x","version":"1","required":false,"slots":[]}],'
            . '"data":{"org.example.x":[{"type":"text","value":"x"}]}}';
        $this->assertReason('data-holds-node', $json);
    }

    private function assertReason(string $reason, string $json): void
    {
        try {
            TreeReader::read($json);
            self::fail('Expected TreeError');
        } catch (TreeError $error) {
            self::assertSame($reason, $error->reason);
            self::assertStringStartsWith('/', $error->pointer);
        }
    }
}

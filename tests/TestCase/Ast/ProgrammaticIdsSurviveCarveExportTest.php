<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ProgrammaticIdsSurviveCarveExportTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function nodes(): array
    {
        $text = ['type' => 'text', 'value' => 'word'];
        $paragraph = ['type' => 'paragraph', 'children' => [$text]];
        $cases = [];
        foreach ([[], ['.class'], ['#id'], ['key']] as $order) {
            $attrs = ['id' => 'authored', 'classes' => ['note'], 'keyValues' => ['key' => 'value']];
            if ($order !== []) {
                $attrs['order'] = $order;
            }
            $suffix = $order === [] ? 'absent order' : implode(',', $order);
            $cases['paragraph ' . $suffix] = [$paragraph + ['attrs' => $attrs], '/children/0'];
            $cases['span ' . $suffix] = [
                ['type' => 'paragraph', 'children' => [['type' => 'span', 'attrs' => $attrs, 'children' => [$text]]]],
                '/children/0/children/0',
            ];
            $cases['div ' . $suffix] = [
                ['type' => 'div', 'attrs' => $attrs, 'children' => [$paragraph]],
                '/children/0',
            ];
        }

        return $cases;
    }

    /**
     * @param array<string, mixed> $node
     * @param string $path
     */
    #[DataProvider('nodes')]
    public function testAnIdWithoutASourceSlotSurvives(array $node, string $path): void
    {
        $codec = new AstCodec();
        $doc = $codec->decode(['type' => 'document', 'srcByteLength' => 0, 'children' => [$node]]);
        $source = (new CarveRenderer())->render($doc);
        $this->assertStringContainsString('#authored', $source);
        $actual = $codec->encode((new CarveConverter())->parse($source));
        foreach (explode('/', ltrim($path, '/')) as $key) {
            $actual = $actual[$key];
        }
        $this->assertSame('authored', $actual['attrs']['id'] ?? null);
        $this->assertSame(['note'], $actual['attrs']['classes'] ?? null);
        $this->assertSame(['key' => 'value'], $actual['attrs']['keyValues'] ?? null);
        $this->assertSame(1, substr_count($source, '.note'));
    }

    public function testAGeneratedHeadingIdIsStillOmitted(): void
    {
        $codec = new AstCodec();
        $doc = $codec->decode([
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'heading', 'level' => 1, 'attrs' => ['id' => 'word'], 'children' => [['type' => 'text', 'value' => 'word']]]],
        ]);
        $this->assertSame("# word\n", (new CarveRenderer())->render($doc));
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Extension\Claim;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Spoiler;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-X-015, C2-X-022: a transform whose fallback still holds an extension node after its unqualified inline extensions
 * are lowered to spans is refused and the core node stays, as in carve-js.
 */
final class RefusedTransformTest extends TestCase
{
    public function testNestedSpoilerClaimsTheOuterOneAndLowersTheInner(): void
    {
        $json = Carve::toJson("A :spoiler[nested :spoiler[in]] word.\n", ['extensions' => [Spoiler::bundle()]]);
        $tree = self::withoutPositions(json_decode($json, true, 512, JSON_THROW_ON_ERROR));
        self::assertIsArray($tree);
        $blocks = $tree['children'];
        self::assertIsArray($blocks);
        self::assertIsArray($blocks[0]);

        self::assertSame(
            [
                ['type' => 'text', 'value' => 'A '],
                [
                    'children' => [],
                    'fallback' => [
                        ['type' => 'text', 'value' => 'nested '],
                        ['attrs' => ['classes' => ['ext-spoiler']], 'children' => [['type' => 'text', 'value' => 'in']], 'type' => 'span'],
                    ],
                    'name' => 'carve.spoiler',
                    'source' => ['body' => 'nested :spoiler[in]', 'form' => 'inline', 'name' => 'spoiler'],
                    'type' => 'inline_extension',
                    'version' => '1',
                ],
                ['type' => 'text', 'value' => ' word.'],
            ],
            $blocks[0]['children'] ?? null,
        );
    }

    public function testQualifiedNodeInTheFallbackIsARefusalAndNoLintFinding(): void
    {
        $source = "A :product[x y] b.\n";
        $options = ['extensions' => self::productWithQualifiedFallback()];
        $parsed = Carve::parseWithDiagnostics($source, $options);
        $codes = array_map(static fn (Diagnostic $d): string => $d->code . ' ' . $d->channel . ' ' . $d->path, $parsed->diagnostics);

        self::assertSame(['extension-fallback-rendered render-loss /children/0/children/1'], $codes);
        $read = Carve::decodeTree(Carve::toJson($source, $options), $options);
        self::assertSame($source, Carve::write($read));
        self::assertSame([], Carve::lint($source, $options));
    }

    /**
     * @return array<\MarkupCarve\Carve\Extension\ExtensionDefinition>
     */
    private static function productWithQualifiedFallback(): array
    {
        $text = static function (string $value): Node {
            $node = new Node('text');
            $node->f = ['value' => $value];

            return $node;
        };
        $transform = static function (Claim $claim) use ($text): Node {
            $inner = new Node('inline_extension', []);
            $inner->f = ['name' => 'shop.inner', 'version' => '1', 'props' => [], 'slots' => [], 'fallback' => [$text('y')]];
            $node = new Node('inline_extension', []);
            $node->f = ['name' => 'shop.product', 'version' => '1', 'props' => [], 'slots' => [], 'fallback' => [$text('x '), $inner]];

            return $node;
        };

        return [
            new ExtensionDefinition('shop.inner', '1'),
            new ExtensionDefinition('shop.product', '1', matcher: ['form' => 'inline', 'name' => 'product'], transform: $transform),
        ];
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documents(): array
    {
        return [
            'paragraph' => [
                "A :spoiler[nested :spoiler[in]] word.\n",
                '<p>A <span class="spoiler">nested <span class="ext-spoiler">in</span></span> word.</p>',
            ],
            'depth three with attributes' => [
                "A :spoiler[a :spoiler[b :spoiler[c]{.x}]] d.\n",
                '<p>A <span class="spoiler">a <span class="ext-spoiler">b <span class="ext-spoiler x">c</span></span></span> d.</p>',
            ],
            'list item' => [
                "- A :spoiler[nested :spoiler[in]] word.\n",
                "<ul>\n  <li>A <span class=\"spoiler\">nested <span class=\"ext-spoiler\">in</span></span> word.</li>\n</ul>",
            ],
            'emphasis' => [
                "A *x :spoiler[nested :spoiler[in]] y*.\n",
                '<p>A <strong>x <span class="spoiler">nested <span class="ext-spoiler">in</span></span> y</strong>.</p>',
            ],
        ];
    }

    #[DataProvider('documents')]
    public function testTreeReadsBack(string $source, string $html): void
    {
        $options = ['extensions' => [Spoiler::bundle()]];
        $read = Carve::decodeTree(Carve::toJson($source, $options), $options);

        self::assertSame($html, trim(Carve::render($source, $options)));
        self::assertSame($html, trim(Carve::render($read, $options)));
        self::assertSame($source, Carve::write($read));
    }

    private static function withoutPositions(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }
        unset($value['pos']);

        return array_map(self::withoutPositions(...), $value);
    }
}

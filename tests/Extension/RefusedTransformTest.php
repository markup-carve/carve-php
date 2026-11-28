<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Extension\Spoiler;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * C2-X-015: a transform whose node does not read back as a tree is refused and the core node stays, as in carve-js. The
 * fallback of an outer spoiler would hold the core inner spoiler, which no fallback may hold, so the inner one is claimed.
 */
final class RefusedTransformTest extends TestCase
{
    public function testNestedSpoilerClaimsTheInnerOne(): void
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
                    'children' => [
                        ['type' => 'text', 'value' => 'nested '],
                        [
                            'children' => [],
                            'fallback' => [['type' => 'text', 'value' => 'in']],
                            'name' => 'carve.spoiler',
                            'source' => ['body' => 'in', 'form' => 'inline', 'name' => 'spoiler'],
                            'type' => 'inline_extension',
                            'version' => '1',
                        ],
                    ],
                    'name' => 'spoiler',
                    'type' => 'inline_extension',
                ],
                ['type' => 'text', 'value' => ' word.'],
            ],
            $blocks[0]['children'] ?? null,
        );
    }

    public function testRefusalIsARenderLossAndNoLintFinding(): void
    {
        $source = "A :spoiler[nested :spoiler[in]] word.\n";
        $options = ['extensions' => [Spoiler::bundle()]];
        $codes = array_map(
            static fn (Diagnostic $d): string => $d->code . ' ' . $d->channel . ' ' . $d->path,
            Carve::parseWithDiagnostics($source, $options)->diagnostics,
        );

        self::assertSame(['extension-fallback-rendered render-loss /children/0/children/1'], $codes);
        self::assertSame([], Carve::lint($source, ['extensions' => ['carve.spoiler']]));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function documents(): array
    {
        return [
            'paragraph' => [
                "A :spoiler[nested :spoiler[in]] word.\n",
                '<p>A <span class="ext-spoiler">nested <span class="spoiler">in</span></span> word.</p>',
            ],
            'depth three with attributes' => [
                "A :spoiler[a :spoiler[b :spoiler[c]{.x}]] d.\n",
                '<p>A <span class="ext-spoiler">a <span class="ext-spoiler">b <span class="spoiler x">c</span></span></span> d.</p>',
            ],
            'list item' => [
                "- A :spoiler[nested :spoiler[in]] word.\n",
                "<ul>\n  <li>A <span class=\"ext-spoiler\">nested <span class=\"spoiler\">in</span></span> word.</li>\n</ul>",
            ],
            'emphasis' => [
                "A *x :spoiler[nested :spoiler[in]] y*.\n",
                '<p>A <strong>x <span class="ext-spoiler">nested <span class="spoiler">in</span></span> y</strong>.</p>',
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

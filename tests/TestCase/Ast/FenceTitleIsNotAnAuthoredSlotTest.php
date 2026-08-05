<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * `attrs.order` is the SOURCE-APPEARANCE order of authored attribute slots.
 *
 * A code fence title is written after the language word - `` ``` php "x.php" ``
 * - and never as `{title=...}`, so the `title` key the parser synthesizes from
 * it has no authored position to record. This engine recorded one anyway, so a
 * serialized tree claimed a slot the source never had. carve-js publishes none.
 *
 * The distinction is authored versus synthesized, not "no title ever gets a
 * slot": a `{title=...}` line IS authored and keeps its entry.
 */
class FenceTitleIsNotAnAuthoredSlotTest extends TestCase
{
    private AstCodec $codec;

    protected function setUp(): void
    {
        $this->codec = new AstCodec();
    }

    /**
     * @return array<string, mixed>
     */
    private function codeBlockAttrs(string $source): array
    {
        $encoded = $this->codec->encode((new CarveConverter())->parse($source));
        foreach ($encoded['children'] ?? [] as $child) {
            if (is_array($child) && ($child['type'] ?? null) === 'code_block') {
                $attrs = $child['attrs'] ?? [];
                self::assertIsArray($attrs);

                return $attrs;
            }
        }

        self::fail('no code_block in the serialized tree');
    }

    public function testASynthesizedTitleKeyRecordsNoOrderSlot(): void
    {
        $attrs = $this->codeBlockAttrs("``` php \"src/Auth.php\"\n\$ok = true;\n```\n");

        self::assertSame(['title' => 'src/Auth.php'], $attrs['keyValues'] ?? null);
        self::assertArrayNotHasKey('order', $attrs);
    }

    public function testAnAuthoredSlotIsStillRecordedBesideASynthesizedTitle(): void
    {
        $attrs = $this->codeBlockAttrs("{#auth}\n``` php \"src/Auth.php\"\n\$ok = true;\n```\n");

        self::assertSame('auth', $attrs['id'] ?? null);
        self::assertSame(['#id'], $attrs['order'] ?? null);
    }

    public function testTheAbsentOrderSurvivesADecodeAndReEncode(): void
    {
        // The decoder used to invent an `order` from the storage keys whenever
        // the wire carried none, so the entry this fix removes came straight
        // back on the next encode.
        $source = "``` php \"src/Auth.php\"\n\$ok = true;\n```\n";
        $codec = new AstCodec();
        $encoded = $codec->encode((new CarveConverter())->parse($source));

        self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
    }

    public function testAnAuthoredTitleAttributeKeepsItsOrderSlot(): void
    {
        // `{title=...}` wins over the opener and IS authored, so it is recorded.
        $attrs = $this->codeBlockAttrs("{title=\"attr\"}\n``` php \"src/Auth.php\"\n\$ok = true;\n```\n");

        self::assertSame(['title' => 'attr'], $attrs['keyValues'] ?? null);
        self::assertSame(['title'], $attrs['order'] ?? null);
    }
}

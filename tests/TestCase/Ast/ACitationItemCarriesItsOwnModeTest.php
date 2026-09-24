<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\AstDecodeException;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §31 (CARVE-P12-053): the ITEM's `mode` is the real field and the
 * group's is its summary.
 *
 * `mode` sat in the item map as pass-through state: it round-tripped because
 * an item is an opaque array, and nothing read it. So a group whose items
 * disagree - the only shape the field exists for - rendered as if every item
 * were parenthetical, and a group carrying only the summary published items
 * with no mode at all, a value divergence against carve-js.
 */
final class ACitationItemCarriesItsOwnModeTest extends TestCase
{
    /**
     * @param array<int, array<string, mixed>> $items
     * @param string|null $groupMode
     * @param string $raw
     *
     * @return array<string, mixed>
     */
    private static function payload(array $items, string $raw, ?string $groupMode = null): array
    {
        $group = ['type' => 'citation_group', 'items' => $items, 'raw' => $raw];
        if ($groupMode !== null) {
            $group['mode'] = $groupMode;
        }

        return [
            'type' => 'document',
            'srcByteLength' => 0,
            'children' => [['type' => 'paragraph', 'children' => [$group]]],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private static function items(?string $first, ?string $second): array
    {
        $items = [];
        foreach ([$first, $second] as $index => $mode) {
            $item = ['type' => 'citation', 'key' => $index === 0 ? 'a' : 'b', 'suppressAuthor' => false];
            if ($mode !== null) {
                $item['mode'] = $mode;
            }
            $items[] = $item;
        }

        return $items;
    }

    private static function converter(): CarveConverter
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension());

        return $converter;
    }

    private static function group(Node $node): CitationGroup
    {
        if ($node instanceof CitationGroup) {
            return $node;
        }
        foreach ($node->getChildren() as $child) {
            if (self::holdsAGroup($child)) {
                return self::group($child);
            }
        }

        self::fail('The tree holds no citation group');
    }

    private static function holdsAGroup(Node $node): bool
    {
        if ($node instanceof CitationGroup) {
            return true;
        }
        foreach ($node->getChildren() as $child) {
            if (self::holdsAGroup($child)) {
                return true;
            }
        }

        return false;
    }

    public function testAGroupWhoseItemsDisagreeRoundTripsIntact(): void
    {
        $codec = new AstCodec();
        $payload = self::payload(self::items('integral', null), '[@a; @b]');

        // No group summary on the way out: the items differ, so a flag would
        // claim the parenthetical item is integral too.
        self::assertSame($payload, $codec->encode($codec->decode($payload)));
    }

    public function testASummaryOnlyTreeHasTheModeReadOntoItsItems(): void
    {
        $codec = new AstCodec();
        $decoded = $codec->decode(self::payload(self::items(null, null), '[+@a; @b]', 'integral'));

        self::assertSame(
            self::payload(self::items('integral', 'integral'), '[+@a; @b]', 'integral'),
            $codec->encode($decoded),
        );
    }

    public function testASummaryThatContradictsItsItemsIsRefused(): void
    {
        $this->expectException(AstDecodeException::class);
        $this->expectExceptionMessage('makes the ITEM authoritative');

        (new AstCodec())->decode(self::payload(self::items('integral', null), '[+@a; @b]', 'integral'));
    }

    public function testASummaryAgreeingWithItsItemsIsKept(): void
    {
        $codec = new AstCodec();
        $payload = self::payload(self::items('integral', 'integral'), '[+@a; @b]', 'integral');

        self::assertSame($payload, $codec->encode($codec->decode($payload)));
    }

    public function testTheGroupFlagDerivesFromItsItems(): void
    {
        $codec = new AstCodec();

        self::assertTrue(self::group($codec->decode(self::payload(self::items('integral', 'integral'), '[+@a; @b]')))->isIntegral());
        self::assertFalse(self::group($codec->decode(self::payload(self::items('integral', null), '[@a; @b]')))->isIntegral());
    }

    public function testAnItemlessGroupIsNotIntegral(): void
    {
        self::assertFalse((new CitationGroup([], '[]', true))->isIntegral());
    }

    public function testASourceSpelledGroupMarksEveryItem(): void
    {
        $document = self::converter()->parse("Only [+@a; @b] here.\n");
        $group = self::group($document);

        self::assertTrue($group->isIntegral());
        foreach ($group->getItems() as $item) {
            self::assertSame('integral', $item['mode'] ?? null);
        }
    }

    public function testAnAllIntegralGroupStillWrapsTheWholeGroup(): void
    {
        $converter = self::converter();
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/spec/tests/corpus-optional/21-citations-integral.crv',
        );

        self::assertStringContainsString(
            '<span class="citation" data-cite-mode="integral">[<a data-cite-key="smith2020"',
            $converter->render($converter->parse($source)),
        );
    }

    public function testAMixedGroupMarksTheItemAndNotTheGroup(): void
    {
        $converter = self::converter();
        $source = (string)file_get_contents(
            dirname(__DIR__, 2) . '/spec/tests/corpus-optional/22-citations-group-marker-vs-item.crv',
        );
        $document = $converter->parse($source);

        // What an editing API does: take the mode off one item of a group the
        // source spelled whole.
        $group = self::group($document);
        $items = $group->getItems();
        unset($items[1]['mode']);
        $group->setItems($items);

        $html = $converter->render($document);

        self::assertStringContainsString(
            '[<span class="citation" data-cite-mode="integral"><a data-cite-key="a" href="#ref-a">1</a></span>, '
                . '<a data-cite-key="b" href="#ref-b">2</a>]',
            $html,
        );
    }

    public function testAMixedGroupIsReportedDegradedByTheProseMirrorBridge(): void
    {
        $document = (new AstCodec())->decode(self::payload(self::items('integral', null), '[@a; @b]'));
        $renderer = new ProseMirrorRenderer();
        $editor = $renderer->render($document);

        // CarveKit has one flag for the whole group and no per-item mode, so
        // the marking cannot ride through the editor. It is reported, not lost
        // in silence.
        self::assertFalse($editor['content'][0]['content'][0]['attrs']['integral']);
        self::assertArrayHasKey('citation_group', $renderer->degradedTypes());

        $back = self::group((new ProseMirrorToCarve())->convert($editor));
        self::assertFalse($back->isIntegral());
    }

    public function testTheEditorsWholeGroupFlagReachesTheItems(): void
    {
        $document = (new AstCodec())->decode(self::payload(self::items('integral', 'integral'), '[+@a; @b]', 'integral'));
        $editor = (new ProseMirrorRenderer())->render($document);
        $group = $editor['content'][0]['content'][0];
        foreach ($group['attrs']['items'] as $index => $item) {
            unset($group['attrs']['items'][$index]['mode']);
        }
        $editor['content'][0]['content'][0] = $group;

        $back = self::group((new ProseMirrorToCarve())->convert($editor));

        self::assertTrue($back->isIntegral());
        self::assertSame('integral', $back->getItems()[1]['mode'] ?? null);
    }
}

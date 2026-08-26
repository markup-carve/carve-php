<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

final class TypedCitationItemTest extends TestCase
{
    /**
     * Source with two citation items.
     *
     * @var string
     */
    private const SOURCE = "See [@a; see @bb, p. 4].\n";

    public function testEachCitationItemHasATypeAndItsOwnPosition(): void
    {
        $converter = $this->converter();
        $converter->getParser()->enablePositionTracking();
        $codec = new AstCodec();
        $encoded = $codec->encode($converter->parse(self::SOURCE));
        $items = $encoded['children'][0]['children'][1]['items'];

        self::assertSame(['citation', 'citation'], array_column($items, 'type'));
        self::assertSame([5, 9], array_column(array_column($items, 'pos'), 'startOffset'));
        self::assertSame([7, 22], array_column(array_column($items, 'pos'), 'endOffset'));
        self::assertSame($encoded, $codec->encode($codec->decode($encoded)));
    }

    public function testDenyingCitationReportsEveryItemAndKeepsAuthoredText(): void
    {
        $converter = $this->converter(
            Profile::full()->denyInline(['citation'])->onDisallowed(Profile::ACTION_TO_TEXT),
        );

        $html = $converter->convert(self::SOURCE);

        self::assertStringContainsString('[@a; see @bb, p. 4]', $html);
        self::assertSame(
            ['citation', 'citation'],
            array_map(static fn ($violation): string => $violation->nodeType, $converter->getProfileViolations()),
        );
    }

    public function testMultilineItemsKeepRequiredPositions(): void
    {
        $converter = $this->converter();
        $converter->getParser()->enablePositionTracking();
        $encoded = (new AstCodec())->encode($converter->parse("See [@a;\n@bb].\n"));
        $items = $encoded['children'][0]['children'][1]['items'];

        self::assertSame([5, 9], array_column(array_column($items, 'pos'), 'startOffset'));
        self::assertSame([7, 12], array_column(array_column($items, 'pos'), 'endOffset'));
    }

    private function converter(?Profile $profile = null): CarveConverter
    {
        $converter = new CarveConverter(profile: $profile);
        $converter->addExtension(new CitationsExtension());

        return $converter;
    }
}

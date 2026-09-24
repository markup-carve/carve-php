<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\NodeType;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * A caption is part of its host, so only the host is deniable.
 *
 * `docs/profiles.md` leaves `caption` out of the vocabulary because `figure`,
 * `figure_group` and `table` each carry their caption as an inline array
 * (carve#2207, carve#2239). This engine's parse tree holds a Caption block under
 * a figure anyway, and routing that internal node through the profile check made
 * an allow list naming `figure` answer false for it: the `<figcaption>` came back
 * as a `<p>` and a violation was reported for a name no host can act on.
 *
 * Both cases below are needed. The first alone would pass a fix that lets a
 * caption outlive a denied host; the second alone passes in the broken state.
 */
class ACaptionIsNotDeniableTest extends TestCase
{
    /**
     * @var string
     */
    private const CAPTIONED_FIGURE = "![alt](/i.png)\n^ A caption\n";

    public function testAnAllowlistNamingTheHostsKeepsTheCaption(): void
    {
        $profile = Profile::full()->allowBlock([
            NodeType::FIGURE,
            NodeType::TABLE,
            NodeType::PARAGRAPH,
        ]);
        $converter = CarveConverter::create(profile: $profile);
        $html = $converter->convert(self::CAPTIONED_FIGURE);

        $this->assertStringContainsString('<figcaption>A caption</figcaption>', $html);
        $this->assertSame(
            (new CarveConverter())->convert(self::CAPTIONED_FIGURE),
            $html,
            'an allow list naming the caption host still changed the figure',
        );
        $this->assertSame([], $converter->getProfileViolations(), 'a caption was reported as a deniable type');
    }

    /**
     * Out of the vocabulary AND still allowed - the two halves that only work
     * together. Taking the name out on its own is what dropped the caption:
     * `isTypeAllowed()` answers for an unlisted type by excluding it whenever a
     * profile sets an allow list at all.
     */
    public function testTheTypeLeavesTheVocabularyWithoutBecomingDeniable(): void
    {
        $this->assertNotContains(NodeType::CAPTION, NodeType::allBlockTypes());
        $this->assertNotContains(NodeType::CAPTION, NodeType::allInlineTypes());

        $restrictive = (new Profile())
            ->allowBlock([NodeType::PARAGRAPH])
            ->allowInline([NodeType::TEXT]);

        $this->assertTrue(
            $restrictive->isTypeAllowed(NodeType::CAPTION),
            'a profile with an allow list denied a caption, which is not a deniable type',
        );
        $this->assertNull($restrictive->getReasonDisallowed(NodeType::CAPTION));
    }

    public function testDenyingTheHostDropsTheCaptionWithIt(): void
    {
        $profile = Profile::full()
            ->denyBlock([NodeType::FIGURE])
            ->onDisallowed(Profile::ACTION_STRIP);
        $converter = CarveConverter::create(profile: $profile);
        $html = $converter->convert(self::CAPTIONED_FIGURE);

        $this->assertSame('', $html);
        $this->assertStringNotContainsString('A caption', $html);
        $this->assertSame(
            [NodeType::FIGURE],
            array_map(
                static fn ($violation): string => $violation->nodeType,
                $converter->getProfileViolations(),
            ),
            'denying the host reported anything other than the one decision that was made',
        );
    }
}

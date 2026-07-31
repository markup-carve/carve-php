<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * `to_text` degrades a denied construct; it does not delete it.
 *
 * The action is named for what it promises. When extractTextContent had no arm
 * for a node's payload it returned '' and the node was removed instead, so
 * denying `substitution` under Profile::comment() erased both words - the same
 * silent-loss class as the vocabulary hole, in a different place.
 */
class ProfileDegradationTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: string, 2: bool, 3: string}>
     */
    public static function constructProvider(): array
    {
        return [
            // source, type, isBlock, text that MUST survive the denial
            'substitution' => ['a {~old~>new~} b', 'substitution', false, 'old'],
            'citation group' => ["[@key] here\n\n[@key]: entry\n", 'citation_group', false, '[@key]'],
            'symbol' => ['a :smile: b', 'symbol', false, 'smile'],
            'critic comment' => ['a {# note #} b', 'critic_comment', false, 'note'],
            'image' => ['a ![alt](/i.png) b', 'image', false, 'alt'],
            'table' => ["| a | b |\n|---|---|\n| 1 | 2 |\n", 'table', true, '1'],
            'heading' => ["# Title\n", 'heading', true, 'Title'],
        ];
    }

    #[DataProvider('constructProvider')]
    public function testDenyingAConstructKeepsItsText(string $source, string $type, bool $isBlock, string $mustSurvive): void
    {
        $profile = Profile::full()->onDisallowed(Profile::ACTION_TO_TEXT);
        $profile = $isBlock ? $profile->denyBlock([$type]) : $profile->denyInline([$type]);

        $before = self::visibleText($this->converter($type)->convert($source));
        $after = self::visibleText($this->converter($type, $profile)->convert($source));

        $this->assertNotSame('', $before, 'the sample renders nothing, so it proves nothing');
        $this->assertStringContainsString(
            $mustSurvive,
            $after,
            "denying '{$type}' destroyed the construct's text instead of degrading it - '{$mustSurvive}' is gone",
        );
    }

    public function testAnUnreachablePayloadIsReportedRatherThanDropped(): void
    {
        $converter = $this->converter('substitution', Profile::full()->denyInline(['substitution']));
        $converter->convert('a {~old~>new~} b');

        $unreachable = [];
        foreach ($converter->getProfileViolations() as $violation) {
            if ($violation->reason === 'to_text_yielded_nothing') {
                $unreachable[] = $violation->nodeType;
            }
        }

        $this->assertSame([], $unreachable, 'substitution has an extractor arm, so nothing should be unreachable');
    }

    private function converter(string $type, ?Profile $profile = null): CarveConverter
    {
        $converter = new CarveConverter(profile: $profile);
        if ($type === 'citation_group') {
            $converter->addExtension(new CitationsExtension());
        }

        return $converter;
    }

    private static function visibleText(string $html): string
    {
        return trim((string)preg_replace('/\s+/', ' ', strip_tags($html)));
    }
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\MentionsExtension;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A mention or tag with no destination is an inert span, not a link, so a
 * derived cross-reference label keeps it (PART 9R R4, carve-php#4944).
 */
class ACrossReferenceLabelKeepsAnInertMentionTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function cases(): iterable
    {
        yield 'heading mention and tag' => [
            "# Hi @bob and #tag\n\nSee </#Hi-bob-and-tag>.\n",
            '<p>See <a href="#Hi-bob-and-tag">Hi <span class="mention"><strong>@bob</strong></span> and <span class="tag"><strong>#tag</strong></span></a>.</p>',
        ];

        yield 'table caption tag before the number' => [
            "{#t}\n| b |\n^ #y#\n\n</#t>\n",
            '<p><a href="#t"><span class="tag"><strong>#y</strong></span> 1</a></p>',
        ];

        yield 'figure caption mention' => [
            "{#f}\n![i](a.png)\n^ Fig @m #\n\n</#f>\n",
            '<p><a href="#f">Fig <span class="mention"><strong>@m</strong></span> 1</a></p>',
        ];

        yield 'mention in emphasis in a heading' => [
            "# A *@m*\n\n</#A-m>\n",
            '<p><a href="#A-m">A <strong><span class="mention"><strong>@m</strong></span></strong></a></p>',
        ];
    }

    #[DataProvider('cases')]
    public function testLabelKeepsTheSpan(string $source, string $expected): void
    {
        $this->assertStringContainsString($expected, (new CarveConverter())->convert($source));
    }

    public function testALinkedMentionStillUnwrapsInsideTheLabel(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MentionsExtension(mentionUrl: '/u/{name}', tagUrl: '/t/{name}'));

        $html = $converter->convert("# Hi @bob\n\nSee </#Hi-bob>.\n");

        $this->assertStringContainsString('<p>See <a href="#Hi-bob">Hi @bob</a>.</p>', $html);
    }
}

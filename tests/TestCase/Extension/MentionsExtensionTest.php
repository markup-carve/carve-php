<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\MentionsExtension;
use PHPUnit\Framework\TestCase;

class MentionsExtensionTest extends TestCase
{
    public function testUserMention(): void
    {
        $html = (new CarveConverter())->convert('Hello @johndoe!');

        $this->assertStringContainsString('<span class="mention"><strong>@johndoe</strong></span>', $html);
    }

    public function testTag(): void
    {
        $html = (new CarveConverter())->convert('See #release-1.0 notes.');

        $this->assertStringContainsString('<span class="tag"><strong>#release-1.0</strong></span>', $html);
    }

    public function testMentionsAndTagsEnabledByDefault(): void
    {
        // No explicit addExtension(): both are core Carve syntax.
        $html = (new CarveConverter())->convert('Hey @alice, see #bug.');

        $this->assertStringContainsString('<span class="mention"><strong>@alice</strong></span>', $html);
        $this->assertStringContainsString('<span class="tag"><strong>#bug</strong></span>', $html);
    }

    public function testCustomTemplatesAndClasses(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MentionsExtension(
            mentionUrl: 'https://example.com/u/{name}',
            tagUrl: '/topic/{name}',
            mentionClass: 'user-link',
            tagClass: 'topic',
        ));

        $html = $converter->convert('@alice tagged #php');

        $this->assertStringContainsString('<a class="user-link" href="https://example.com/u/alice">@alice</a>', $html);
        $this->assertStringContainsString('<a class="topic" href="/topic/php">#php</a>', $html);
    }

    public function testMultipleMentions(): void
    {
        $html = (new CarveConverter())->convert('@alice and @bob discussed the issue.');

        $this->assertStringContainsString('<span class="mention"><strong>@alice</strong></span>', $html);
        $this->assertStringContainsString('<span class="mention"><strong>@bob</strong></span>', $html);
    }

    public function testMentionWithHyphenAndUnderscore(): void
    {
        $html = (new CarveConverter())->convert('Thanks @john-doe and @jane_doe');

        $this->assertStringContainsString('<span class="mention"><strong>@john-doe</strong></span>', $html);
        $this->assertStringContainsString('<span class="mention"><strong>@jane_doe</strong></span>', $html);
    }

    public function testMentionWithInteriorDot(): void
    {
        // An interior dot (followed by another name character) is part of the
        // name; a trailing dot is sentence punctuation (grammar PART 9 §7,
        // corpus 89-mention-and-tag-name-boundaries).
        $html = (new CarveConverter())->convert('Ping @john.doe and @markus. end');

        $this->assertStringContainsString('<span class="mention"><strong>@john.doe</strong></span>', $html);
        $this->assertStringContainsString('<span class="mention"><strong>@markus</strong></span>. end', $html);
    }

    public function testMentionAtStartOfText(): void
    {
        $html = (new CarveConverter())->convert('@admin please help');

        $this->assertStringContainsString('<span class="mention"><strong>@admin</strong></span>', $html);
    }

    public function testMentionAtEndOfText(): void
    {
        $html = (new CarveConverter())->convert('Thanks @helper');

        $this->assertStringContainsString('<span class="mention"><strong>@helper</strong></span>', $html);
    }

    public function testMidWordAtIsNotAMention(): void
    {
        // Email-like text must not become a mention.
        $html = (new CarveConverter())->convert('email a@b.com stays');

        $this->assertStringNotContainsString('class="mention"', $html);
        $this->assertStringContainsString('a@b.com', $html);
    }

    public function testTrailingPunctuationNotPartOfTag(): void
    {
        $html = (new CarveConverter())->convert('see #release-1.0.');

        $this->assertStringContainsString('<span class="tag"><strong>#release-1.0</strong></span>', $html);
        $this->assertStringNotContainsString('release-1.0.', $html);
    }

    public function testEscapedMentionNotLinked(): void
    {
        $html = (new CarveConverter())->convert('Contact \\@support for help.');

        $this->assertStringContainsString('@support', $html);
        $this->assertStringNotContainsString('href="/users/support"', $html);
    }

    public function testRepeatedRenderIsStable(): void
    {
        $converter = new CarveConverter();
        $document = $converter->parse('Hello @johndoe!');

        $first = $converter->render($document);
        $second = $converter->render($document);

        $this->assertSame($first, $second);
        $this->assertStringContainsString('<span class="mention"><strong>@johndoe</strong></span>', $second);
    }
}

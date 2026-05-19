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

        $this->assertStringContainsString('<a class="mention" href="/users/johndoe">@johndoe</a>', $html);
    }

    public function testTag(): void
    {
        $html = (new CarveConverter())->convert('See #release-1.0 notes.');

        $this->assertStringContainsString('<a class="tag" href="/tags/release-1.0">#release-1.0</a>', $html);
    }

    public function testMentionsAndTagsEnabledByDefault(): void
    {
        // No explicit addExtension(): both are core Carve syntax.
        $html = (new CarveConverter())->convert('Hey @alice, see #bug.');

        $this->assertStringContainsString('class="mention" href="/users/alice"', $html);
        $this->assertStringContainsString('class="tag" href="/tags/bug"', $html);
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

        $this->assertStringContainsString('href="/users/alice"', $html);
        $this->assertStringContainsString('href="/users/bob"', $html);
    }

    public function testMentionWithHyphenAndUnderscore(): void
    {
        $html = (new CarveConverter())->convert('Thanks @john-doe and @jane_doe');

        $this->assertStringContainsString('href="/users/john-doe"', $html);
        $this->assertStringContainsString('href="/users/jane_doe"', $html);
    }

    public function testMentionAtStartOfText(): void
    {
        $html = (new CarveConverter())->convert('@admin please help');

        $this->assertStringContainsString('href="/users/admin"', $html);
    }

    public function testMentionAtEndOfText(): void
    {
        $html = (new CarveConverter())->convert('Thanks @helper');

        $this->assertStringContainsString('href="/users/helper"', $html);
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

        $this->assertStringContainsString('href="/tags/release-1.0"', $html);
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
        $this->assertStringContainsString('<a class="mention" href="/users/johndoe">@johndoe</a>', $second);
    }
}

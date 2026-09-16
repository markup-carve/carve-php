<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\MentionsExtension;
use MarkupCarve\Carve\Extension\SocialLinkResolverInput;
use MarkupCarve\Carve\Node\Inline\Mention;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use PHPUnit\Framework\TestCase;
use RuntimeException;

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

    public function testTemplateHrefIsSanitized(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MentionsExtension(
            mentionUrl: 'javascript:alert({name})',
        ));

        $html = $converter->convert('@alice');

        $this->assertStringContainsString('<span class="mention"><strong>@alice</strong></span>', $html);
    }

    public function testResolversAreAuthoritativeAndReceiveContext(): void
    {
        $calls = [];
        $context = (object)['tenant' => 42];
        $converter = new CarveConverter();
        $converter->addExtension(new MentionsExtension(
            mentionUrl: '/fallback/{name}',
            mentionResolver: function (SocialLinkResolverInput $input) use (&$calls): ?string {
                $calls[] = $input;

                return match ($input->name) {
                    'alice' => '/people/42',
                    'unsafe' => 'javascript:alert(1)',
                    default => null,
                };
            },
            tagResolver: static fn (SocialLinkResolverInput $input): ?string => $input->name === 'release' ? '/collections/stable' : null,
            resolverContext: $context,
        ));

        $document = $converter->parse('@alice @missing #release @unsafe');
        $mention = $document->getChildren()[0]->getChildren()[0];
        $this->assertInstanceOf(Mention::class, $mention);
        $mention->setAttribute('data-role', 'lead');
        $html = $converter->render($document);
        $this->assertStringContainsString('<a class="mention" href="/people/42" data-role="lead">@alice</a>', $html);
        $this->assertStringContainsString('<span class="mention"><strong>@missing</strong></span>', $html);
        $this->assertStringContainsString('<a class="tag" href="/collections/stable">#release</a>', $html);
        $this->assertStringContainsString('<span class="mention"><strong>@unsafe</strong></span>', $html);
        $this->assertCount(3, $calls);
        $this->assertSame($context, $calls[0]->context);
        $this->assertSame('lead', $calls[0]->attributes['data-role']);
    }

    public function testResolverErrorsRenderTheInertForm(): void
    {
        $converter = new CarveConverter();
        $converter->addExtension(new MentionsExtension(
            mentionResolver: static function (): never {
                throw new RuntimeException('lookup failed');
            },
        ));

        $this->assertStringContainsString(
            '<span class="mention"><strong>@alice</strong></span>',
            $converter->convert('@alice'),
        );
    }

    public function testResolverDestinationReachesTheMarkdownRenderer(): void
    {
        $converter = new CarveConverter(renderer: new MarkdownRenderer());
        $converter->addExtension(new MentionsExtension(
            mentionUrl: '/fallback/{name}',
            mentionResolver: static fn (): string => '/people/42',
        ));

        $this->assertSame("[@alice](/people/42)\n", $converter->convert('@alice'));
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

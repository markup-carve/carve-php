<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Mention;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class HtmlRendererMentionAttributesTest extends TestCase
{
    /**
     * @param string $cssClass
     * @param string $href
     * @param string $label
     * @param array<string, string> $attributes
     * @param string $expected
     */
    #[DataProvider('mentionAttributeRenderProvider')]
    public function testMentionAttributesRenderWithMergedClass(
        string $cssClass,
        string $href,
        string $label,
        array $attributes,
        string $expected,
    ): void {
        $node = new Mention($cssClass, $href, $label);
        foreach ($attributes as $key => $value) {
            $node->setAttribute($key, $value);
        }

        $this->assertSame("<p>$expected</p>\n", (new CarveConverter())->render($this->document($node)));
    }

    /**
     * @return array<string, array{string, string, string, array<string, string>, string}>
     */
    public static function mentionAttributeRenderProvider(): array
    {
        return [
            'mention span no attrs' => [
                'mention',
                '',
                '@alice',
                [],
                '<span class="mention"><strong>@alice</strong></span>',
            ],
            'mention span id' => [
                'mention',
                '',
                '@alice',
                ['id' => 'x'],
                '<span class="mention" id="x"><strong>@alice</strong></span>',
            ],
            'mention span class' => [
                'mention',
                '',
                '@alice',
                ['class' => 'user'],
                '<span class="mention user"><strong>@alice</strong></span>',
            ],
            'mention span source order after merged class' => [
                'mention',
                '',
                '@alice',
                ['data-role' => 'lead', 'class' => 'user', 'id' => 'x'],
                '<span class="mention user" data-role="lead" id="x"><strong>@alice</strong></span>',
            ],
            'mention link no attrs' => [
                'mention',
                '/u/alice',
                '@alice',
                [],
                '<a class="mention" href="/u/alice">@alice</a>',
            ],
            'mention link id' => [
                'mention',
                '/u/alice',
                '@alice',
                ['id' => 'x'],
                '<a class="mention" href="/u/alice" id="x">@alice</a>',
            ],
            'mention link class' => [
                'mention',
                '/u/alice',
                '@alice',
                ['class' => 'user'],
                '<a class="mention user" href="/u/alice">@alice</a>',
            ],
            'mention link source order after href' => [
                'mention',
                '/u/alice',
                '@alice',
                ['data-role' => 'lead', 'class' => 'user', 'id' => 'x'],
                '<a class="mention user" href="/u/alice" data-role="lead" id="x">@alice</a>',
            ],
            'mention link author href does not displace structural href' => [
                'mention',
                '/u/alice',
                '@alice',
                ['href' => '/evil', 'class' => 'user', 'data-role' => 'lead'],
                '<a class="mention user" href="/u/alice" data-role="lead">@alice</a>',
            ],
            'tag span no attrs' => [
                'tag',
                '',
                '#release',
                [],
                '<span class="tag"><strong>#release</strong></span>',
            ],
            'tag span id' => [
                'tag',
                '',
                '#release',
                ['id' => 'x'],
                '<span class="tag" id="x"><strong>#release</strong></span>',
            ],
            'tag span class' => [
                'tag',
                '',
                '#release',
                ['class' => 'user'],
                '<span class="tag user"><strong>#release</strong></span>',
            ],
            'tag span source order after merged class' => [
                'tag',
                '',
                '#release',
                ['data-role' => 'lead', 'class' => 'user', 'id' => 'x'],
                '<span class="tag user" data-role="lead" id="x"><strong>#release</strong></span>',
            ],
            'tag link no attrs' => [
                'tag',
                '/t/release',
                '#release',
                [],
                '<a class="tag" href="/t/release">#release</a>',
            ],
            'tag link id' => [
                'tag',
                '/t/release',
                '#release',
                ['id' => 'x'],
                '<a class="tag" href="/t/release" id="x">#release</a>',
            ],
            'tag link class' => [
                'tag',
                '/t/release',
                '#release',
                ['class' => 'user'],
                '<a class="tag user" href="/t/release">#release</a>',
            ],
            'tag link source order after href' => [
                'tag',
                '/t/release',
                '#release',
                ['data-role' => 'lead', 'class' => 'user', 'id' => 'x'],
                '<a class="tag user" href="/t/release" data-role="lead" id="x">#release</a>',
            ],
            'tag link author href does not displace structural href' => [
                'tag',
                '/t/release',
                '#release',
                ['href' => '/evil', 'class' => 'user', 'data-role' => 'lead'],
                '<a class="tag user" href="/t/release" data-role="lead">#release</a>',
            ],
        ];
    }

    private function document(Mention $node): Document
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild($node);
        $document->appendChild($paragraph);

        return $document;
    }
}

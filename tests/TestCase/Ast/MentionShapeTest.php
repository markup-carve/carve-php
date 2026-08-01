<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * A mention is `{type, user}` and a tag is `{type: "tag", name}`.
 *
 * This engine models both as a Link subclass carrying a css class, an empty
 * destination and a Text child holding the literal `@user` / `#tag` - so the
 * wire carried four keys the schema rejects, and called a tag a `mention`.
 *
 * The two ARE one trust class, which is why a profile classifies `#tag` as
 * `mention`. That is about what a profile can deny, not about what the node is:
 * PART 12 section 3 and profiles.md both keep `tag` as its own AST type.
 */
class MentionShapeTest extends TestCase
{
    /**
     * @return array<string, array<string, mixed>>
     */
    private function inlineByType(string $source): array
    {
        $encoded = (new AstCodec())->encode((new CarveConverter())->parse($source));
        $out = [];
        foreach ($encoded['children'][0]['children'] ?? [] as $child) {
            $out[$child['type']] = $child;
        }

        return $out;
    }

    public function testAMentionPublishesItsUser(): void
    {
        $mention = $this->inlineByType("hi @user\n")['mention'];

        $this->assertSame('user', $mention['user']);
        $this->assertSame([], array_diff(array_keys($mention), ['type', 'user', 'pos', 'attrs']));
    }

    public function testATagIsItsOwnTypeAndPublishesItsName(): void
    {
        $tag = $this->inlineByType("hi #tag\n")['tag'];

        $this->assertSame('tag', $tag['name']);
        $this->assertSame([], array_diff(array_keys($tag), ['type', 'name', 'pos', 'attrs']));
    }

    public function testNeitherPublishesTheRenderedLabel(): void
    {
        // `user` carries the content; the Text child holds the same string with
        // its sigil, and publishing both would be two representations of one.
        foreach ($this->inlineByType("@user and #tag\n") as $node) {
            $this->assertArrayNotHasKey('children', $node);
            $this->assertArrayNotHasKey('cssClass', $node);
            $this->assertArrayNotHasKey('destination', $node);
        }
    }

    public function testBothSurviveARoundTrip(): void
    {
        $codec = new AstCodec();
        $converter = new CarveConverter();
        foreach (["hi @user\n", "hi #tag\n", "@a and #b together\n"] as $source) {
            $decoded = $codec->decode($codec->encode($converter->parse($source)));

            $this->assertSame(
                $converter->render($converter->parse($source)),
                $converter->render($decoded),
                sprintf('%s must render identically after a round trip', json_encode($source)),
            );
        }
    }
}

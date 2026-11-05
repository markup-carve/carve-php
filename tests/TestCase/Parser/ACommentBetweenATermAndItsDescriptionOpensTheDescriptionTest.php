<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Block\DefinitionDescription;
use MarkupCarve\Carve\Node\Block\DefinitionList;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Parser\BlockParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment written after a term is the first child of the description that
 * follows, after the last term when more follow (CARVE-P12-027, carve#5167).
 */
class ACommentBetweenATermAndItsDescriptionOpensTheDescriptionTest extends TestCase
{
    /**
     * Source, description index, payload, description span or null, fmt output (corpus 956).
     *
     * @return array<string, array{string, int, string, array{int, int}|null, string}>
     */
    public static function cases(): array
    {
        return [
            'indented span' => [":: t\n  %%%\n      a\n  %%%\n:  body\n", 0, '      a', [7, 32], ":: t\n: %%%\n        a\n  %%%\n\n  body\n"],
            'span' => [":: t\n%%%\na\n%%%\n: body\n", 0, 'a', [5, 21], ":: t\n: %%%\n  a\n  %%%\n\n  body\n"],
            'span, two terms' => [":: t\n%%%\na\n%%%\n:: u\n: body\n", 0, 'a', null, ":: t\n:: u\n: %%%\n  a\n  %%%\n\n  body\n"],
            'line' => [":: t\n%% c\n: body\n", 0, 'c', [5, 16], ":: t\n: %% c\n\n  body\n"],
            'line, two terms' => [":: t\n%% c\n:: u\n: body\n", 0, 'c', null, ":: t\n:: u\n: %% c\n\n  body\n"],
            'line, second entry' => [":: a\n: x\n:: t\n%% c\n: body\n", 1, 'c', [14, 25], ":: a\n: x\n:: t\n: %% c\n\n  body\n"],
            'span, second entry' => [":: a\n: x\n:: t\n%%%\nq\n%%%\n: body\n", 1, 'q', [14, 30], ":: a\n: x\n:: t\n: %%%\n  q\n  %%%\n\n  body\n"],
        ];
    }

    /**
     * @param string $source
     * @param int $index
     * @param string $payload
     * @param array{int, int}|null $span
     * @param string $formatted
     */
    #[DataProvider('cases')]
    public function testTheCommentOpensTheDescription(string $source, int $index, string $payload, ?array $span, string $formatted): void
    {
        $document = (new BlockParser(false, false, false, true))->parse($source);
        $children = $document->getChildren();
        $this->assertCount(1, $children);
        $list = $children[0];
        $this->assertInstanceOf(DefinitionList::class, $list);
        $descriptions = array_values(array_filter($list->getChildren(), static fn (Node $node): bool => $node instanceof DefinitionDescription));
        $description = $descriptions[$index];
        $comment = $description->getChildren()[0];
        $this->assertInstanceOf(Comment::class, $comment);
        $this->assertSame($payload, $comment->getContent());
        $pos = $description->getPos();
        $this->assertSame($span, $pos === null ? null : [$pos->startOffset, $pos->endOffset]);
        $this->assertSame(strlen(rtrim($source, "\n")), $list->getPos()?->endOffset);
        $this->assertSame($formatted, CarveConverter::toCarve($source));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }

    public function testInAListItemTheCommentOpensTheDescription(): void
    {
        $this->assertSame(
            "- i\n\n  :: t\n  : %% c\n\n    body\n",
            CarveConverter::toCarve("- i\n\n  :: t\n  %% c\n  : body\n"),
        );
    }

    /**
     * Across a blank line the body keeps its column, so a list under its paragraph stays in it.
     *
     * @return array<string, array{string}>
     */
    public static function listItemHosts(): array
    {
        return [
            'comment on the marker line' => ["- i\n\n  :: t\n  : %% c\n\n    body\n    - x\n"],
            'span on the marker line' => ["- i\n\n  :: t\n  : %%%\n    a\n    %%%\n\n    body\n    - x\n"],
            'paragraph' => ["- i\n\n  :: t\n  : body\n\n    body\n    - x\n"],
        ];
    }

    #[DataProvider('listItemHosts')]
    public function testAListUnderTheBodyParagraphStaysInTheDescription(string $source): void
    {
        $html = (new CarveConverter())->convert($source);
        $this->assertMatchesRegularExpression('#<dd>.*<ul>\s*<li>x</li>\s*</ul>\s*</dd>#s', $html);
        $formatted = CarveConverter::toCarve($source);
        $this->assertSame($html, (new CarveConverter())->convert($formatted));
        $this->assertSame($formatted, CarveConverter::toCarve($formatted));
    }
}

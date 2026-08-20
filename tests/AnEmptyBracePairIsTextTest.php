<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Inline\SmartPunctuation;
use PHPUnit\Framework\TestCase;

/**
 * carve#1447, two rulings that arrived together.
 *
 * AN EMPTY BRACE PAIR IS NOT A CONSTRUCT. Every content slot involved is a
 * one-or-more repetition -- `forced_content` and `inline_content` both -- so an
 * opener that meets its own closer opened nothing and its characters are text.
 * This engine emitted empty elements instead: `<em></em>`, `<sup></sup>`,
 * `<mark></mark>`. The harm is not the empty element, it is that the author's
 * braces are in the source and nothing is in the output.
 *
 * A BRACED HYPHEN PAIR IS AN EN DASH. The bare run carries a flanking guard
 * (carve#1443), so a run with whitespace before it and a non-whitespace
 * character after it is flag-shaped and stays literal. That is right for a long
 * CLI flag and wrong for the author who meant a dash there, and `{--}` is the
 * way to say it -- the string it took was the empty deletion.
 */
class AnEmptyBracePairIsTextTest extends TestCase
{
    private CarveConverter $converter;

    protected function setUp(): void
    {
        $this->converter = CarveConverter::create();
    }

    private function html(string $source): string
    {
        return trim($this->converter->convert($source));
    }

    public function testEveryEmptyForcedSpanIsLiteral(): void
    {
        foreach (['{//}', '{**}', '{__}', '{~~}', '{^^}', '{,,}', '{==}'] as $pair) {
            $this->assertSame('<p>' . $pair . '</p>', $this->html($pair . "\n"));
        }
    }

    public function testTheEmptyAdditionAndCommentAreLiteral(): void
    {
        $this->assertSame('<p>{++}</p>', $this->html("{++}\n"));
        $this->assertSame('<p>{##}</p>', $this->html("{##}\n"));
    }

    public function testAnEmptyPairDoesNotSwallowTheNextConstruct(): void
    {
        // The empty pair being text has to mean it STOPS there. Skipping past
        // its own closer instead found the NEXT one, so `{++} x {+y+}` came
        // back as a single insertion holding `+} x {+y` -- the empty pair
        // eating the construct after it, which is worse than the empty element
        // it replaced.
        $this->assertSame('<p>{//} x <em>y</em></p>', $this->html("{//} x {/y/}\n"));
        $this->assertSame('<p>{**} x <strong>b</strong></p>', $this->html("{**} x {*b*}\n"));
        $this->assertSame('<p>{~~} x <s>s</s></p>', $this->html("{~~} x {~s~}\n"));
        $this->assertSame('<p>{==} x <mark>h</mark></p>', $this->html("{==} x {=h=}\n"));
        $this->assertSame('<p>{++} x <ins>y</ins></p>', $this->html("{++} x {+y+}\n"));
        $this->assertSame('<p>– x <del>y</del></p>', $this->html("{--} x {-y-}\n"));
    }

    public function testAPairHoldingSomethingIsStillTheConstruct(): void
    {
        $this->assertSame('<p><em>i</em></p>', $this->html("{/i/}\n"));
        $this->assertSame('<p><strong>b</strong></p>', $this->html("{*b*}\n"));
        $this->assertSame('<p><s>s</s></p>', $this->html("{~s~}\n"));
        $this->assertSame('<p><ins>ins</ins></p>', $this->html("{+ins+}\n"));
        $this->assertSame('<p><del>del</del></p>', $this->html("{-del-}\n"));
        $this->assertSame(
            '<p><span class="critic-comment"> c </span></p>',
            $this->html("{# c #}\n"),
        );
    }

    public function testAFullyEmptySubstitutionIsLeftAlone(): void
    {
        // Its halves are independent, and a half-empty substitution is an
        // ordinary edit -- a deletion with no replacement, an insertion
        // replacing nothing -- so requiring content per half would refuse real
        // documents.
        $this->assertSame('<p><del>a</del><ins></ins></p>', $this->html("{~a~>~}\n"));
        $this->assertSame('<p><del></del><ins>b</ins></p>', $this->html("{~~>b~}\n"));
        $this->assertSame('<p><del></del><ins></ins></p>', $this->html("{~~>~}\n"));
    }

    public function testADeletionHoldingAHyphenIsUntouched(): void
    {
        // The one string that moved is the EMPTY deletion, which is also why
        // there is no braced em dash: a three-hyphen brace deletes a hyphen,
        // and that is a thing an author writes.
        $this->assertSame('<p><del>-</del></p>', $this->html("{---}\n"));
        $this->assertSame('<p><del>x</del></p>', $this->html("{-x-}\n"));
    }

    public function testTheBracedPairConvertsWhereTheBareRunIsRefused(): void
    {
        $this->assertSame('<p>a ---(p) b</p>', $this->html("a ---(p) b\n"));
        $this->assertSame('<p>a –(p) b</p>', $this->html("a {--}(p) b\n"));
        $this->assertSame('<p>x –verbose y</p>', $this->html("x {--}verbose y\n"));
    }

    public function testTheBracedPairConsumesItsBraces(): void
    {
        $this->assertSame('<p>x–y</p>', $this->html("x{--}y\n"));
        $this->assertSame('<p>–start</p>', $this->html("{--}start\n"));
        $this->assertSame('<p>––</p>', $this->html("{--}{--}\n"));
    }

    public function testTheBracedPairIsInlineContent(): void
    {
        $this->assertSame('<p><strong>a – b</strong></p>', $this->html("*a {--} b*\n"));
        $this->assertSame('<p><a href="u">a – b</a></p>', $this->html("[a {--} b](u)\n"));
    }

    public function testTheBracedPairIsNotReadInsideACodeSpan(): void
    {
        $this->assertSame('<p><code>{--}</code></p>', $this->html("`{--}`\n"));
    }

    public function testTheBracedPairIsTheSameNodeTheBareRunProduces(): void
    {
        // Not a glyph in a text run: `fmt` preserves `--` and `...` because
        // they are smart_punctuation carrying the authored spelling, and the
        // braced form is a second spelling of the same kind rather than a
        // second construct. Written as a Text node it also could not be PLACED
        // -- placeAt checks a Text against its own source, and that one held a
        // glyph the source does not -- so PART 12 conformance reported the
        // node as having no position.
        $converter = new CarveConverter();
        $converter->getParser()->enablePositionTracking();
        $document = $converter->parse("a {--} b\n");
        $inlines = $document->getChildren()[0]->getChildren();
        $node = $inlines[1];

        $this->assertInstanceOf(SmartPunctuation::class, $node);
        $this->assertSame('en_dash', $node->getKind());
        $this->assertSame('{--}', $node->getContent());
        $this->assertNotNull($node->getPos());
    }

    public function testTheWriterRoundTrips(): void
    {
        $this->assertSame("a {--} b\n", $this->converter->toCarve("a {--} b\n"));
        foreach (["a {--} b\n", "{--}start\n", "{---} and {-x-}\n"] as $source) {
            $this->assertSame(
                $this->html($source),
                $this->html($this->converter->toCarve($source)),
            );
        }
    }
}

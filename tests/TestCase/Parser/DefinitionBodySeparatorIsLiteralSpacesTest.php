<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The definition-list BODY separator is literal spaces.
 *
 * `definition_body = (':', definition_separator, ...)` with
 * `definition_separator = space, {space}`, `space = ' '` and
 * `whitespace = ' ' | '\t'` kept deliberately apart. A marker separator is
 * literal; indentation is columns, and a separator is not indentation
 * (carve#692, and carve#698 for the `::` term marker on this same construct).
 *
 * Six sites matched it with `/^:\s\s+/`. Without the `u` modifier PCRE's `\s`
 * is `[ \t\n\r\f\v]`, so a tab, a vertical tab or a form feed in either slot
 * opened a `<dd>` that no other implementation opens - and the content arrived
 * with the tab silently removed (carve-php#935).
 *
 * WHAT carve#1757 MOVED, AND WHAT IT DID NOT. The separator is now a run of ONE
 * or more spaces rather than two, so only the FIRST slot is still the
 * separator's own - `:\tdef` and `:\v\vdef` open nothing, which is the half of
 * carve-php#935 that is settled and is what this file pins. What follows that
 * one space is CONTENT, and a tab there is leading content whitespace stripped
 * exactly as carve-php#884 strips it after the `::` term separator; the
 * executable oracle reads `: \tdef` the same way.
 *
 * A vertical tab or a form feed is NOT whitespace under this engine's one
 * whitespace definition (`StringUtil::WHITESPACE_CHARS`, carve-php#1071), so it
 * survives into the `<dd>` as content - which is the answer the two-space
 * spelling already gave, now reachable one column earlier. What
 * such a line becomes across implementations is the live three-way disagreement
 * carve#935 records and is deliberately not pinned here.
 */
class DefinitionBodySeparatorIsLiteralSpacesTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * The separator's OWN slot holds something other than a space.
     *
     * @return array<string, array{0: string}>
     */
    public static function nonSpaceSeparatorProvider(): array
    {
        return [
            'a tab' => [":: t\n:\tdef\n"],
            'two vertical tabs' => [":: t\n:\v\vdef\n"],
            'a form feed' => [":: t\n:\fdef\n"],
            'a vertical tab' => [":: t\n:\vdef\n"],
            'a vertical tab then a space' => [":: t\n:\v def\n"],
        ];
    }

    #[DataProvider('nonSpaceSeparatorProvider')]
    public function testAWhitespaceSeparatorDoesNotOpenADefinitionBody(string $source): void
    {
        $this->assertStringNotContainsString('<dd>', $this->html($source));
    }

    #[DataProvider('nonSpaceSeparatorProvider')]
    public function testItDoesNotRenderAsTheTwoSpaceSpelling(string $source): void
    {
        // The second half of the defect: the `<dd>` was formed AND the
        // separator character was consumed with it, so a document the grammar
        // does not admit came out byte-identical to one it does. Asserting the
        // character appears SOMEWHERE in the output would be satisfied by a
        // stray tab in the markup; this pins the two documents apart.
        $this->assertNotSame($this->html(":: t\n:  def\n"), $this->html($source));
    }

    public function testTwoLiteralSpacesStillOpenADefinitionBody(): void
    {
        // The control. Without it every assertion above would pass on an engine
        // that stopped parsing definition lists at all.
        $this->assertStringContainsString('<dd>def</dd>', $this->html(":: t\n:  def\n"));
    }

    public function testOneLiteralSpaceOpensADefinitionBody(): void
    {
        // carve#1757. The second control, and the one the provider above needs:
        // every case there is `:` followed by a non-space, so an engine that
        // refused the one-space separator outright would still pass them all.
        $this->assertStringContainsString('<dd>def</dd>', $this->html(":: t\n: def\n"));
    }

    public function testAThirdSpaceIsStillTheSeparatorRunAndNotContent(): void
    {
        // `\s\s+` was greedy over two-or-more, and the replacement has to keep
        // that: a wider separator run is not leading indentation of the body.
        $this->assertStringContainsString('<dd>def</dd>', $this->html(":: t\n:    def\n"));
    }

    public function testATabAfterTheSeparatorIsStrippedContentNotASeparator(): void
    {
        // The separator is the one space; the tab is the body's leading content
        // whitespace and comes off exactly as carve-php#884 takes it off after
        // the `::` term separator. So the line opens a body, and it says the
        // same thing the two-space spelling says - which is the one case in the
        // provider above that carve#1757 moved.
        $this->assertSame($this->html(":: t\n:  def\n"), $this->html(":: t\n: \tdef\n"));
    }
}

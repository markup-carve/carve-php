<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The definition-list BODY separator is two literal spaces.
 *
 * `definition_body = (':', space, space, first_block_content) | ...` with
 * `space = ' '` and `whitespace = ' ' | '\t'` kept deliberately apart. A marker
 * separator is literal; indentation is columns, and a separator is not
 * indentation (carve#692, and carve#698 for the `::` term marker on this same
 * construct).
 *
 * Six sites matched it with `/^:\s\s+/`. Without the `u` modifier PCRE's `\s`
 * is `[ \t\n\r\f\v]`, so a tab, a vertical tab or a form feed in either slot
 * opened a `<dd>` that no other implementation opens - and the content arrived
 * with the tab silently removed (carve-php#935).
 *
 * These assert what is settled: the line is not a definition body, and the
 * character the author typed is still in the output. What the line becomes
 * INSTEAD is a live three-way disagreement (carve#935 records it) and is
 * deliberately not pinned here.
 */
class DefinitionBodySeparatorIsLiteralSpacesTest extends TestCase
{
    protected function html(string $source): string
    {
        return (new CarveConverter())->convert($source);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function nonSpaceSeparatorProvider(): array
    {
        return [
            'a space then a tab' => [":: t\n: \tdef\n"],
            'two vertical tabs' => [":: t\n:\v\vdef\n"],
            'a space then a form feed' => [":: t\n: \fdef\n"],
            'a space then a vertical tab' => [":: t\n: \vdef\n"],
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

    public function testAThirdSpaceIsStillTheSeparatorRunAndNotContent(): void
    {
        // `\s\s+` was greedy over two-or-more, and the replacement has to keep
        // that: a wider separator run is not leading indentation of the body.
        $this->assertStringContainsString('<dd>def</dd>', $this->html(":: t\n:    def\n"));
    }
}

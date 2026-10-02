<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\Ast\SourceSpan;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Inline\CitationGroup;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

class CitationsExtensionTest extends TestCase
{
    use ScalingGuardTrait;

    public function testPublicGroupPositioningCountsUnicodeAndWhitespace(): void
    {
        foreach ([false, true] as $integral) {
            $raw = '[' . ($integral ? '+' : '') . "voir α @a; \t voir β @bb ]";
            $group = new CitationGroup([
                ['key' => 'a', 'suppressAuthor' => false],
                ['key' => 'bb', 'suppressAuthor' => false],
            ], $raw, $integral);
            $group->setPos(new SourceSpan(2, 2, 5, 5 + mb_strlen($raw, 'UTF-8'), 10, 10 + mb_strlen($raw, 'UTF-8')));
            $shift = $integral ? 1 : 0;
            $items = $group->getItems();
            $this->assertSame(11 + $shift, $items[0]['pos']['startOffset']);
            $this->assertSame(20 + $shift, $items[0]['pos']['endOffset']);
            $this->assertSame(24 + $shift, $items[1]['pos']['startOffset']);
            $this->assertSame(34 + $shift, $items[1]['pos']['endOffset']);
            $this->assertSame(19 + $shift, $items[1]['pos']['startColumn']);
        }
    }

    #[Group('scaling')]
    public function testPublicCitationPositionSetterScalesLinearly(): void
    {
        $this->assertConversionScalesLinearly(
            static function (string $raw): void {
                $count = substr_count($raw, ';') + 1;
                $group = new CitationGroup(array_fill(0, $count, ['key' => 'a', 'suppressAuthor' => false]), $raw);
                $length = mb_strlen($raw, 'UTF-8');
                $group->setPos(new SourceSpan(1, 1, 1, $length + 1, 0, $length));
            },
            '[' . implode('; ', array_fill(0, 4096, '@a')) . ']',
            '[' . implode('; ', array_fill(0, 16384, '@a')) . ']',
            'citation item position setter',
            4096,
            16384,
        );
    }

    #[Group('scaling')]
    public function testPositionedCitationGroupsScaleLinearly(): void
    {
        $converter = $this->converter();
        $converter->getParser()->enablePositionTracking();
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->parse($source),
            '[' . implode('; ', array_fill(0, 1024, '@a')) . ']',
            '[' . implode('; ', array_fill(0, 4096, '@a')) . ']',
            'positioned citation groups',
            1024,
            4096,
        );
    }

    public function testParsesCitationGroup(): void
    {
        $group = $this->firstCitationGroup('[@smith2020]');

        $this->assertInstanceOf(CitationGroup::class, $group);
        $this->assertSame('smith2020', $group->getItems()[0]['key']);
    }

    public function testLeavesBareMentionAlone(): void
    {
        $converter = $this->converter();
        $document = $converter->parse('@alice');
        $paragraph = $document->getChildren()[0];

        $this->assertInstanceOf(Paragraph::class, $paragraph);
        $this->assertSame('mention', $paragraph->getChildren()[0]->getType());
    }

    public function testDoesNotClaimReferenceLink(): void
    {
        $this->assertNull($this->firstCitationGroup('[text][ref]'));
    }

    public function testDeclinesPlainBracketWithNoKey(): void
    {
        $this->assertNull($this->firstCitationGroup('[just text]'));
    }

    public function testRenderWithoutCitationGroupsLeavesPlainDocumentUntouched(): void
    {
        $this->assertSame('<p>Plain document.</p>', $this->html('Plain document.'));
    }

    public function testRecognizesCitationAfterUnmatchedOpeningBracket(): void
    {
        // An earlier unmatched `[` must not suppress a later balanced citation
        // in the same text node (regression for the bracket-pair precompute).
        $group = $this->firstCitationGroup('x [bad [@doe]');

        $this->assertInstanceOf(CitationGroup::class, $group);
        $this->assertSame('doe', $group->getItems()[0]['key']);
    }

    public function testParsesLocator(): void
    {
        $group = $this->firstCitationGroup('[@smith2020, p. 33]');

        $this->assertInstanceOf(CitationGroup::class, $group);
        $this->assertSame('smith2020', $group->getItems()[0]['key']);
        $this->assertArrayHasKey('locator', $group->getItems()[0]);
    }

    public function testParsesSuppressAuthor(): void
    {
        $group = $this->firstCitationGroup('[-@smith2020]');

        $this->assertInstanceOf(CitationGroup::class, $group);
        $this->assertTrue($group->getItems()[0]['suppressAuthor']);
    }

    public function testParsesMultipleItems(): void
    {
        $group = $this->firstCitationGroup('[@a; @b]');

        $this->assertInstanceOf(CitationGroup::class, $group);
        $this->assertSame(['a', 'b'], array_column($group->getItems(), 'key'));
    }

    public function testDropsDefinitionParagraphAndNumbersCitation(): void
    {
        $html = $this->html("See [@smith2020].\n\n[@smith2020]: Smith, J. (2020). Title.");

        $this->assertStringContainsString('data-cite-key="smith2020" href="#ref-smith2020">1</a>', $html);
        $this->assertStringNotContainsString('<p>Smith, J. (2020). Title.</p>', $html);
    }

    public function testBuildsReferencesListWithStableIds(): void
    {
        $html = $this->html("[@a].\n\n[@a]: Entry A.");

        $this->assertStringContainsString('<ol class="references">', $html);
        $this->assertStringContainsString('<li id="ref-a">Entry A.</li>', $html);
    }

    public function testNoCollisionIdsRemainStable(): void
    {
        $html = $this->bibHtml('See [@foo].', [
            ['id' => 'foo', 'title' => 'Foo'],
        ]);

        $this->assertStringContainsString('<a id="cite-foo-1" data-cite-key="foo" href="#ref-foo">1</a>', $html);
        $this->assertStringContainsString('<li id="ref-foo">Foo. <a href="#cite-foo-1"', $html);
    }

    public function testCitationAnchorIdsAvoidHeadingIdsAndBackrefsFollow(): void
    {
        $html = $this->bibHtml("# cite foo 1\n\nSee [@foo].", [
            ['id' => 'foo', 'title' => 'Foo'],
        ]);

        $this->assertStringContainsString('<section id="cite-foo-1">', $html);
        $this->assertStringContainsString('<a id="cite-foo-1-2" data-cite-key="foo" href="#ref-foo">1</a>', $html);
        $this->assertStringContainsString('<a href="#cite-foo-1-2" class="ref-backref">', $html);
    }

    public function testReferenceIdsAvoidExplicitIdsAndCitationsFollow(): void
    {
        $html = $this->bibHtml("{#ref-foo}\nReserved.\n\nSee [@foo].", [
            ['id' => 'foo', 'title' => 'Foo'],
        ]);

        $this->assertStringContainsString('<a id="cite-foo-1" data-cite-key="foo" href="#ref-foo-2">1</a>', $html);
        $this->assertStringContainsString('<li id="ref-foo-2">Foo.', $html);
    }

    public function testNumbersByFirstCitationOrder(): void
    {
        $html = $this->html("[@b] then [@a].\n\n[@a]: A.\n\n[@b]: B.");

        $this->assertStringContainsString('href="#ref-b">1</a>', $html);
        $this->assertStringContainsString('href="#ref-a">2</a>', $html);
    }

    public function testRendersLocatorAndPrefixInsideBrackets(): void
    {
        $html = $this->html("[see @a, p. 3].\n\n[@a]: A.");

        $this->assertStringContainsString('data-cite-key="a" data-cite-prefix="see" data-locator-label="page" data-locator="3" href="#ref-a">1</a>', $html);
    }

    public function testRendersUndefinedKeyVerbatim(): void
    {
        $this->assertStringContainsString('[@nope]', $this->html('[@nope].'));
    }

    public function testKeepsMentionWorking(): void
    {
        $this->assertStringContainsString('class="mention"', $this->html('@alice'));
    }

    public function testCollectsConsecutiveDefinitionLines(): void
    {
        $html = $this->html("[@a] and [@b].\n\n[@a]: First.\n[@b]: Second.");

        $this->assertStringContainsString('href="#ref-a">1</a>', $html);
        $this->assertStringContainsString('href="#ref-b">2</a>', $html);
        $this->assertStringContainsString('<li id="ref-a">First.</li>', $html);
        $this->assertStringContainsString('<li id="ref-b">Second.</li>', $html);
    }

    public function testAuthorDateUsesDefinitionAttributes(): void
    {
        $html = $this->authorDateHtml('See [@s].' . "\n\n" . '[@s]: {author="Smith" year="2020"} Smith, J.');

        $this->assertStringContainsString('data-cite-key="s" href="#ref-s">Smith 2020</a>', $html);
    }

    public function testAuthorDateSuppressesAuthor(): void
    {
        $html = $this->authorDateHtml('[-@s].' . "\n\n" . '[@s]: {author="Smith" year="2020"} S.');

        $this->assertStringContainsString('>2020</a>', $html);
    }

    public function testStateIsolationForReusedExtension(): void
    {
        $extension = new CitationsExtension();
        $converter = new CarveConverter();
        $converter->addExtension($extension);

        $converter->convert("[@a].\n\n[@a]: First doc A.");
        $html = trim($converter->convert('[@a].'));

        $this->assertStringContainsString('[@a]', $html);
        $this->assertStringNotContainsString('href="#ref-a"', $html);
    }

    public function testInjectsIntoExplicitReferencesBlock(): void
    {
        $html = $this->html("[@a].\n\n::: references\n:::\n\n[@a]: A.");

        $this->assertMatchesRegularExpression('/<div class="references">[\s\S]*<ol class="references">/', $html);
    }

    // A WALL-CLOCK BOUND, so it measures the runner as much as the code and
    // belongs on the one that runs alone. phpunit.xml.dist says why the group
    // exists: the measurement "is only meaningful on an unloaded runner".
    #[Group('scaling')]
    public function testUnmatchedBracketRunParsesFast(): void
    {
        $source = str_repeat('[', 8000);
        $start = microtime(true);

        $html = $this->html($source);

        $this->assertSame('<p>' . $source . '</p>', $html);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    // ----- Tier-3 Bibliography (#199) ---------------------------------------

    public function testBibliographyResolvesPoolEntry(): void
    {
        $html = $this->bibHtml('See [@smith2020].', [$this->smith()]);

        $this->assertStringContainsString('<a id="cite-smith2020-1" data-cite-key="smith2020" href="#ref-smith2020">1</a>', $html);
        $this->assertStringContainsString(
            '<li id="ref-smith2020">Smith, John (2020). A Study. '
                . '<a href="#cite-smith2020-1" class="ref-backref">↩</a></li>',
            $html,
        );
    }

    public function testInDocumentDefinitionOverridesPool(): void
    {
        $html = $this->bibHtml("See [@smith2020].\n\n[@smith2020]: In-doc entry.", [$this->smith()]);

        $this->assertStringContainsString('<li id="ref-smith2020">In-doc entry.', $html);
        $this->assertStringNotContainsString('A Study', $html);
    }

    public function testBibliographyEmitsOneBackLinkPerUseSite(): void
    {
        $html = $this->bibHtml('[@smith2020] then [@smith2020] again.', [$this->smith()]);

        $this->assertStringContainsString('<a id="cite-smith2020-1" data-cite-key="smith2020" href="#ref-smith2020">1</a>', $html);
        $this->assertStringContainsString('<a id="cite-smith2020-2" data-cite-key="smith2020" href="#ref-smith2020">1</a>', $html);
        $this->assertStringContainsString('<a href="#cite-smith2020-1" class="ref-backref">↩</a>', $html);
        $this->assertStringContainsString('<a href="#cite-smith2020-2" class="ref-backref">↩</a>', $html);
    }

    public function testMultiKeyGroupAnchorsEachKey(): void
    {
        $html = $this->bibHtml('[@a; @b]', [
            ['id' => 'a', 'title' => 'Alpha'],
            ['id' => 'b', 'title' => 'Beta'],
        ]);

        $this->assertStringContainsString('<a id="cite-a-1" data-cite-key="a" href="#ref-a">1</a>', $html);
        $this->assertStringContainsString('<a id="cite-b-1" data-cite-key="b" href="#ref-b">2</a>', $html);
    }

    public function testUnresolvedKeyRendersVerbatim(): void
    {
        $html = $this->bibHtml('[@nope]', [$this->smith()]);

        $this->assertStringContainsString('[@nope]', $html);
        $this->assertStringNotContainsString('cite-nope', $html);
        $this->assertStringNotContainsString('class="references"', $html);
    }

    public function testPartiallyResolvedGroupIsFullyVerbatim(): void
    {
        // [@a; @missing] renders verbatim because @missing is unresolved; @a
        // must NOT leak into the references list or produce an orphan back-ref.
        $html = $this->bibHtml('[@a; @missing]', [['id' => 'a', 'title' => 'Alpha']]);

        $this->assertStringContainsString('[@a; @missing]', $html);
        $this->assertStringNotContainsString('class="references"', $html);
        $this->assertStringNotContainsString('ref-backref', $html);
        $this->assertStringNotContainsString('id="cite-a-1"', $html);
    }

    public function testCslEntryTextIsEscapedAsPlainData(): void
    {
        $html = $this->bibHtml('[@x]', [['id' => 'x', 'title' => '<b>raw</b> & co']]);

        $this->assertStringContainsString('&lt;b&gt;raw&lt;/b&gt; &amp; co.', $html);
    }

    /**
     * @param array<string, mixed> $csl
     * @param string $expected
     */
    #[DataProvider('cslFormatterProvider')]
    public function testCslFormatter(array $csl, string $expected): void
    {
        $html = $this->bibHtml('[@x]', [['id' => 'x'] + $csl]);

        $this->assertStringContainsString('<li id="ref-x">' . $expected . ' <a href="#cite-x-1"', $html);
    }

    /**
     * @return array<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function cslFormatterProvider(): array
    {
        return [
            'author + year + title' => [
                ['author' => [['family' => 'Smith', 'given' => 'John']], 'issued' => ['date-parts' => [[2020]]], 'title' => 'T'],
                'Smith, John (2020). T.',
            ],
            'author only' => [['author' => [['family' => 'Doe']]], 'Doe.'],
            'year + title, no author' => [['issued' => ['date-parts' => [[1999]]], 'title' => 'T'], '(1999). T.'],
            'multiple authors' => [
                ['author' => [['family' => 'A', 'given' => 'X'], ['family' => 'B', 'given' => 'Y']], 'title' => 'T'],
                'A, X; B, Y. T.',
            ],
            'literal name and year' => [
                ['author' => [['literal' => 'WHO']], 'issued' => ['literal' => 'n.d.'], 'title' => 'T'],
                'WHO (n.d.). T.',
            ],
        ];
    }

    public function testNoPoolKeepsTier2Behavior(): void
    {
        $html = $this->html("[@a].\n\n[@a]: A.");

        $this->assertStringContainsString('<li id="ref-a">A.</li>', $html);
        $this->assertStringNotContainsString('ref-backref', $html);
        $this->assertStringNotContainsString('id="cite-a-1"', $html);
    }

    public function testTrailingCommaEmptyLocatorIsCitation(): void
    {
        // `[@key,]` - trailing comma, no locator - is a normal citation, not
        // verbatim text (carve#227; matches the carve-js oracle).
        $html = $this->html("See [@smith2020,].\n\n[@smith2020]: Smith, J. (2020).");

        $this->assertStringContainsString('href="#ref-smith2020">1</a>', $html);
        $this->assertStringNotContainsString('[@smith2020,]', $html);
        $this->assertStringNotContainsString('data-locator', $html);
    }

    private function converter(string $mode = 'numbered'): CarveConverter
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension($mode));

        return $converter;
    }

    /**
     * @param string $source
     * @param array<int, array<string, mixed>> $bibliography
     */
    private function bibHtml(string $source, array $bibliography): string
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension('numbered', $bibliography));

        return trim($converter->convert($source));
    }

    /**
     * @return array<string, mixed>
     */
    private function smith(): array
    {
        return [
            'id' => 'smith2020',
            'author' => [['family' => 'Smith', 'given' => 'John']],
            'issued' => ['date-parts' => [[2020]]],
            'title' => 'A Study',
        ];
    }

    private function html(string $source): string
    {
        return trim($this->converter()->convert($source));
    }

    private function authorDateHtml(string $source): string
    {
        return trim($this->converter('author-date')->convert($source));
    }

    private function firstCitationGroup(string $source): ?CitationGroup
    {
        $document = $this->converter()->parse($source);
        foreach ($document->getChildren() as $block) {
            if (!$block instanceof Paragraph) {
                continue;
            }
            foreach ($block->getChildren() as $inline) {
                if ($inline instanceof CitationGroup) {
                    return $inline;
                }
            }
        }

        return null;
    }
}

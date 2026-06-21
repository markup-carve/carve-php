<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Extension;

use Carve\CarveConverter;
use Carve\Extension\CitationsExtension;
use Carve\Node\Block\Paragraph;
use Carve\Node\Inline\CitationGroup;
use PHPUnit\Framework\TestCase;

class CitationsExtensionTest extends TestCase
{
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

        $this->assertStringContainsString('[<a href="#ref-smith2020">1</a>]', $html);
        $this->assertStringNotContainsString('<p>Smith, J. (2020). Title.</p>', $html);
    }

    public function testBuildsReferencesListWithStableIds(): void
    {
        $html = $this->html("[@a].\n\n[@a]: Entry A.");

        $this->assertStringContainsString('<ol class="references">', $html);
        $this->assertStringContainsString('<li id="ref-a">Entry A.</li>', $html);
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

        $this->assertStringContainsString('[see <a href="#ref-a">1</a>, p. 3]', $html);
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

        $this->assertStringContainsString('(<a href="#ref-s">Smith 2020</a>)', $html);
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

    public function testUnmatchedBracketRunParsesFast(): void
    {
        $source = str_repeat('[', 8000);
        $start = microtime(true);

        $html = $this->html($source);

        $this->assertSame('<p>' . $source . '</p>', $html);
        $this->assertLessThan(1.0, microtime(true) - $start);
    }

    private function converter(string $mode = 'numbered'): CarveConverter
    {
        $converter = new CarveConverter();
        $converter->addExtension(new CitationsExtension($mode));

        return $converter;
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

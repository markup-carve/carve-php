<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

/**
 * A profile filters what this engine PUBLISHES, not only what it renders.
 *
 * Filtering happened on the render path alone, so a host that denied a type and
 * then serialized `parse()`'s result shipped the denied content in the tree. The
 * HTML was correct and identical to carve-js and carve-rs; the AST carried the
 * code block the profile removed, contents and all (carve-php#853). That is the
 * pipeline the report describes - hand an untrusted document to a converter, then
 * pass the tree to a PDF renderer or an LSP - and it received the denial as a
 * suggestion.
 *
 * TWO THINGS HAD TO MOVE TOGETHER.
 *
 * The filter now runs at the end of `parse()`, BEFORE the text-run coalescer:
 * `to_text` degradation replaces nodes with Text, which leaves runs adjacent, and
 * PART 12 §1a is about the tree that gets published. Filtering after the coalescer
 * would satisfy this file's leak cases and publish three adjacent text runs where
 * §1a requires one - testTheDegradedTextIsStillCoalesced is what says so.
 *
 * And the render path had to stop filtering a document `parse()` already filtered.
 * `applyProfile()` clears the violation list first, so a second pass over an
 * already-clean tree finds nothing and `getProfileViolations()` after `convert()`
 * would come back EMPTY - a host in collect mode told the document was fine. A
 * document from anywhere else still gets filtered, which
 * testAForeignDocumentIsStillFiltered pins.
 */
class ProfileFiltersWhatIsPublishedTest extends TestCase
{
    /**
     * @var string
     */
    protected const WITH_CODE = "before\n\n```\nsecret code\n```\n\nafter\n";

    protected function strictProfile(): Profile
    {
        return Profile::full()->denyBlock(['code_block'])->onDisallowed(Profile::ACTION_STRIP);
    }

    protected function astJson(CarveConverter $converter, string $source): string
    {
        return (new AstCodec())->encodeJson($converter->parse($source));
    }

    public function testTheDeniedNodeIsNotPublished(): void
    {
        $json = $this->astJson(CarveConverter::create(profile: $this->strictProfile()), self::WITH_CODE);

        $this->assertStringNotContainsString('code_block', $json);
    }

    public function testTheDeniedCONTENTIsNotPublished(): void
    {
        // Asserted separately from the node type: a fix that renamed the node but
        // kept its text would pass the assertion above and still ship the secret.
        $json = $this->astJson(CarveConverter::create(profile: $this->strictProfile()), self::WITH_CODE);

        $this->assertStringNotContainsString('secret code', $json);
    }

    public function testTheSurvivingContentIsStillPublished(): void
    {
        // The boundary. Every assertion above would also pass if the profile had
        // started emptying the whole document.
        $json = $this->astJson(CarveConverter::create(profile: $this->strictProfile()), self::WITH_CODE);

        $this->assertStringContainsString('before', $json);
        $this->assertStringContainsString('after', $json);
    }

    public function testTheRenderedHtmlIsUnchanged(): void
    {
        // The HTML was already right, and has to stay right: the filter moved, it
        // did not get added.
        $html = CarveConverter::create(profile: $this->strictProfile())->convert(self::WITH_CODE);

        $this->assertSame("<p>before</p>\n<p>after</p>\n", $html);
    }

    public function testTheViolationSurvivesAConvert(): void
    {
        // The hazard the second half of the fix exists for. `parse()` filters and
        // records; the render path used to filter again, clearing the list first
        // and finding nothing left to deny.
        $converter = CarveConverter::create(profile: $this->strictProfile());
        $converter->convert(self::WITH_CODE);

        $this->assertTrue($converter->hasProfileViolations(), 'the denial went unreported');
        $types = array_map(
            static fn ($violation): string => $violation->nodeType,
            $converter->getProfileViolations(),
        );
        $this->assertContains('code_block', $types);
    }

    public function testTheViolationIsReportedAfterAParseAlone(): void
    {
        // A host that only publishes the tree never calls render, and still has to
        // learn what was dropped.
        $converter = CarveConverter::create(profile: $this->strictProfile());
        $converter->parse(self::WITH_CODE);

        $this->assertTrue($converter->hasProfileViolations());
    }

    public function testParsedDocumentsAreNotRetainedByTheConverter(): void
    {
        $converter = CarveConverter::create(profile: $this->strictProfile());
        $document = $converter->parse(self::WITH_CODE);

        $property = new ReflectionProperty($converter, 'filteredDocuments');
        /** @var \WeakMap<\MarkupCarve\Carve\Node\Document, true> $filteredDocuments */
        $filteredDocuments = $property->getValue($converter);
        $this->assertCount(1, $filteredDocuments);

        $nextDocument = $converter->parse("still here\n");
        unset($document);
        gc_collect_cycles();

        $this->assertCount(1, $filteredDocuments, 'a reusable converter retained its previous AST');
        $this->assertTrue(isset($filteredDocuments[$nextDocument]));
    }

    public function testAForeignDocumentIsStillFiltered(): void
    {
        // The branch the skip must not swallow: a document this converter did not
        // parse - hand-built here, but equally one decoded from JSON - has never
        // been filtered, so `render()` still has to do it.
        //
        // A CODE BLOCK, not a paragraph. Denying `paragraph` proves nothing:
        // `to_text` degrades a block node by wrapping its text in a paragraph, so
        // a denied paragraph renders as a paragraph and the filter's work is
        // invisible.
        $document = new Document();
        $document->appendChild(new CodeBlock("secret code\n"));

        $converter = CarveConverter::create(profile: $this->strictProfile());
        $html = $converter->render($document);

        $this->assertStringNotContainsString('secret code', $html);
        $this->assertTrue($converter->hasProfileViolations());
    }

    public function testTheDegradedTextIsStillCoalesced(): void
    {
        // PART 12 §1a, and the reason the filter runs BEFORE the coalescer. `to_text`
        // turns the denied `strong` into a Text node between two existing ones; all
        // three are one run in the published tree.
        //
        // Filtering after the coalescer would leave three adjacent text nodes here
        // and pass every other case in this file.
        $converter = CarveConverter::create(profile: Profile::full()->denyInline(['strong']));
        $tree = (new AstCodec())->encode($converter->parse("a *bold* b\n"));

        $children = $tree['children'][0]['children'] ?? [];
        $this->assertCount(1, $children, (string)json_encode($children));
        $this->assertSame('a bold b', $children[0]['value'] ?? null);
    }

    public function testNothingChangesWithoutAProfile(): void
    {
        $json = $this->astJson(new CarveConverter(), self::WITH_CODE);

        $this->assertStringContainsString('code_block', $json);
        $this->assertStringContainsString('secret code', $json);
    }

    public function testToTextPublishesTheDegradedTextRatherThanNothing(): void
    {
        // `strip` removes; the default action degrades, and what it degrades to is
        // published. Otherwise "the tree is filtered" could be satisfied by
        // dropping content the action says to keep.
        $converter = CarveConverter::create(profile: Profile::full()->denyBlock(['code_block']));
        $json = $this->astJson($converter, self::WITH_CODE);

        $this->assertStringNotContainsString('code_block', $json);
        $this->assertStringContainsString('secret code', $json);
    }
}

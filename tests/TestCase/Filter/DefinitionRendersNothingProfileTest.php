<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Denying a definition that renders nothing removes it, rather than putting the
 * node's type name on the page (carve-php#855).
 *
 * The filter degrades a denied node to its text content, and a node it cannot
 * extract text from takes a deliberate diagnostic path: substitute
 * `[<type>]`, a marker "deliberately ugly enough that it cannot pass for
 * intended output". The marker was doing its job - `rendersNothing()` should
 * have claimed the node before it got there.
 *
 * That predicate listed Comment, Frontmatter and Footnote. Frontmatter is in it
 * for this exact symptom, and its own comment says so. A link reference
 * definition is the same shape and was left behind, so denying one under the
 * DEFAULT action produced:
 *
 *     <p>See <a href="/u">x</a>.</p>
 *     <p>[link_reference_definition]</p>
 *
 * `docs/profiles.md` names the pair: "`link_reference_definition` is the
 * `abbreviation_def` case exactly: the definition line renders nothing in HTML,
 * so denying it would not stop anything reaching the page - the `link` or
 * `image` it feeds is the node a profile denies."
 *
 * That last clause is the part not to overreach on, and it is asserted below:
 * the link and the abbreviation still render. Denying the definition denies the
 * definition, unlike a footnote, whose reference has nowhere to point once its
 * own definition is gone (#850).
 */
class DefinitionRendersNothingProfileTest extends TestCase
{
    /**
     * @var string
     */
    private const LINK_REF = "See [x][y].\n\n[y]: /u\n";

    /**
     * @var string
     */
    private const ABBREV = "HTML is fine.\n\n*[HTML]: HyperText\n";

    private function denied(string $source, string $type, string $action): string
    {
        $converter = new CarveConverter();
        $converter->setProfile(Profile::full()->denyBlock([$type])->onDisallowed($action));

        return trim((string)preg_replace('/\s+/', ' ', $converter->convert($source)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function actions(): array
    {
        return [
            'to_text (the default)' => [Profile::ACTION_TO_TEXT],
            'strip' => [Profile::ACTION_STRIP],
        ];
    }

    #[DataProvider('actions')]
    public function testADeniedLinkReferenceDefinitionPutsNothingOnThePage(string $action): void
    {
        $html = $this->denied(self::LINK_REF, 'link_reference_definition', $action);

        $this->assertStringNotContainsString('[link_reference_definition]', $html);
        $this->assertSame('<p>See <a href="/u">x</a>.</p>', $html);
    }

    #[DataProvider('actions')]
    public function testADeniedAbbreviationDefinitionPutsNothingOnThePage(string $action): void
    {
        $html = $this->denied(self::ABBREV, 'abbreviation_def', $action);

        $this->assertStringNotContainsString('[abbreviation_def]', $html);
        $this->assertSame('<p><abbr title="HyperText">HTML</abbr> is fine.</p>', $html);
    }

    public function testTheNodeTheDefinitionFeedsStillRenders(): void
    {
        $this->assertStringContainsString(
            '<a href="/u">',
            $this->denied(self::LINK_REF, 'link_reference_definition', Profile::ACTION_STRIP),
        );
        $this->assertStringContainsString(
            '<abbr ',
            $this->denied(self::ABBREV, 'abbreviation_def', Profile::ACTION_STRIP),
        );
    }

    public function testACommentStillRendersNothing(): void
    {
        // A member the predicate already had, kept so a repair of the other two
        // cannot quietly change it.
        $this->assertSame(
            '<p>a</p> <p>b</p>',
            $this->denied("a\n\n%% hidden\n\nb\n", 'comment', Profile::ACTION_TO_TEXT),
        );
    }

    public function testAnUndeniedDocumentIsUnaffected(): void
    {
        $converter = new CarveConverter();
        $this->assertStringContainsString('<a href="/u">', $converter->convert(self::LINK_REF));
        $this->assertStringContainsString('<abbr ', $converter->convert(self::ABBREV));
    }
}

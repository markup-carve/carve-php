<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\TestCase;

/**
 * profiles.md requires `autolink` and `admonition` to be nameable on their own:
 * an autolink is not a `link` (folding it in loses the authored form a round
 * trip has to restore), and an admonition is not a `div` (a profile that wants
 * to deny callouts while allowing generic containers cannot say so if the kind
 * lives in a class string).
 *
 * Neither is a node class of its own here - an autolink is a Link carrying a
 * flag, an admonition a Div carrying one - so the filter matched on the broader
 * name and a profile naming the narrower one matched nothing. That was silent:
 * a host could deny autolinks, get no error and no violation, and still emit
 * them (carve#362).
 *
 * They stay COVERED BY the broader name: unfolding them without that would
 * quietly widen every profile already relying on `link` or `div`.
 */
class ProfileSubtypeTest extends TestCase
{
    /**
     * @var string
     */
    protected const AUTOLINK = "See <https://example.com> here.\n";

    /**
     * @var string
     */
    protected const ADMONITION = "::: note\ncallout\n:::\n";

    /**
     * A named div whose type word is NOT one of the Tier-1 callout kinds
     * (grammar PART 9 §12). `isTyped()` is true here exactly as it is for
     * `::: note`, which is why `isTyped()` alone cannot be the classification
     * predicate (carve#507): this must classify as `div`, not `admonition`.
     *
     * @var string
     */
    protected const NAMED_CONTAINER = "::: sidebar\ncontent\n:::\n";

    protected function html(string $source, Profile $profile): string
    {
        return (new CarveConverter(profile: $profile))->convert($source);
    }

    /**
     * The canonical types (per {@see Profile::canonicalTypeOf()}) of every
     * node the source produces, in document order.
     *
     * @return list<string>
     */
    protected function canonicalTypesOf(string $source): array
    {
        $found = [];
        $walk = function (Node $node) use (&$walk, &$found): void {
            $found[] = Profile::canonicalTypeOf($node);
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        return $found;
    }

    public function testDeniesAnAutolinkWhenTheProfileNamesIt(): void
    {
        $this->assertStringNotContainsString('<a ', $this->html(self::AUTOLINK, Profile::full()->denyInline(['autolink'])));
    }

    public function testStillDeniesAnAutolinkWhenTheProfileNamesLink(): void
    {
        $this->assertStringNotContainsString('<a ', $this->html(self::AUTOLINK, Profile::full()->denyInline(['link'])));
    }

    public function testKeepsOrdinaryLinksWhenOnlyAutolinkIsDenied(): void
    {
        $out = $this->html(
            "A [real](https://a.example) and <https://b.example>.\n",
            Profile::full()->denyInline(['autolink']),
        );
        $this->assertStringContainsString('href="https://a.example"', $out);
        $this->assertStringNotContainsString('href="https://b.example"', $out);
    }

    public function testDeniesAnAdmonitionWhenTheProfileNamesIt(): void
    {
        $this->assertStringNotContainsString('<aside', $this->html(self::ADMONITION, Profile::full()->denyBlock(['admonition'])));
    }

    public function testStillDeniesAnAdmonitionWhenTheProfileNamesDiv(): void
    {
        $this->assertStringNotContainsString('<aside', $this->html(self::ADMONITION, Profile::full()->denyBlock(['div'])));
    }

    public function testKeepsGenericContainersWhenOnlyAdmonitionIsDenied(): void
    {
        // The case profiles.md names: deny callouts, allow generic containers.
        $source = self::ADMONITION . "\n{.wrap}\n:::\ngeneric\n:::\n";
        $out = $this->html($source, Profile::full()->denyBlock(['admonition']));
        $this->assertStringNotContainsString('<aside', $out);
        $this->assertStringContainsString('<div class="wrap">', $out);
    }

    public function testAdmitsASubtypeThroughAnAllowListNamingItsSupertype(): void
    {
        $out = $this->html(self::AUTOLINK, Profile::full()->allowInline(['text', 'link']));
        $this->assertStringContainsString('<a ', $out);
    }

    /**
     * carve#507: a named div that is NOT a Tier-1 callout kind must classify
     * as `div`, not `admonition` - `isTyped()` alone (true for `::: sidebar`
     * just as it is for `::: note`) is not the right predicate.
     */
    public function testANamedContainerWithoutACalloutClassClassifiesAsDivNotAdmonition(): void
    {
        $types = $this->canonicalTypesOf(self::NAMED_CONTAINER);
        $this->assertContains('div', $types);
        $this->assertNotContains('admonition', $types);
    }

    public function testANamedCalloutClassifiesAsAdmonition(): void
    {
        $this->assertContains('admonition', $this->canonicalTypesOf(self::ADMONITION));
    }

    /**
     * `{.warning}` attached above the opener adds a Tier-1 class to a div that
     * is ALSO named for something else (`sidebar`). It renders as
     * `<aside class="admonition warning sidebar">`, so it must classify as
     * `admonition` too - classification has to agree with what actually
     * renders, not merely with the opener's own type word.
     */
    public function testAnAttributeLineCalloutClassMakesANamedContainerClassifyAsAdmonition(): void
    {
        $source = "{.warning}\n" . self::NAMED_CONTAINER;
        $this->assertContains('admonition', $this->canonicalTypesOf($source));
    }

    public function testDenyingAdmonitionNoLongerStripsAPlainNamedContainer(): void
    {
        $out = $this->html(self::NAMED_CONTAINER, Profile::full()->denyBlock(['admonition']));
        $this->assertStringContainsString('<div class="sidebar">', $out);
    }

    public function testDenyingDivStillStripsAPlainNamedContainer(): void
    {
        $out = $this->html(self::NAMED_CONTAINER, Profile::full()->denyBlock(['div']));
        $this->assertStringNotContainsString('<div class="sidebar">', $out);
    }
}

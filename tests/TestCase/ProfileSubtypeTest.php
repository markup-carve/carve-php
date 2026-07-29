<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
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

    protected function html(string $source, Profile $profile): string
    {
        return (new CarveConverter(profile: $profile))->convert($source);
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
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The `carve` target writes the document back, profile or no profile.
 *
 * A profile is a statement about what may be RENDERED. This target does not
 * render - PART 11 §1 makes its contract `to_html(fmt(x)) == to_html(x)`, which
 * is to reproduce the document rather than a permitted subset of it. So a
 * profile must not filter, alter or annotate what it writes (profiles.md, "The
 * `carve` target does not apply a profile"; carve#759).
 *
 * This engine applied the profile here, which showed up in two ways: a link
 * policy ADDED `{rel="nofollow ugc"}` to source the author never wrote, and a
 * denied type had its text DROPPED - content loss in the one target whose
 * purpose is to give the author's document back.
 *
 * The asymmetry is why the rule points this way: a host that wanted filtering
 * can still render through a filtered target, while a host that did not want it
 * has lost the user's text with nothing saying so.
 */
class CarveTargetIgnoresProfileTest extends TestCase
{
    protected function carve(string $source, ?Profile $profile = null): string
    {
        $converter = new CarveConverter(profile: $profile, renderer: new CarveRenderer());

        return $converter->convert($source);
    }

    public function testALinkPolicyDoesNotAnnotateTheWrittenSource(): void
    {
        $source = "text with a [link](/u) here\n";

        $this->assertSame(
            $this->carve($source),
            $this->carve($source, Profile::comment()),
            'the comment profile added link-policy attributes to the written source',
        );
    }

    public function testADeniedTypeKeepsItsSource(): void
    {
        // `minimal` denies far more than `comment`; under it the image and the
        // link both have to survive the WRITER, whatever a renderer would do
        // with them.
        $source = "an ![image](p.png) and a [link](/u)\n";

        $this->assertSame($this->carve($source), $this->carve($source, Profile::minimal()));
    }

    public function testTheDocumentIsWrittenBackUnchanged(): void
    {
        // The invariant behind the rule, stated directly: whatever the profile,
        // the canonical form of this document is the same string.
        $source = "# Heading\n\ntext with a [link](/u) and an ![image](p.png)\n";
        $expected = $this->carve($source);

        foreach ([Profile::full(), Profile::article(), Profile::comment(), Profile::minimal()] as $profile) {
            $this->assertSame($expected, $this->carve($source, $profile));
        }
    }

    public function testTheHtmlTargetStillAppliesTheProfile(): void
    {
        // The control. If the profile stopped applying everywhere, every
        // assertion above would pass for the wrong reason.
        $source = "text with a [link](/u) here\n";
        $plain = (new CarveConverter())->convert($source);
        $filtered = (new CarveConverter(profile: Profile::comment()))->convert($source);

        $this->assertNotSame($plain, $filtered, 'the html target stopped applying the profile');
    }
}

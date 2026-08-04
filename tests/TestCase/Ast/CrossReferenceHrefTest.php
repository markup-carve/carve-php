<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

/**
 * PART 12 §3a, extended to crossrefs by markup-carve/carve#614: a crossref
 * serializes as a `heading_ref` carrying the authored id in `target`, and
 * where it RESOLVES the destination is published beside it in `href`.
 *
 * This engine published the authored half only, so a consumer decoding the
 * tree had to rebuild the heading-id table - including the case-insensitive
 * fallback and the not-found case - to render a crossref, which is the
 * recomputation §5 exists to prevent (carve-php#735).
 */
class CrossReferenceHrefTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    protected function firstHeadingRef(string $source): array
    {
        $document = (new CarveConverter())->parse($source);
        $encoded = (new AstCodec())->encode($document);

        $found = null;
        $walk = function (array $node) use (&$walk, &$found): void {
            if (($node['type'] ?? null) === 'heading_ref' && $found === null) {
                $found = $node;
            }
            foreach ($node['children'] ?? [] as $child) {
                $walk($child);
            }
        };
        $walk($encoded);

        $this->assertIsArray($found, 'no heading_ref node in the encoded tree');

        return $found;
    }

    public function testAResolvedCrossrefPublishesTheDestination(): void
    {
        $node = $this->firstHeadingRef("# Intro\n\nSee </#intro>.");

        $this->assertSame('intro', $node['target'], 'the authored id survives');
        $this->assertSame('#Intro', $node['href'], 'the ACTUAL id, case-preserved');
    }

    public function testAnUnresolvedCrossrefPublishesNoDestination(): void
    {
        // No href is what says it did not resolve.
        $node = $this->firstHeadingRef('See </#Nope>.');

        $this->assertSame('Nope', $node['target']);
        $this->assertArrayNotHasKey('href', $node);
    }

    public function testTheCaseInsensitiveFallbackIsReflectedInTheHref(): void
    {
        $node = $this->firstHeadingRef("# Getting Started\n\nSee </#getting-started>.");

        $this->assertSame('getting-started', $node['target']);
        $this->assertSame('#Getting-Started', $node['href']);
    }

    public function testTheRenderedOutputIsUnchanged(): void
    {
        $html = (new CarveConverter())->convert("# Intro\n\nSee </#intro>.");

        $this->assertStringContainsString('<a href="#Intro">Intro</a>', $html);
    }

    public function testTheHrefSurvivesADecodeAndReEncode(): void
    {
        $codec = new AstCodec();
        $json = $codec->encodeJson((new CarveConverter())->parse("# Intro\n\nSee </#intro>."));
        $document = $codec->decodeJson($json);

        $encoded = $codec->encode($document);
        $paragraph = $encoded['children'][0]['children'][1] ?? $encoded['children'][1];
        $ref = null;
        foreach ($paragraph['children'] ?? [] as $child) {
            if (($child['type'] ?? null) === 'heading_ref') {
                $ref = $child;
            }
        }

        $this->assertIsArray($ref);
        $this->assertSame('#Intro', $ref['href']);
    }
}

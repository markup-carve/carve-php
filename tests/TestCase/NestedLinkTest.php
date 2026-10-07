<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\AutolinkExtension;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\Emphasis;
use MarkupCarve\Carve\Node\Inline\Link;
use MarkupCarve\Carve\Node\Inline\Ruby;
use MarkupCarve\Carve\Node\Inline\Text;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Links never nest: any link inside a link label is unwrapped so only the
 * outermost destination applies, while the inner link keeps just its text.
 */
class NestedLinkTest extends TestCase
{
    use ScalingGuardTrait;

    #[Group('scaling')]
    public function testManyNestedSiblingLinksScaleLinearly(): void
    {
        $converter = new CarveConverter();
        $small = '[' . str_repeat('[x](/inner) ', 8192) . '](/outer)';
        $large = '[' . str_repeat('[x](/inner) ', 32768) . '](/outer)';
        $documents = [strlen($small) => $converter->parse($small), strlen($large) => $converter->parse($large)];
        $this->assertConversionScalesLinearly(
            static fn (string $source): string => $converter->render(clone $documents[strlen($source)]),
            $small,
            $large,
            'nested sibling links',
            8192,
            32768,
            maxPerByteRatio: 2.0,
        );
    }

    public function testNestedLinksInRubyUpdateThePairFields(): void
    {
        $base = new Link('/inner');
        $base->appendChild(new Text('x'));
        $annotation = new Link('/inner');
        $annotation->appendChild(new Text('a'));
        $ruby = new Ruby([['base' => [$base], 'annotation' => [$annotation]]]);
        $outer = new Link('/outer');
        $outer->appendChild($ruby);
        $paragraph = new Paragraph();
        $paragraph->appendChild($outer);
        $document = new Document();
        $document->appendChild($paragraph);
        $html = (new CarveConverter())->render($document);
        self::assertStringNotContainsString('/inner', $html);
        self::assertInstanceOf(Text::class, $ruby->getPairs()[0]['base'][0]);
        self::assertInstanceOf(Text::class, $ruby->getPairs()[0]['annotation'][0]);
        self::assertSame('x', $ruby->getPairs()[0]['base'][0]->getContent());
        self::assertSame('a', $ruby->getPairs()[0]['annotation'][0]->getContent());
    }

    public function testCustomReplacementHooksStillRun(): void
    {
        $custom = new class extends Emphasis {
            public int $replacements = 0;

            public function replaceChild(int $index, Node $child): void
            {
                $this->replacements++;
                parent::replaceChild($index, $child);
            }
        };
        $inner = new Link('/inner');
        $inner->appendChild(new Text('x'));
        $custom->appendChild($inner);
        $outer = new Link('/outer');
        $outer->appendChild($custom);
        $paragraph = new Paragraph();
        $paragraph->appendChild($outer);
        $document = new Document();
        $document->appendChild($paragraph);
        (new CarveConverter())->render($document);
        self::assertSame(1, $custom->replacements);
    }

    #[DataProvider('nestedLinkProvider')]
    public function testLinksNeverNest(string $input, bool $autolink, string $expected): void
    {
        $converter = new CarveConverter();
        if ($autolink) {
            $converter->addExtension(new AutolinkExtension());
        }

        $this->assertSame($expected, trim($converter->convert($input)));
    }

    /**
     * A `</#id>` cross-reference inside a link label becomes an anchor only at
     * render time, so it is the post-resolution pass that must flatten it to the
     * target heading's text. The outer link must stay a single anchor with no
     * nested `<a>`.
     *
     * Heading ids are case-preserving in carve-php, so the emitted href may be
     * `#H` rather than the lowercase `#h` other implementations produce; that
     * id-casing difference is a known, pre-existing divergence. We assert only
     * that there is no nested anchor and that the inner cross-reference became
     * plain text inside the outer link.
     */
    public function testCrossReferenceInLinkLabelIsFlattened(): void
    {
        $converter = new CarveConverter();
        $html = $converter->convert("# H\n\n[see </#H>](/outer)");

        // The outer link survives as a single anchor with the cross-reference
        // flattened to the heading text.
        $this->assertStringContainsString('<a href="/outer">see H</a>', $html);
        // No nested anchor: the inner cross-reference must NOT have produced an
        // <a> inside the outer link.
        $this->assertStringNotContainsString('see <a', $html);
        // The standalone heading still renders normally.
        $this->assertStringContainsString('<h1>H</h1>', $html);
    }

    /**
     * @return array<string, array{string, bool, string}>
     */
    public static function nestedLinkProvider(): array
    {
        return [
            'autolink extension bare URL in label' => [
                '[https://x.com](https://y.com)',
                true,
                '<p><a href="https://y.com">https://x.com</a></p>',
            ],
            'explicit nested brackets' => [
                '[[x](y)](z)',
                false,
                '<p><a href="z">x</a></p>',
            ],
            'core angle autolink in label' => [
                '[pre <http://h> post](/u)',
                false,
                '<p><a href="/u">pre http://h post</a></p>',
            ],
            'literal brackets are not a link' => [
                '[a [b] c](/u)',
                false,
                '<p><a href="/u">a [b] c</a></p>',
            ],
            'email autolink drops mailto scheme in display text' => [
                '[mail <a@b.com> here](/u)',
                false,
                '<p><a href="/u">mail a@b.com here</a></p>',
            ],
            'top-level autolink outside a label still links' => [
                'plain https://x.com here',
                true,
                '<p>plain <a href="https://x.com">https://x.com</a> here</p>',
            ],
            'link buried inside emphasis is unwrapped too' => [
                '[*em https://x.com*](/u)',
                true,
                '<p><a href="/u"><strong>em https://x.com</strong></a></p>',
            ],
            // A nested reference link cannot resolve (links never nest), so the
            // inner `[x][missing]` keeps its literal source rather than being
            // unwrapped to just its parsed children.
            'unresolved reference link in label stays literal' => [
                '[[x][missing]](/z)',
                false,
                '<p><a href="/z">[x][missing]</a></p>',
            ],
            // A RESOLVED reference link inside a label becomes a Link node only
            // during resolution, so the post-resolution pass unwraps it to its
            // DISPLAY text (`x`), dropping the inner destination. The earlier
            // parse-time approach got this wrong because the inner link was not
            // yet resolved when the label was parsed.
            'resolved reference link in label unwraps to display text' => [
                "[good]: /g\n\n[[x][good]](/z)",
                false,
                '<p><a href="/z">x</a></p>',
            ],
            // A footnote body renders in the endnotes section, OUTSIDE the link
            // anchor, so a link inside the footnote body is not a nested anchor
            // and must survive intact.
            'link inside footnote body in label survives' => [
                '[x ^[see [y](/inner)]](/outer)',
                false,
                '<p><a href="/outer">x <a id="fnref1" href="#fn1" role="doc-noteref">'
                    . "<sup>1</sup></a></a></p>\n"
                    . "<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n"
                    . "    <li id=\"fn1\">\n"
                    . '      <p>see <a href="/inner">y</a>'
                    . "<a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">\u{21A9}</a></p>\n"
                    . "    </li>\n  </ol>\n</section>",
            ],
        ];
    }
}

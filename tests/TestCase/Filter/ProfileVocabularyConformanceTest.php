<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CitationsExtension;
use MarkupCarve\Carve\Node\Node;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Every type in the normative profile vocabulary must actually be deniable.
 *
 * `docs/profiles.md` in the spec repo calls its type list normative: a profile's
 * allow/deny lists "use these exact type strings", and an implementation "MUST
 * expose the same node-type vocabulary". Nothing checked that. Naming a type the
 * parser never produces is silently accepted - the document renders in full and
 * no violation is reported - so a host restricting untrusted input can believe it
 * disabled a construct and be wrong.
 *
 * The signal here is the reported VIOLATION, not the rendered output. Comparing
 * HTML gives false positives: `to_text` is identity for a text-like node, so
 * denying `text` or `paragraph` legitimately changes nothing visible while still
 * being enforced.
 */
class ProfileVocabularyConformanceTest extends TestCase
{
    /**
     * Types the spec lists that carve-php cannot currently match, with the reason.
     *
     * This list is a RATCHET: it may shrink, never grow. Each entry is a place a
     * profile silently does nothing. See markup-carve/carve#362 - the fix
     * direction is a spec decision, because making `autolink` separately
     * deniable would stop `denyInline(['link'])` from covering autolinks, which
     * is a security-relevant change rather than a pure bug fix.
     *
     * @var array<string, string>
     */
    private const KNOWN_GAPS = [
        // Section.php and NodeType::SECTION exist but `new Section(` appears
        // nowhere: the <section> wrapper is generated while rendering, so no
        // section node is ever parsed. This one fails in carve-js too.
        'section' => 'never instantiated; the wrapper is a render-time construct',
    ];

    /**
     * Types whose sample needs an extension registered to produce the node.
     *
     * @var list<string>
     */
    private const NEEDS_CITATIONS = ['citation_group'];

    /**
     * @return array<string, array{0: string, 1: string, 2: bool}>
     */
    public static function vocabularyProvider(): array
    {
        $block = true;
        $inline = false;

        $cases = [
            'paragraph' => ['just a paragraph', $block],
            'heading' => ["# Title\n", $block],
            'code_block' => ["```php\necho 1;\n```\n", $block],
            'block_quote' => ["> quoted\n", $block],
            'list' => ["- one\n- two\n", $block],
            'list_item' => ["- one\n- two\n", $block],
            'table' => ["| a | b |\n|---|---|\n| 1 | 2 |\n", $block],
            'table_row' => ["| a | b |\n|---|---|\n| 1 | 2 |\n", $block],
            'table_cell' => ["| a | b |\n|---|---|\n| 1 | 2 |\n", $block],
            'thematic_break' => ["---\n", $block],
            'div' => [":::\nbody\n:::\n", $block],
            'admonition' => ["::: note\nbody\n:::\n", $block],
            'raw_block' => ["```=html\n<b>x</b>\n```\n", $block],
            'footnote' => ["ref[^a]\n\n[^a]: note\n", $block],
            'definition_list' => [":: term\n:  definition\n", $block],
            'definition_term' => [":: term\n:  definition\n", $block],
            'definition_description' => [":: term\n:  definition\n", $block],
            'section' => ["# Title\n\nbody\n", $block],
            'line_block' => ["::: |\na\nb\n:::\n", $block],
            'comment' => ["%% a comment\n", $block],
            'figure' => ["![alt](/i.png)\n^ caption\n", $block],
            'caption' => ["![alt](/i.png)\n^ caption\n", $block],

            'text' => ['plain words', $inline],
            'emphasis' => ['a /em/ b', $inline],
            'strong' => ['a *strong* b', $inline],
            'underline' => ['a _u_ b', $inline],
            'strike' => ['a ~s~ b', $inline],
            'inline_extension' => ['a :index[term] b', $inline],
            'mention' => ['hi @someone there', $inline],
            'code' => 'a `code` b',
            'link' => ['a [l](/u) b', $inline],
            'autolink' => ['a <https://e.com> b', $inline],
            'image' => ['a ![alt](/i.png) b', $inline],
            'soft_break' => ["line one\nline two", $inline],
            'hard_break' => ["line one\\\nline two", $inline],
            'raw_inline' => ['a `<b>x</b>`{=html} b', $inline],
            'escaped_text' => 'a \\* b',
            'footnote_ref' => ["ref[^a]\n\n[^a]: note\n", $inline],
            'inline_footnote' => ['a ^[inline note] b', $inline],
            'heading_ref' => ["# Target\n\nsee </#Target>\n", $inline],
            'citation_group' => ["[@key]\n\n[@key]: entry\n", $inline],
            'caption_number' => ["![alt](/i.png)\n^ Figure #: cap\n", $inline],
            'span' => ['a [text]{.cls} b', $inline],
            'superscript' => ['a x{^2^} b', $inline],
            'subscript' => ['a x{,2,} b', $inline],
            'highlight' => ['a {=mark=} b', $inline],
            'insert' => ['a {+ins+} b', $inline],
            'delete' => ['a {-del-} b', $inline],
            'substitution' => ['a {~old~>new~} b', $inline],
            'symbol' => ['a :smile: b', $inline],
            'math' => 'a $`x^2` b',
            'abbreviation' => ["*[HTML]: HyperText\n\nHTML rocks\n", $inline],
        ];

        $out = [];
        foreach ($cases as $type => $case) {
            // A few entries above are written as a bare string for readability.
            [$source, $isBlock] = is_array($case) ? $case : [$case, false];
            $out[$type] = [$type, $source, $isBlock];
        }

        return $out;
    }

    private function converter(string $type, ?Profile $profile = null): CarveConverter
    {
        $converter = new CarveConverter(profile: $profile);

        if (in_array($type, self::NEEDS_CITATIONS, true)) {
            $converter->addExtension(new CitationsExtension());
        }

        return $converter;
    }

    /**
     * @return array<string, bool>
     */
    private function typesIn(string $type, string $source): array
    {
        $found = [];
        $walk = function (Node $node) use (&$walk, &$found): void {
            $found[Profile::canonicalTypeOf($node)] = true;
            foreach ($node->getChildren() as $child) {
                $walk($child);
            }
        };
        $walk($this->converter($type)->parse($source));

        return $found;
    }

    #[DataProvider('vocabularyProvider')]
    public function testTheSampleActuallyProducesTheType(string $type, string $source, bool $isBlock): void
    {
        $this->assertNotSame('', $source);
        $produced = isset($this->typesIn($type, $source)[$type]);

        if (isset(self::KNOWN_GAPS[$type])) {
            $this->assertFalse(
                $produced,
                "'{$type}' is listed as a known gap ({$this->gapReason($type)}) but the parser now produces it. "
                . 'Remove it from KNOWN_GAPS - the ratchet only shrinks.',
            );

            return;
        }

        $this->assertTrue(
            $produced,
            "The sample for '{$type}' does not produce that node, so this test proves nothing about it. "
            . 'Fix the sample rather than the assertion.',
        );
    }

    #[DataProvider('vocabularyProvider')]
    public function testDenyingTheTypeReportsAViolation(string $type, string $source, bool $isBlock): void
    {
        $profile = Profile::full()->onDisallowed(Profile::ACTION_TO_TEXT);
        $profile = $isBlock ? $profile->denyBlock([$type]) : $profile->denyInline([$type]);

        $converter = $this->converter($type, $profile);
        $converter->convert($source);

        $reported = false;
        foreach ($converter->getProfileViolations() as $violation) {
            if ($violation->nodeType === $type) {
                $reported = true;
            }
        }

        if (isset(self::KNOWN_GAPS[$type])) {
            $this->assertFalse(
                $reported,
                "'{$type}' is a known gap ({$this->gapReason($type)}) but denying it now reports a violation. "
                . 'Remove it from KNOWN_GAPS.',
            );

            return;
        }

        $this->assertTrue(
            $reported,
            "Denying '{$type}' reported no violation, so a host naming it in a profile is silently ignored. "
            . 'Either the type is not really deniable - in which case it does not belong in the normative '
            . 'vocabulary - or the filter cannot see it.',
        );
    }

    public function testTheKnownGapListDoesNotGrow(): void
    {
        // Pinned so a new gap cannot be waved through by appending to the list.
        $this->assertCount(1, self::KNOWN_GAPS);
        $this->assertSame(['section'], array_keys(self::KNOWN_GAPS));
    }

    private function gapReason(string $type): string
    {
        return self::KNOWN_GAPS[$type] ?? '';
    }
}

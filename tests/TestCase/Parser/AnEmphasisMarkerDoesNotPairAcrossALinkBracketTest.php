<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * PART 8 ranks a link at 5 and an emphasis marker at 7, so a bracket run resolves
 * before a marker is scanned and a delimiter inside the run is label text by then.
 *
 * The run is opaque whatever it turns out to be - an inline link, a reference that
 * resolves or does not, an image, a note reference, an attributed span, or a run
 * that stays literal. All of those are the same bracket run at the moment the
 * marker asks. An UNBALANCED `[` opens no run, so a delimiter behind it still
 * closes (markup-carve/carve#2577, corpus category 522).
 *
 * Only the DESTINATION was opaque here, under PART 9 §9 E2a. Every expectation
 * below was measured against the oracle at carve `e778d33a`, over 39 shapes of
 * which 22 parted before this and one still does, named at the bottom.
 */
class AnEmphasisMarkerDoesNotPairAcrossALinkBracketTest extends TestCase
{
    /**
     * The three documents of corpus category 522, which the spec pin does not carry.
     *
     * @return array<string, array{string, string}>
     */
    public static function corpusDocuments(): array
    {
        return [
            'a marker beside the bracket' => ["/[a/](/u)\n", "<p>/<a href=\"/u\">a/</a></p>\n"],
            'the bare pair control' => ["/a/\n", "<p><em>a</em></p>\n"],
            'the around-the-link control' => ["/[a](/u)/\n", "<p><em><a href=\"/u\">a</a></em></p>\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function everyMarker(): array
    {
        return [
            'emphasis' => ["/[a/](/u)\n", "<p>/<a href=\"/u\">a/</a></p>\n"],
            'strong' => ["*[a*](/u)\n", "<p>*<a href=\"/u\">a*</a></p>\n"],
            'underline' => ["_[a_](/u)\n", "<p>_<a href=\"/u\">a_</a></p>\n"],
            'strike' => ["~[a~](/u)\n", "<p>~<a href=\"/u\">a~</a></p>\n"],
            'highlight' => ["=[a=](/u)\n", "<p>=<a href=\"/u\">a=</a></p>\n"],
        ];
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function everyRunKind(): array
    {
        return [
            'an inline link' => ["/[a/](/u)\n", "<p>/<a href=\"/u\">a/</a></p>\n"],
            'an inline link with a title' => [
                "/[a/](/u \"t\")\n",
                "<p>/<a href=\"/u\" title=\"t\">a/</a></p>\n",
            ],
            'a reference that resolves' => [
                "/[a/][r]\n\n[r]: /u\n",
                "<p>/<a href=\"/u\">a/</a></p>\n",
            ],
            'a reference that does not resolve' => ["/[a/][r]\n", "<p>/[a/][r]</p>\n"],
            'a collapsed reference' => [
                "/[a/][]\n\n[a/]: /u\n",
                "<p>/<a href=\"/u\">a/</a></p>\n",
            ],
            'an image' => ["/![a/](/i)\n", "<p>/<img src=\"/i\" alt=\"a/\"></p>\n"],
            'a note reference' => ["/[^a/]\n", "<p>/[^a/]</p>\n"],
            'an attributed span' => ["/[a/]{.c}\n", "<p>/<span class=\"c\">a/</span></p>\n"],
            'a run that stays literal' => ["/[a/] x\n", "<p>/[a/] x</p>\n"],
            'a nested run inside the label' => [
                "/[a [b/] c](/u)\n",
                "<p>/<a href=\"/u\">a [b/] c</a></p>\n",
            ],
            'a code span inside the label' => [
                "/[`a/`](/u)\n",
                "<p>/<a href=\"/u\"><code>a/</code></a></p>\n",
            ],
            'an escaped marker inside the label' => [
                "/[a\\/](/u)\n",
                "<p>/<a href=\"/u\">a/</a></p>\n",
            ],
            'two runs, a marker in each label' => [
                "/[a/](/u) [b/](/v)\n",
                "<p>/<a href=\"/u\">a/</a> <a href=\"/v\">b/</a></p>\n",
            ],
        ];
    }

    /**
     * A run only shields a delimiter when it CLOSES. These are the rows that keep
     * the skip from swallowing the whole tail.
     *
     * @return array<string, array{string, string}>
     */
    public static function runsThatDoNotClose(): array
    {
        return [
            'an unbalanced opener' => ["/a [b/ c\n", "<p><em>a [b</em> c</p>\n"],
            'no closing bracket at all' => ["/[a/\n", "<p><em>[a</em></p>\n"],
            'an escaped opener' => ["/a \\[b/ c\n", "<p><em>a [b</em> c</p>\n"],
            'a marker inside a nested unclosed run' => ["/a [b [c/ d\n", "<p><em>a [b [c</em> d</p>\n"],
        ];
    }

    /**
     * The closer past a closed run is still reachable, which is the half the
     * around-the-link control proves and these extend.
     *
     * @return array<string, array{string, string}>
     */
    public static function closersPastARun(): array
    {
        return [
            'immediately after the run' => ["/[a/]/\n", "<p><em>[a/]</em></p>\n"],
            'after the whole link' => ["/[a/](/u) t/\n", "<p><em><a href=\"/u\">a/</a> t</em></p>\n"],
            'past two runs' => ["/a [b/] [c/] d/\n", "<p><em>a [b/] [c/] d</em></p>\n"],
            'a marker each side of a link' => ["/a[b](/u)c/\n", "<p><em>a<a href=\"/u\">b</a>c</em></p>\n"],
            'a label with a marker inside a span' => ["/a [b/c](/u) d/\n", "<p><em>a <a href=\"/u\">b/c</a> d</em></p>\n"],
            'a pair wholly inside a label' => ["[/a/](/u)\n", "<p><a href=\"/u\"><em>a</em></a></p>\n"],
            'a pair wholly inside a literal run' => ["[/a/] x\n", "<p>[<em>a</em>] x</p>\n"],
            'an empty run between the markers' => ["/a [] b/\n", "<p><em>a [] b</em></p>\n"],
            'a run with no marker in it' => ["/a [b] c/\n", "<p><em>a [b] c</em></p>\n"],
            'a run inside a code span' => ["/a `[b/]` c/\n", "<p><em>a <code>[b/]</code> c</em></p>\n"],
        ];
    }

    #[DataProvider('corpusDocuments')]
    #[DataProvider('everyMarker')]
    #[DataProvider('everyRunKind')]
    #[DataProvider('runsThatDoNotClose')]
    #[DataProvider('closersPastARun')]
    public function testTheOracleReading(string $source, string $expected): void
    {
        $this->assertSame($expected, (new CarveConverter())->convert($source));
    }

    /**
     * STILL DIVERGENT, and left that way on purpose: the mirror half, where the
     * OPENER sits inside the run and the closer outside it. The oracle isolates
     * the interior of every closed run; this engine only isolates a run that
     * becomes a node, so a literal run's interior shares the enclosing stream.
     * That is a separate defect with its own mechanism, no corpus row pins it, and
     * it read this way before the fix above as well.
     *
     * @return void
     */
    public function testTheMirrorHalfIsNotFixedHere(): void
    {
        $this->assertSame("<p>[<em>a] b</em></p>\n", (new CarveConverter())->convert("[/a] b/\n"));
    }
}

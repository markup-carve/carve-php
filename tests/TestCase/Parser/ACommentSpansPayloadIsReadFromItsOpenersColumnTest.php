<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\Comment;
use MarkupCarve\Carve\Node\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * A comment span's payload is dedented by its OPENER's column, and the closer's
 * column is not a parameter.
 *
 * `resources/grammar.ebnf` PART 9 §24 puts the base on the opener - "opaque
 * payload indentation beyond `block_base` is authored data, and a closer belongs
 * to the base established by its opener" - and §28 pairs the delimiters on
 * length alone. Two readings disagreed with that (ruled in markup-carve/carve#2503,
 * measured on the gate's `508-*` documents):
 *
 * - the authored-base pass ended a span's extent at the first line below the
 *   base, so a CLOSED span whose closer sat lower rolled back to its opener
 *   alone: the opener reached the nested parse dedented and its payload did not;
 * - the payload was then stored as the container happened to hand it over, with
 *   no dedent of its own, so an indented span at document level kept its
 *   opener's columns too.
 *
 * Both are invisible in HTML - a comment renders nothing - so the corpus passed
 * while the canonical `carve` writer re-spelled the payload two columns in and
 * disagreed with carve-js and carve-rs on three documents.
 *
 * The rows sweep the closer column per host: one payload cannot have several
 * spellings depending on where the pair was closed. The last two providers are
 * controls at document level, where no container strip is involved.
 */
class ACommentSpansPayloadIsReadFromItsOpenersColumnTest extends TestCase
{
    /**
     * @return array<string> The content of every comment node, in tree order.
     */
    private function payloads(string $source): array
    {
        $found = [];
        $walk = function (Node $node) use (&$walk, &$found): void {
            foreach ($node->getChildren() as $child) {
                if ($child instanceof Comment) {
                    $found[] = $child->getContent();
                }
                $walk($child);
            }
        };
        $walk((new CarveConverter())->parse($source));

        return $found;
    }

    /**
     * @return array<string, array{string}>
     */
    public static function descriptionBodies(): array
    {
        $rows = [];
        foreach (range(0, 6) as $column) {
            $rows['closer at column ' . $column] = [
                ":: t\n:  head\n\n     %%%\n     a\n" . str_repeat(' ', $column) . "%%%\n",
            ];
        }

        return $rows;
    }

    #[DataProvider('descriptionBodies')]
    public function testADescriptionBodyReadsThePayloadAtTheOpenersColumn(string $source): void
    {
        $this->assertSame(['a'], $this->payloads($source));
    }

    /**
     * The payload sits two columns past its opener here, which is authored data
     * the span keeps at every closer column.
     *
     * @return array<string, array{string}>
     */
    public static function descriptionBodiesWithAnOverIndentedPayload(): array
    {
        $rows = [];
        foreach (range(0, 6) as $column) {
            $rows['closer at column ' . $column] = [
                ":: t\n:  head\n\n     %%%\n       a\n" . str_repeat(' ', $column) . "%%%\n",
            ];
        }

        return $rows;
    }

    #[DataProvider('descriptionBodiesWithAnOverIndentedPayload')]
    public function testAnOverIndentedPayloadKeepsWhatItAuthoredPastTheOpener(string $source): void
    {
        $this->assertSame(['  a'], $this->payloads($source));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function itemsHoldingTwoSpans(): array
    {
        $rows = [];
        foreach (range(0, 5) as $column) {
            $rows['first closer at column ' . $column] = [
                "- head\n\n    %%%\n    a\n" . str_repeat(' ', $column)
                    . "%%%\n    %%%\n    b\n    %%%\n\n  tail\n",
            ];
        }

        return $rows;
    }

    #[DataProvider('itemsHoldingTwoSpans')]
    public function testAnItemReadsBothPayloadsAtTheirOpenersColumn(string $source): void
    {
        $this->assertSame(['a', 'b'], $this->payloads($source));
    }

    /**
     * The three documents the cross-engine writer comparison reported, with the
     * bytes carve-js and carve-rs write for them.
     *
     * @return array<string, array{string, string}>
     */
    public static function writerDocuments(): array
    {
        return [
            'a description body' => [
                ":: t\n:  head\n\n     %%%\n     a\n%%%\n",
                ":: t\n: head\n\n  %%%\n  a\n  %%%\n",
            ],
            'a description body with a tail' => [
                ":: t\n:  head\n\n     %%%\n     a\n%%%\n\n   tail\n",
                ":: t\n: head\n\n  %%%\n  a\n  %%%\n\n  tail\n",
            ],
            'an item holding two spans' => [
                "- head\n\n    %%%\n    a\n%%%\n    %%%\n    b\n    %%%\n\n  tail\n",
                "- head\n\n  %%%\n  a\n  %%%\n\n  %%%\n  b\n  %%%\n\n  tail\n",
            ],
        ];
    }

    #[DataProvider('writerDocuments')]
    public function testTheWriterSpellsThePayloadAtItsFence(string $source, string $carve): void
    {
        $this->assertSame($carve, CarveConverter::toCarve($source));
    }

    /**
     * CONTROL - document level, where no container strip stands between the
     * opener and the payload. The span still reads its payload at its own fence.
     *
     * @return array<string, array{string, array<string>}>
     */
    public static function documentLevelSpans(): array
    {
        return [
            'payload at the opener, closer below it' => ["  %%%\n  a\n%%%\n", ['a']],
            'payload at the opener, closer at it' => ["  %%%\n  a\n  %%%\n", ['a']],
            'payload past the opener, closer below it' => ["  %%%\n    a\n%%%\n", ['  a']],
            'payload past the opener, closer at it' => ["  %%%\n    a\n  %%%\n", ['  a']],
            'a flush opener keeps every column' => ["%%%\n    a\n%%%\n", ['    a']],
        ];
    }

    /**
     * @param string $source
     * @param array<string> $payloads
     */
    #[DataProvider('documentLevelSpans')]
    public function testAControlADocumentLevelSpanReadsItsOwnFence(string $source, array $payloads): void
    {
        $this->assertSame($payloads, $this->payloads($source));
    }

    /**
     * A comment folded into a term reads its payload at the fence like every
     * other host, and reads it ONCE: the term path used to dedent the stored
     * payload a second time, which ate the columns the author wrote past the
     * fence.
     */
    public function testATermFoldsACommentWithoutDedentingItTwice(): void
    {
        $this->assertSame(['    a'], $this->payloads(":: t\n  %%%\n      a\n  %%%\n:  body\n"));
    }

    /**
     * CONTROL - an opener with no closer ahead opens no block (§28): the line is
     * one `%%` line comment whose first two `%` are the marker, so its content
     * is the third and the lines below stay visible text. A fix that gave the
     * degraded form a payload extent would hide them. carve-rs reads the same
     * single `%`.
     */
    public function testAControlADegradedOpenerOwnsNoPayload(): void
    {
        $source = "- head\n\n    %%%\n    a\nb\ntail\n";
        $html = (new CarveConverter())->convert($source);

        $this->assertSame(['%'], $this->payloads($source));
        $this->assertStringContainsString('a', $html);
        $this->assertStringContainsString('b', $html);
    }
}

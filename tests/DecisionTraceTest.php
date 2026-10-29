<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\DecisionTrace;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Expected records are the spec model's `modelTrace()` (carve scripts/spec/decision-trace.mjs).
 */
final class DecisionTraceTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function documents(): iterable
    {
        yield 'lazy line past an item and a quote' => ["- a\n  > q\nlazy\n", '[{"line":1,"step":"S5","owner":"doc/item:1","rule":"CARVE-P0-024","form":"item","cell":"B-item-start"},{"line":2,"step":"S5","owner":"doc/item:1/quote:2","rule":"CARVE-P0-024","form":"quote","cell":"B-quote-start"},{"line":3,"step":"S4","owner":"doc/item:1/quote:2","rule":"CARVE-P0-006","form":"lazy","reached":"doc"}]'];
        yield 'frontmatter is INPUT' => ["---\ntitle: x\n---\ntext\n", '[{"line":1,"step":"INPUT","owner":"doc","rule":null,"form":"frontmatter"},{"line":2,"step":"INPUT","owner":"doc","rule":null,"form":"frontmatter"},{"line":3,"step":"INPUT","owner":"doc","rule":null,"form":"frontmatter"},{"line":4,"step":"S5","owner":"doc","rule":"CARVE-P0-024","form":"text"}]'];
        yield 'code payload and closer' => ["```\ncode\n```\n", '[{"line":1,"step":"S5","owner":"doc/code:1","rule":"CARVE-P0-024","form":"code","cell":"B-code-start"},{"line":2,"step":"S1","owner":"doc/code:1","rule":"CARVE-P0-005","form":"payload","cell":"B-code-keep"},{"line":3,"step":"S1","owner":"doc/code:1","rule":"CARVE-P0-005","form":"closer","cell":"B-code-end"}]'];
        yield 'comment fence in an item' => ["- a\n\n  %%%\n  hidden\n  %%%\n", '[{"line":1,"step":"S5","owner":"doc/item:1","rule":"CARVE-P0-024","form":"item","cell":"B-item-start"},{"line":2,"step":"S5","owner":"doc/item:1","rule":"CARVE-P0-003","form":"blank"},{"line":3,"step":"S5","owner":"doc/item:1/comment:3","rule":"CARVE-P0-024","form":"comment","cell":"B-comment-start"},{"line":4,"step":"S1","owner":"doc/item:1/comment:3","rule":"CARVE-P0-005","form":"payload","cell":"B-comment-keep"},{"line":5,"step":"S1","owner":"doc/item:1/comment:3","rule":"CARVE-P0-005","form":"closer","cell":"B-comment-end"}]'];
        yield 'div closer closes its div' => ["::: note\nin\n:::\n", '[{"line":1,"step":"S5","owner":"doc/div:1","rule":"CARVE-P0-024","form":"div","cell":"B-div-start"},{"line":2,"step":"S5","owner":"doc/div:1","rule":"CARVE-P0-024","form":"text"},{"line":3,"step":"S5","owner":"doc/div:1","rule":"CARVE-P0-024","form":"closer","cell":"B-div-end"}]'];
        yield 'line block is a div fence' => ["::: |\nline\n:::\n", '[{"line":1,"step":"S5","owner":"doc/div:1","rule":"CARVE-P0-024","form":"div","cell":"B-div-start"},{"line":2,"step":"S1","owner":"doc/div:1","rule":"CARVE-P0-005","form":"payload","cell":"B-div-keep"},{"line":3,"step":"S1","owner":"doc/div:1","rule":"CARVE-P0-005","form":"closer","cell":"B-div-end"}]'];
        yield 'accepted plus and its follower' => ["- a\n+\n# h\n", '[{"line":1,"step":"S5","owner":"doc/item:1","rule":"CARVE-P0-024","form":"item","cell":"B-item-start"},{"line":2,"step":"S2","owner":"doc/item:1","rule":"CARVE-P9-067","form":"plus","cell":"B-plus-start"},{"line":3,"step":"S5","owner":"doc","rule":"CARVE-P0-011","form":"leaf","closed":1,"reached":"doc","plus":2}]'];
        yield 'a line closing two containers' => ["- > q\n\n# h\n", '[{"line":1,"step":"S5","owner":"doc/item:1/quote:1","rule":"CARVE-P0-024","form":"item","cell":"B-item-start"},{"line":2,"step":"S5","owner":"doc/item:1","rule":"CARVE-P0-003","form":"blank","closed":1,"reached":"doc/item:1"},{"line":3,"step":"S5","owner":"doc","rule":"CARVE-P0-011","form":"leaf","closed":1,"reached":"doc"}]'];
        yield 'footnote, term and description' => ["[^n]: note\n\n:: t\n: d\n", '[{"line":1,"step":"S5","owner":"doc/footnote:1","rule":"CARVE-P0-024","form":"footnote","cell":"B-footnote-start"},{"line":2,"step":"S5","owner":"doc/footnote:1","rule":"CARVE-P0-003","form":"blank"},{"line":3,"step":"S5","owner":"doc","rule":"CARVE-P0-024","form":"term","cell":"B-term-start","closed":1,"reached":"doc"},{"line":4,"step":"S5","owner":"doc/dd:4","rule":"CARVE-P0-024","form":"dd","cell":"B-dd-start"}]'];
    }

    #[DataProvider('documents')]
    public function testRecordsMatchTheSpecModel(string $source, string $expected): void
    {
        $this->assertSame(json_decode($expected, true, flags: JSON_THROW_ON_ERROR), DecisionTrace::records($source));
    }

    public function testJsonLinesStartWithTheHeader(): void
    {
        $lines = explode("\n", DecisionTrace::jsonLines("a\n"));

        $this->assertSame('{"trace":"carve-decision-trace","version":1,"engine":"carve-php"}', $lines[0]);
        $this->assertSame('{"line":1,"step":"S5","owner":"doc","rule":"CARVE-P0-024","form":"text"}', $lines[1]);
        $this->assertSame('', $lines[2]);
    }

    public function testCliPrintsTheTrace(): void
    {
        $process = proc_open(
            [PHP_BINARY, __DIR__ . '/../bin/carve', 'trace', '-'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);
        fwrite($pipes[0], "```\ncode\n```\n");
        fclose($pipes[0]);
        $out = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[2]);

        $this->assertSame(0, proc_close($process), (string)$err);
        $this->assertSame(DecisionTrace::jsonLines("```\ncode\n```\n"), $out);
    }

    public function testTracingLeavesTheParseUnchanged(): void
    {
        $source = "- a\n  > q\nlazy\n";
        $converter = new CarveConverter();
        $before = $converter->convert($source);
        DecisionTrace::records($source);

        $this->assertSame($before, (new CarveConverter())->convert($source));
    }
}

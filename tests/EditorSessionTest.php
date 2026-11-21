<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test;

use InvalidArgumentException;
use MarkupCarve\Carve\Carve;
use PHPUnit\Framework\TestCase;

final class EditorSessionTest extends TestCase
{
    public function testSyntaxTokensUseByteRanges(): void
    {
        $source = "😀\n\n{#hero}\n### Head\n\n[label](https://example.com)\n";
        $nodes = Carve::createEditorSession($source)->snapshot()['nodes'];
        $tokens = [];
        foreach ($nodes as $node) {
            foreach ($node['tokens'] as $token) {
                $tokens[] = [$token['role'], substr($source, $token['startByte'], $token['endByte'] - $token['startByte'])];
            }
        }
        self::assertContains(['attribute', '{#hero}'], $tokens);
        self::assertContains(['block-marker', '### '], $tokens);
        self::assertContains(['destination', 'https://example.com'], $tokens);
    }

    public function testAttributeTokensAcrossLineEndings(): void
    {
        foreach (["\n", "\r\n"] as $ending) {
            $source = '{.a}' . $ending . '# Head' . $ending;
            $nodes = Carve::createEditorSession($source)->snapshot()['nodes'];
            $tokens = [];
            foreach ($nodes as $node) {
                $tokens = array_merge($tokens, $node['tokens']);
            }
            self::assertContains(['role' => 'attribute', 'startByte' => 0, 'endByte' => 4], $tokens);
        }
    }

    public function testUtf8ByteChangesAndStructuralFallbackMatchFreshParse(): void
    {
        $session = Carve::createEditorSession('😀 one');
        $before = $session->snapshot();
        $after = $session->update([['from' => 5, 'to' => 8, 'insert' => 'two']]);
        self::assertSame('😀 one', $before['source']);
        self::assertSame('😀 two', $after['source']);
        self::assertSame(1, $after['revision']);
        self::assertFalse($after['reusedPreviousTree']);
        self::assertSame(json_decode(Carve::toJson('😀 two'), true), $after['ast']);
        self::assertContains('/children/0/children/0', $after['changedPaths']);
    }

    public function testBatchedChangesAndInvalidChanges(): void
    {
        $session = Carve::createEditorSession("one\n\ntwo\n\nthree");
        $after = $session->update([
            ['from' => 0, 'to' => 0, 'insert' => 'A'],
            ['from' => 0, 'to' => 3, 'insert' => 'ONE'],
            ['from' => 10, 'to' => 15, 'insert' => 'four'],
        ]);
        self::assertSame(json_decode(Carve::toJson($after['source']), true), $after['ast']);
        $before = $session->snapshot();
        foreach ([[['from' => 2, 'to' => 1, 'insert' => '']], [['from' => 0, 'to' => 0, 'insert' => "\xff"]]] as $changes) {
            try {
                $session->update($changes);
                self::fail('Invalid changes were accepted.');
            } catch (InvalidArgumentException) {
                self::assertSame($before, $session->snapshot());
            }
        }
    }

    public function testPlainParagraphEditReusesOtherParagraphs(): void
    {
        self::assertTrue(Carve::supportsIncrementalParagraphReuse());
        $source = "first\n\nMiddle\n\nlast\n";
        $session = Carve::createEditorSession($source);
        $after = $session->update([['from' => 7, 'to' => 13, 'insert' => 'Edited']]);
        self::assertTrue($after['reusedPreviousTree']);
        self::assertLessThan(strlen($after['source']), $after['parsedSourceBytes']);
        self::assertSame(json_decode(Carve::toJson($after['source']), true), $after['ast']);
        self::assertSame(Carve::parseWithLayout($after['source'])->layout, $after['layout']);
    }

    public function testUntouchedNodesKeepIdentityWhenMoved(): void
    {
        $session = Carve::createEditorSession("first\n\nsecond\n");
        $before = array_column($session->snapshot()['identity']['nodes'], 'id', 'path');
        $after = $session->update([['from' => 0, 'to' => 0, 'insert' => "intro\n\n"]]);
        $ids = array_column($after['identity']['nodes'], 'id', 'path');
        self::assertSame($before['/children/1'], $ids['/children/2']);
        self::assertSame($before[''], $ids['']);
    }

    public function testDeletingAnIdenticalParagraphKeepsTheSurvivorsIdentity(): void
    {
        $session = Carve::createEditorSession("same\n\nsame\n");
        $before = array_column($session->snapshot()['identity']['nodes'], 'id', 'path');
        $after = $session->update([['from' => 0, 'to' => 6, 'insert' => '']]);
        $ids = array_column($after['identity']['nodes'], 'id', 'path');
        self::assertSame($before['/children/1'], $ids['/children/0']);
        self::assertSame($before['/children/1/children/0'], $ids['/children/0/children/0']);
    }

    public function testRepeatedPlainEditsStayFresh(): void
    {
        $session = Carve::createEditorSession("first\n\ntext\n\nlast");
        for ($index = 0; $index < 20; $index++) {
            $source = $session->snapshot()['source'];
            $end = strpos($source, "\n", 7);
            self::assertNotFalse($end);
            $after = $session->update([['from' => 7, 'to' => $end, 'insert' => 'paragraph ' . $index]]);
            self::assertTrue($after['reusedPreviousTree']);
            self::assertSame(json_decode(Carve::toJson($after['source']), true), $after['ast']);
            self::assertSame(Carve::parseWithLayout($after['source'])->layout, $after['layout']);
        }
    }

    public function testClauseCasesMatchFreshParseAtEveryByteOffset(): void
    {
        foreach (ClauseCasesTest::cases() as [$row]) {
            self::assertIsString($row['input'] ?? null);
            $source = $row['input'];
            $length = strlen($source);
            for ($at = 0; $at <= $length; $at++) {
                if ($at < $length && (ord($source[$at]) & 0xc0) === 0x80) {
                    continue;
                }
                $session = Carve::createEditorSession($source);
                $inserted = $session->update([['from' => $at, 'to' => $at, 'insert' => 'x']]);
                self::assertSame(json_decode(Carve::toJson($inserted['source']), true), $inserted['ast']);
                if ($at === $length) {
                    continue;
                }
                $end = $at + 1;
                while ($end < $length && (ord($source[$end]) & 0xc0) === 0x80) {
                    $end++;
                }
                $session = Carve::createEditorSession($source);
                $deleted = $session->update([['from' => $at, 'to' => $end, 'insert' => '']]);
                self::assertSame(json_decode(Carve::toJson($deleted['source']), true), $deleted['ast']);
            }
        }
    }
}

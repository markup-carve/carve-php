<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use InvalidArgumentException;
use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\TestCase;

final class EditorSessionTest extends TestCase
{
    public function testSyntaxTokensUseByteRanges(): void
    {
        $source = "😀\n\n{#hero}\n### Head\n\n[label](https://example.com)\n";
        $nodes = (new CarveConverter())->createEditorSession($source)->snapshot()['nodes'];
        $tokens = [];
        foreach ($nodes as $node) {
            foreach ($node['tokens'] as $token) {
                $tokens[] = [$token['role'], substr($source, $token['startByte'], $token['endByte'] - $token['startByte'])];
            }
        }
        $this->assertContains(['attribute', '{#hero}'], $tokens);
        $this->assertContains(['block-marker', '### '], $tokens);
        $this->assertContains(['destination', 'https://example.com'], $tokens);
    }

    public function testEditsUseUtf8BytesAndMatchFreshParse(): void
    {
        $converter = new CarveConverter();
        $session = $converter->createEditorSession('😀 one');
        $before = $session->snapshot();
        $update = $session->update([['from' => 5, 'to' => 8, 'insert' => 'two']]);
        $this->assertSame('😀 two', $update['source']);
        $this->assertSame(1, $update['revision']);
        $this->assertSame('😀 one', $before['source']);
        $this->assertSame($converter->parseWithSourceLayout('😀 two')['ast'], $update['ast']);
        $this->assertContains('/children/0/children/0', $update['changedPaths']);
    }

    public function testInvalidEditsLeaveTheSnapshotUnchanged(): void
    {
        $session = (new CarveConverter())->createEditorSession('a😀b');
        $before = $session->snapshot();
        foreach (
            [
                [['from' => 2, 'to' => 2, 'insert' => 'x']],
                [['from' => 0, 'to' => 5, 'insert' => ''], ['from' => 1, 'to' => 1, 'insert' => '']],
                [['from' => 0, 'to' => 0, 'insert' => "\xff"]],
            ] as $changes
        ) {
            try {
                $session->update($changes);
                $this->fail('Invalid changes were accepted.');
            } catch (InvalidArgumentException) {
                $this->assertSame($before, $session->snapshot());
            }
        }
    }

    public function testUntouchedNodesKeepIdentityAfterMoving(): void
    {
        $session = (new CarveConverter())->createEditorSession("first\n\nsecond\n");
        $old = array_column($session->snapshot()['identity']['nodes'], 'id', 'path');
        $update = $session->update([['from' => 0, 'to' => 0, 'insert' => "intro\n\n"]]);
        $new = array_column($update['identity']['nodes'], 'id', 'path');
        $this->assertSame($old['/children/1'], $new['/children/2']);
        $this->assertSame($old[''], $new['']);
    }

    public function testReferenceChangesReportTheUneditedLink(): void
    {
        $source = "[label][r]\n\n[r]: /one\n";
        $session = (new CarveConverter())->createEditorSession($source);
        $start = strpos($source, '/one');
        $update = $session->update([['from' => $start, 'to' => $start + 4, 'insert' => '/two']]);
        $this->assertContains('/children/0/children/0', $update['changedPaths']);
    }

    public function testLocalUnicodeEditsReuseOtherParagraphs(): void
    {
        $converter = new CarveConverter();
        $session = $converter->createEditorSession("één\n\nMitte\n\n終わり\n");
        $update = $session->update([['from' => 7, 'to' => 12, 'insert' => '日本語']]);
        self::assertTrue($update['reusedPreviousTree']);
        self::assertSame(9, $update['parsedSourceBytes']);
        $fresh = (new CarveConverter())->parseWithSourceLayout($update['source']);
        self::assertSame($fresh['ast'], $update['ast']);
        self::assertSame($fresh['layout'], $update['layout']);
        $copy = $session->snapshot();
        $copy['ast']['children'] = [];
        self::assertNotEmpty($session->snapshot()['ast']['children']);
    }

    public function testRepeatedEditsAndStructuralFallbacksMatchFreshParsing(): void
    {
        $session = (new CarveConverter())->createEditorSession("first\n\ntext\n\nlast");
        for ($index = 0; $index < 100; $index++) {
            $source = $session->snapshot()['source'];
            $end = strpos($source, "\n", 7);
            self::assertNotFalse($end);
            $update = $session->update([['from' => 7, 'to' => $end, 'insert' => "paragraph $index é"]]);
            $fresh = (new CarveConverter())->parseWithSourceLayout($update['source']);
            self::assertSame($fresh['ast'], $update['ast']);
            self::assertSame($fresh['layout'], $update['layout']);
            self::assertTrue($update['reusedPreviousTree']);
            self::assertLessThan(strlen($update['source']), $update['parsedSourceBytes']);
        }
        foreach (['# Heading', '[ref]: /url', "first\n\nsecond", '1. item', ''] as $insert) {
            $session = (new CarveConverter())->createEditorSession("before\n\ntext\n\nafter");
            $update = $session->update([['from' => 8, 'to' => 12, 'insert' => $insert]]);
            self::assertFalse($update['reusedPreviousTree']);
            self::assertSame((new CarveConverter())->parseWithSourceLayout($update['source'])['ast'], $update['ast']);
        }
    }

    public function testAccessingCustomParserDisablesReuse(): void
    {
        $converter = new CarveConverter();
        $converter->getParser();
        $session = $converter->createEditorSession("first\n\ntext\n\nlast");
        $update = $session->update([['from' => 7, 'to' => 11, 'insert' => 'edited']]);
        self::assertFalse($update['reusedPreviousTree']);
        self::assertSame(strlen($update['source']), $update['parsedSourceBytes']);
    }
}

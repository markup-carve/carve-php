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
}

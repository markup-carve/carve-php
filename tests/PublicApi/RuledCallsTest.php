<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\PublicApi;

use DateTime;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Json\PatchError;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreePatch;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\OptionError;
use MarkupCarve\Carve\Test\Support\Corpus;
use PHPUnit\Framework\TestCase;

final class RuledCallsTest extends TestCase
{
    public function testAPatchBetweenTwoExampleTreesTurnsTheFirstIntoTheSecond(): void
    {
        $trees = [];
        foreach (array_slice(Corpus::examples(), 0, 80) as $example) {
            if (is_string($example['input'] ?? null)) {
                $trees[] = Carve::parse($example['input']);
            }
        }
        self::assertGreaterThan(40, count($trees));
        $count = count($trees);
        for ($at = 1; $at < $count; $at++) {
            [$a, $b] = [$trees[$at - 1], $trees[$at]];
            $patched = Carve::applyPatch($a, Carve::diffTree($a, $b), ['fingerprint' => Carve::treeFingerprint($a)]);
            self::assertSame([], Carve::diffTree($patched, $b));
        }
    }

    public function testEqualTreesGiveTheEmptyPatchAndPointersOtherwise(): void
    {
        self::assertSame([], Carve::diffTree(Carve::parse('a'), Carve::parse('a')));
        self::assertSame(
            [['op' => 'replace', 'path' => '/children/0/children/0/value', 'value' => 'a c']],
            Carve::diffTree(self::bare('a b'), self::bare('a c')),
        );
    }

    public function testPointersEscapeTildeAndSlash(): void
    {
        $patch = TreePatch::diff('{"a~b/c":1,"x":[1,2]}', '{"a~b/c":2,"x":[1,2,3]}');
        self::assertSame([
            ['op' => 'replace', 'path' => '/a~0b~1c', 'value' => 2],
            ['op' => 'add', 'path' => '/x/2', 'value' => 3],
        ], $patch);
        $seen = null;
        $read = static function (string $json) use (&$seen): Node {
            $seen = $json;

            return Carve::parse('a');
        };
        TreePatch::apply('{"a~b/c":1}', [['op' => 'replace', 'path' => '/a~0b~1c', 'value' => 2]], null, $read);
        self::assertSame('{"a~b/c":2}', $seen);
    }

    public function testRefusesATreeWhoseFingerprintDiffers(): void
    {
        $this->expectException(PatchError::class);
        Carve::applyPatch(Carve::parse('a'), [], ['fingerprint' => Carve::treeFingerprint(Carve::parse('b'))]);
    }

    public function testRefusesAResultThatDoesNotDecodeNamingItsPath(): void
    {
        try {
            Carve::applyPatch(self::bare('a'), [['op' => 'replace', 'path' => '/children/0/type', 'value' => 'nope']]);
            self::fail('accepted');
        } catch (PatchError $e) {
            self::assertSame('/children/0', $e->pointer);
        }
    }

    public function testRefusesAFailingOperationAtItsIndexAndLeavesTheTree(): void
    {
        $tree = self::bare('a');
        $before = Carve::encodeTree($tree);
        $cases = [
            [[['op' => 'remove', 'path' => '/children/3']], '/0/path'],
            [[['op' => 'test', 'path' => '/type', 'value' => 'x']], '/0/value'],
            [[['op' => 'add', 'path' => '/children/-', 'value' => ['type' => 'thematic_break']], ['op' => 'jump', 'path' => '']], '/1/op'],
            [[['op' => 'replace', 'path' => 'children']], '/0/path'],
            [['x'], '/0'],
            [[['op' => 'remove', 'path' => '/children/0~2']], '/0/path'],
        ];
        foreach ($cases as [$patch, $pointer]) {
            try {
                Carve::applyPatch($tree, $patch);
                self::fail('accepted');
            } catch (PatchError $e) {
                self::assertSame($pointer, $e->pointer);
            }
        }
        self::assertSame($before, Carve::encodeTree($tree));
    }

    public function testTestsWithoutRegardToMemberOrderAndRefusesNonJson(): void
    {
        $tree = self::bare('a');
        $reversed = array_reverse((array)json_decode(Carve::encodeTree($tree), true), true);
        $tested = Carve::applyPatch($tree, [['op' => 'test', 'path' => '', 'value' => $reversed]]);
        self::assertSame(Carve::encodeTree($tree), Carve::encodeTree($tested));
        foreach ([NAN, INF, new DateTime('@0'), static fn () => 1] as $value) {
            try {
                Carve::applyPatch($tree, [['op' => 'add', 'path' => '/children/-', 'value' => $value]]);
                self::fail('accepted');
            } catch (PatchError $e) {
                self::assertSame('/0/value', $e->pointer);
            }
        }
        try {
            Carve::applyPatch($tree, [['op' => 'add', 'path' => '/children/-']]);
            self::fail('accepted');
        } catch (PatchError $e) {
            self::assertSame('/0/value', $e->pointer);
        }
    }

    public function testAppliesMoveAndCopy(): void
    {
        $tree = self::bare("a\n\nb\n");
        $moved = Carve::applyPatch($tree, [['op' => 'move', 'from' => '/children/0', 'path' => '/children/1']]);
        self::assertSame(
            json_encode(json_decode(TreeEncoder::json(self::bare("b\n\na\n"), null), true)['children']),
            json_encode(json_decode(TreeEncoder::json($moved, null), true)['children']),
        );
        foreach (['', '/children/0'] as $same) {
            $unchanged = Carve::applyPatch($tree, [['op' => 'move', 'from' => $same, 'path' => $same]]);
            self::assertSame(Carve::encodeTree($tree), Carve::encodeTree($unchanged));
        }
        $copied = Carve::applyPatch($tree, [['op' => 'copy', 'from' => '/children/0', 'path' => '/children/-']]);
        self::assertCount(3, $copied->children ?? []);
        $this->expectException(PatchError::class);
        Carve::applyPatch($tree, [['op' => 'move', 'from' => '/children', 'path' => '/children/0']]);
    }

    public function testFingerprintsAreSha256OfTheBytes(): void
    {
        self::assertSame('sha256:ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad', Carve::sourceFingerprint('abc'));
        $tree = Carve::parse('a');
        self::assertSame(Carve::sourceFingerprint(Carve::encodeTree($tree)), Carve::treeFingerprint($tree));
    }

    public function testSpecVersionAndNodeTypes(): void
    {
        $pinned = explode(' ', trim((string)file_get_contents(dirname(__DIR__, 2) . '/spec/PINNED')));
        self::assertSame(['version' => '0.2', 'commit' => $pinned[1]], Carve::SPEC_VERSION);
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', Carve::SPEC_VERSION['commit']);
        self::assertContains('paragraph', Carve::NODE_TYPES['block']);
        self::assertContains('text', Carve::NODE_TYPES['inline']);
        self::assertNotContains('text', Carve::NODE_TYPES['block']);
        $version = Carve::version();
        self::assertTrue($version === null || $version !== '');
    }

    public function testHeadingIdIsTheIdTheOptionGenerates(): void
    {
        foreach (['keep-case', 'lower', 'ascii', 'ascii-lower', 'ascii-strict', 'ascii-strict-lower'] as $mode) {
            foreach (['Hello World', 'Ünïcode Straße', '2 Ways', '-', 'a/b: c?', 'Héllo Wörld'] as $text) {
                $html = Carve::toHtml('= ' . $text . "\n", ['headingIds' => $mode]);
                $id = Carve::headingId($text, ['headingIds' => $mode]);
                self::assertStringContainsString('id="' . $id . '"', $html, $mode . ' ' . $text);
            }
        }
        self::assertSame('hello-world', Carve::headingId('Héllo Wörld', ['headingIds' => 'ascii-lower']));
    }

    public function testHeadingIdAddsNoSuffixAndIsNullUnderOff(): void
    {
        self::assertSame('A', Carve::headingId('A'));
        self::assertStringContainsString('id="A-2"', Carve::toHtml("= A\n\n= A\n"));
        self::assertNull(Carve::headingId('A', ['headingIds' => 'off']));
        $this->expectException(OptionError::class);
        Carve::headingId('A', ['headingIds' => 'upper']);
    }

    public function testHeadingIdTakesNoOtherOption(): void
    {
        $this->expectException(OptionError::class);
        Carve::headingId('A', ['softBreak' => 'br']);
    }

    private static function bare(string $source): Node
    {
        return Carve::decodeTree(TreeEncoder::json(Carve::parse($source), null));
    }
}

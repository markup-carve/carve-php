<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\ProseMirror;

use MarkupCarve\Carve\Json\TreeEquality;
use MarkupCarve\Carve\ProseMirror\Kit;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The wire shape of the carve-grammars Tiptap kit: `tests/fixtures/prosemirror/kit-wire.json` holds what `tree.js`
 * produces at the commit in `_provenance`, one input per ProseMirror node and mark type the conformance inputs reach.
 */
final class KitWireTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private static function fixture(): array
    {
        $data = Kit::decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/prosemirror/kit-wire.json'));
        assert(is_array($data));

        return $data;
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function loaded(): array
    {
        $out = [];
        foreach (self::fixture()['loaded'] as $case) {
            $out[$case['id']] = [$case];
        }

        return $out;
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function edited(): array
    {
        $out = [];
        foreach (self::fixture()['edited'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('loaded')]
    public function testATreeLoadsAsTheKitLoadsIt(array $case): void
    {
        $this->assertSame(Kit::encode($case['pm']), (new ProseMirrorRenderer())->renderJson($case['tree']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('loaded')]
    public function testALoadedDocumentSavesAsTheKitSavesIt(array $case): void
    {
        $tree = (new ProseMirrorToCarve())->toTree($case['pm']);

        $this->assertSame(Kit::encode($case['back']), Kit::encode($tree));
        $this->assertTrue(TreeEquality::equal(self::arrays($case['tree']), self::arrays($tree)));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('edited')]
    public function testAnEditorDocumentSavesAsTheKitSavesIt(array $case): void
    {
        $this->assertSame(Kit::encode($case['tree']), Kit::encode((new ProseMirrorToCarve())->toTree($case['pm'])));
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function mapped(): array
    {
        $out = [];
        foreach (self::fixture()['mapped'] as $case) {
            $out[$case['name']] = [$case];
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('mapped')]
    public function testAMappedDocumentSavesAsTheKitSavesIt(array $case): void
    {
        $result = (new ProseMirrorToCarve(['mapping' => $case['mapping']]))->toTreeWithOrigins($case['doc']);

        $this->assertSame(Kit::encode($case['saved']), Kit::encode($result['tree']));
        $this->assertSame(Kit::encode($case['pointers']), Kit::encode($result['pointers']));
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('mapped')]
    public function testAMappedTreeLoadsAsTheKitLoadsIt(array $case): void
    {
        if (!isset($case['tree'])) {
            $this->assertArrayNotHasKey('pm', $case);

            return;
        }
        $this->assertSame(Kit::encode($case['pm']), (new ProseMirrorRenderer(['mapping' => $case['mapping']]))->renderJson($case['tree']));
    }

    public function testTheFixtureNamesTheKitCommit(): void
    {
        $this->assertMatchesRegularExpression('/^[0-9a-f]{40}$/', self::fixture()['_provenance']['commit']);
    }

    private static function arrays(mixed $value): mixed
    {
        return json_decode(Kit::encode(['v' => $value]), true)['v'];
    }
}

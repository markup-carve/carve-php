<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Test\Support\ClauseHost;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DocumentTransformTest extends TestCase
{
    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function clauseCases(): array
    {
        $path = dirname(__DIR__, 2) . '/spec/conformance/clause-cases.json';
        $cases = json_decode((string)file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        $wanted = ['CC-1221' => true, 'CC-1229' => true, 'CC-1230' => true];
        $out = [];
        foreach ($cases['cases'] ?? $cases as $case) {
            if (isset($wanted[$case['id']])) {
                $out[$case['id']] = [$case];
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('clauseCases')]
    public function testHostDocumentTransformsUseTheClauseCase(array $case): void
    {
        $transforms = ClauseHost::documentTransforms($case);
        $result = Carve::renderWithDocumentTransforms($case['tree'], $transforms);

        self::assertSame($case['expected']['html'], $result->html);
        $codes = array_map(static fn ($entry): string => $entry->code, $result->diagnostics);
        foreach ($case['expected']['diagnostics'] ?? [] as $code) {
            self::assertContains($code, $codes);
        }
    }

    /**
     * @return array<string, array{\Closure(\MarkupCarve\Carve\Node): \MarkupCarve\Carve\Node}>
     */
    public static function invalidViews(): array
    {
        return [
            'unknown node type' => [
                static function (Node $view): Node {
                    $view->children[0]->children[] = new Node('nope');

                    return $view;
                },
            ],
            'block node in inline content' => [
                static function (Node $view): Node {
                    $view->children[0]->children[] = new Node('paragraph', []);

                    return $view;
                },
            ],
            'text value not a string' => [
                static function (Node $view): Node {
                    $view->children[0]->children[0]->f['value'] = 3;

                    return $view;
                },
            ],
            'deep invalid node' => [
                static function (Node $view): Node {
                    $node = new Node('nope');
                    for ($i = 0; $i < 20; $i++) {
                        $node = new Node('emphasis', [$node]);
                    }
                    $view->children[0]->children[] = $node;

                    return $view;
                },
            ],
        ];
    }

    /**
     * C2-X-054, C2-X-055: a transform whose view is no valid tree fails, its input view stays, and the next transform runs.
     *
     * @param \Closure(\MarkupCarve\Carve\Node): \MarkupCarve\Carve\Node $invalid
     */
    #[DataProvider('invalidViews')]
    public function testTransformReturningAnInvalidTreeFails(Closure $invalid): void
    {
        $source = "Plain *text* here.\n\nSecond paragraph.\n";
        $mark = static function (Node $view): Node {
            $view->children[1]->children[0]->f['value'] = 'Marked.';

            return $view;
        };
        $transforms = [new DocumentTransform('broken', $invalid), new DocumentTransform('mark', $mark)];
        $result = Carve::renderWithDocumentTransforms($source, $transforms);

        self::assertSame(Carve::render(str_replace('Second paragraph.', 'Marked.', $source)), $result->html);
        $failed = array_values(array_filter($result->diagnostics, static fn ($d): bool => $d->code === 'extension-transform-failed'));
        self::assertCount(1, $failed);
        self::assertSame('Document transform failed: broken.', $failed[0]->message);
    }
}

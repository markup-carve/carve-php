<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Extension\Navigation;
use MarkupCarve\Carve\Json\SchemaValidator;
use MarkupCarve\Carve\Json\TreeEncoder;
use MarkupCarve\Carve\Json\TreeError;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use stdClass;
use Throwable;

/**
 * carve-php#5978: the shortcuts of the transform check give the result of the full check (C2-X-053 to C2-X-055).
 */
final class TransformCheckCostTest extends TestCase
{
    /**
     * @var string
     */
    private const DOC = "{#a}\n= A </#b>\n\nSee </#a>.\n\n{#b}\n== B\n\n- item ^[note]\n";

    public function testUnchangedViewRendersAsWithoutTransform(): void
    {
        $noop = new ExtensionDefinition('x.noop', '1', documentTransform: static fn (Node $view): Node => $view);
        $again = new ExtensionDefinition('x.again', '1', documentTransform: static fn (Node $view): Node => $view);
        $plain = Carve::renderWithDocumentTransforms(self::DOC, []);
        $result = Carve::renderWithDocumentTransforms(self::DOC, [], ['extensions' => [$noop, $again]]);

        self::assertSame($plain->html, $result->html);
        self::assertSame([], array_values(array_filter(
            $result->codes(),
            static fn (string $code): bool => $code === 'extension-transform-failed',
        )));
    }

    public function testUnchangedTransformAfterAChangeKeepsTheChange(): void
    {
        $noop = new ExtensionDefinition('x.noop', '1', documentTransform: static fn (Node $view): Node => $view);
        $numbered = Carve::render(self::DOC, ['extensions' => [Navigation::headingNumbers()]]);

        self::assertSame($numbered, Carve::render(self::DOC, ['extensions' => [Navigation::headingNumbers(), $noop]]));
        self::assertStringContainsString('<span class="section-number">1</span>', $numbered);
    }

    public function testEqualButChangedValueCountsAsChanged(): void
    {
        $doc = Carve::parse(self::DOC);
        $copy = Extensions::copy($doc);
        self::assertTrue(Extensions::same($copy, $doc));

        self::first($copy)->f['level'] = 1.0;
        self::assertFalse(Extensions::same($copy, $doc), 'a float level is not the integer level');

        $shared = Extensions::copy($doc);
        $shared->f['probe'] = new stdClass();
        $other = Extensions::copy($shared);
        self::assertFalse(Extensions::same($other, $shared), 'a shared object may have been edited in place');
    }

    /**
     * @return array<string, array{\Closure(\MarkupCarve\Carve\Node): void}>
     */
    public static function views(): array
    {
        return [
            'unchanged' => [
                static function (Node $view): void {
                },
            ],
            'float level' => [
                static function (Node $view): void {
                    self::first($view)->f['level'] = 1.0;
                },
            ],
            'fractional level' => [
                static function (Node $view): void {
                    self::first($view)->f['level'] = 1.5;
                },
            ],
            'string level' => [
                static function (Node $view): void {
                    self::first($view)->f['level'] = 'one';
                },
            ],
            'invalid utf-8' => [
                static function (Node $view): void {
                    $view->children[] = new Node('paragraph', [Node::text("a\xC3(", -1, -1)]);
                },
            ],
            'object field' => [
                static function (Node $view): void {
                    self::first($view)->f['probe'] = new stdClass();
                },
            ],
            'unknown type' => [
                static function (Node $view): void {
                    $view->children[] = new Node('no_such_node', []);
                },
            ],
            'wrong const' => [
                static function (Node $view): void {
                    self::first($view)->type = 'Heading';
                },
            ],
        ];
    }

    /**
     * @param \Closure(\MarkupCarve\Carve\Node): void $edit
     */
    #[DataProvider('views')]
    public function testCheckTreeOfAgreesWithTheJsonCheck(Closure $edit): void
    {
        $view = Extensions::copy(Carve::parse(self::DOC));
        $edit($view);

        self::assertSame(self::outcome(static fn () => TreeReader::check(TreeEncoder::json($view, null))), self::outcome(
            static fn () => TreeReader::checkTreeOf($view),
        ));
    }

    public function testOneKeywordSchemasReportTheirKeyword(): void
    {
        $schema = (object)[

            'properties' => (object)[
                'a' => (object)['const' => 'x', 'description' => 'd'],
                'b' => (object)['type' => 'string', '$comment' => 'c'],
            ],
        ];

        $validator = new SchemaValidator($schema);
        self::assertTrue($validator->valid((object)['a' => 'x', 'b' => 's'], $schema));
        self::assertFalse($validator->valid((object)['a' => 'y'], $schema));
        self::assertSame(['$.a: const "x"'], $validator->errors);

        $validator = new SchemaValidator($schema);
        self::assertFalse($validator->valid((object)['b' => 1], $schema));
        self::assertSame(['$.b: type "string"'], $validator->errors);
    }

    private static function first(Node $view): Node
    {
        $first = $view->children[0] ?? null;
        self::assertInstanceOf(Node::class, $first);

        return $first;
    }

    private static function outcome(Closure $check): string
    {
        try {
            $check();

            return 'ok';
        } catch (TreeError $e) {
            return 'TreeError ' . $e->getMessage();
        } catch (Throwable $e) {
            return $e::class;
        }
    }
}

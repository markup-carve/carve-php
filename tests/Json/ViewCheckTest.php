<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Json;

use Closure;
use MarkupCarve\Carve\Attrs;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Extension\Navigation;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Json\ViewCheck;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * carve-php#5978: `ViewCheck::passes()` is true only for views the full tree check accepts.
 */
final class ViewCheckTest extends TestCase
{
    /**
     * @var string
     */
    private const DOC = "{#a}\n= A </#b>\n\nSee </#a> and *b*.\n\n{#b}\n== B\n\n- item\n\n> quote\n\n![alt](x.png \"t\")\n";

    public function testANumberedViewTakesTheShortcut(): void
    {
        [$view, $options] = self::view();

        self::assertTrue(ViewCheck::passes($view, $options));
        TreeReader::checkTreeOf($view, $options);
    }

    /**
     * @return array<string, array{\Closure(\MarkupCarve\Carve\Node): void, bool}>
     */
    public static function edits(): array
    {
        $text = static function (string $value): Node {
            $node = new Node('text');
            $node->f['value'] = $value;

            return $node;
        };
        $deep = static function (string $type, int $levels) use ($text): Node {
            $node = $text('d');
            for ($i = 0; $i < $levels; $i++) {
                $node = new Node($type, [$node]);
            }

            return $node;
        };

        return [
            'unchanged' => [
                static function (Node $view): void {
                }, true,
            ],
            'inserted paragraph' => [
                static function (Node $view) use ($text): void {
                    $view->children[] = new Node('paragraph', [$text('tail')]);
                }, true,
            ],
            'block in inline content' => [
                static function (Node $view) use ($text): void {
                    self::first($view)->children[] = new Node('paragraph', [$text('x')]);
                }, false,
            ],
            'duplicate attribute key' => [
                static function (Node $view): void {
                    $attrs = new Attrs();
                    $attrs->pairs = [['k', '1'], ['K', '2']];
                    self::first($view)->f['attrs'] = $attrs;
                }, false,
            ],
            'undeclared data' => [
                static function (Node $view): void {
                    self::first($view)->f['data'] = ['x.undeclared' => 1];
                }, false,
            ],
            'a node in data' => [
                static function (Node $view): void {
                    self::first($view)->f['data'] = ['carve.headingNumbers' => ['type' => 'text']];
                }, false,
            ],
            'string level' => [
                static function (Node $view): void {
                    self::first($view)->f['level'] = 'one';
                }, false,
            ],
            'float level' => [
                static function (Node $view): void {
                    self::first($view)->f['level'] = 1.0;
                }, false,
            ],
            'invalid utf-8' => [
                static function (Node $view) use ($text): void {
                    // The canonical JSON writes U+FFFD for the stray byte, which reads back.
                    $view->children[] = new Node('paragraph', [$text("a\xC3(")]);
                }, true,
            ],
            'inline depth over the limit' => [
                static function (Node $view) use ($deep): void {
                    $view->children[] = new Node('paragraph', [$deep('span', 205)]);
                }, false,
            ],
            'block depth over the limit' => [
                static function (Node $view) use ($deep): void {
                    $view->children[] = $deep('block_quote', 205);
                }, false,
            ],
        ];
    }

    /**
     * @param \Closure(\MarkupCarve\Carve\Node): void $edit
     * @param bool $valid
     */
    #[DataProvider('edits')]
    public function testThePassAgreesWithTheFullCheck(Closure $edit, bool $valid): void
    {
        [$view, $options] = self::view();
        $edit($view);
        try {
            TreeReader::checkTreeOf($view, $options);
            $full = true;
        } catch (Throwable) {
            $full = false;
        }

        self::assertSame($valid, $full, 'the full check');
        if (!$full) {
            self::assertFalse(ViewCheck::passes($view, $options));
        }
    }

    /**
     * @return array{\MarkupCarve\Carve\Node, array<string, mixed>}
     */
    private static function view(): array
    {
        $captured = null;
        $spy = new ExtensionDefinition('x.spy', '1', documentTransform: static function (Node $view) use (&$captured): Node {
            $captured = Extensions::copy($view);

            return $view;
        });
        $numbers = Navigation::headingNumbers();
        Carve::render(self::DOC, ['extensions' => [$numbers, $spy]]);
        self::assertInstanceOf(Node::class, $captured);

        return [$captured, ['extensions' => [$numbers, $spy, $numbers->name => $numbers->version, $spy->name => $spy->version]]];
    }

    private static function first(Node $view): Node
    {
        $first = $view->children[0] ?? null;
        self::assertInstanceOf(Node::class, $first);
        $inner = $first->children[0] ?? $first;

        return $inner instanceof Node ? $inner : $first;
    }
}

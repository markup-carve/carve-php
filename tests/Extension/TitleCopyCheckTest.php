<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Extension;

use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Extension\CopyLedger;
use MarkupCarve\Carve\Extension\ExtensionDefinition;
use MarkupCarve\Carve\Extension\Extensions;
use MarkupCarve\Carve\Extension\Navigation;
use MarkupCarve\Carve\Extension\NavTree;
use MarkupCarve\Carve\Generated\Tables;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Json\ViewCheck;
use MarkupCarve\Carve\Node;
use PHPUnit\Framework\TestCase;
use Throwable;

/**
 * carve-php#5978: the view check reads a title copy as its template, and still gives the result of the full check.
 */
final class TitleCopyCheckTest extends TestCase
{
    /**
     * @var string
     */
    private const DOC = "{#a}\n= A </#b>\n\nSee </#a> and </#b>.\n\n{#b}\n== B </#a>\n\nAgain </#b>.\n";

    public function testHeadingNumbersRecordsItsTitleCopies(): void
    {
        $run = Navigation::headingNumbers()->documentTransform;
        self::assertNotNull($run);
        [$view, $copies] = CopyLedger::run($run, self::resolved(self::DOC));

        self::assertNotSame([], $copies);
        foreach ($copies as [$copy, $template]) {
            self::assertNotSame($copy, $template);
            self::assertTrue(Extensions::same($copy, $template));
        }
        $options = ['extensions' => [Navigation::NUMBERS => '1']];
        self::assertTrue(ViewCheck::passes($view, $options, $copies));
        self::assertTrue(ViewCheck::passes($view, $options));
        TreeReader::checkTreeOf($view, $options);
    }

    public function testATransformThatCallsACertifiedOneRecordsNoCopies(): void
    {
        $run = Navigation::headingNumbers()->documentTransform;
        self::assertNotNull($run);
        $wrapper = static fn (Node $view): Node => $run($view);
        [, $copies] = CopyLedger::run($wrapper, self::resolved(self::DOC));

        self::assertSame([], $copies);
    }

    public function testACopyEditedByAWrappingTransformFailsTheCheck(): void
    {
        $run = Navigation::headingNumbers()->documentTransform;
        self::assertNotNull($run);
        $wrapper = new ExtensionDefinition('x.wrap', '1', documentTransform: static function (Node $view) use ($run): Node {
            $view = $run($view);
            $last = null;
            NavTree::visit($view, static function (Node $node) use (&$last): void {
                if ($node->type === 'text') {
                    $last = $node;
                }
            }, true);
            self::assertNotNull($last);
            $last->f['value'] = 5;

            return $view;
        });
        $result = Carve::renderWithDocumentTransforms(self::DOC, [], ['extensions' => [$wrapper]]);

        self::assertContains('extension-transform-failed', $result->codes());
    }

    public function testATemplateIsWalkedAgainInADeeperPlace(): void
    {
        $template = self::emphasis(NavTree::text('t'));
        $deep = Extensions::copy($template);
        $copies = [spl_object_id($deep) => [$deep, $template]];
        $nested = $deep;
        for ($i = 0; $i < Tables::INLINE_DEPTH; $i++) {
            $nested = self::emphasis($nested);
        }
        $view = self::document([new Node('paragraph', [$template]), new Node('paragraph', [$nested])]);

        self::assertFalse(self::fullCheck($view));
        self::assertFalse(ViewCheck::passes($view, [], $copies));

        $shallow = Extensions::copy($template);
        $view = self::document([new Node('paragraph', [$template]), new Node('paragraph', [self::emphasis($shallow)])]);
        self::assertTrue(self::fullCheck($view));
        self::assertTrue(ViewCheck::passes($view, [], [spl_object_id($shallow) => [$shallow, $template]]));
    }

    public function testATemplateIsCheckedAgainInAnotherKindOfPlace(): void
    {
        $template = NavTree::text('t');
        $copy = Extensions::copy($template);
        $view = self::document([new Node('paragraph', [$template]), $copy]);

        self::assertFalse(self::fullCheck($view));
        self::assertFalse(ViewCheck::passes($view, [], [spl_object_id($copy) => [$copy, $template]]));
    }

    public function testAnInvalidTemplateFailsInEveryPlace(): void
    {
        $template = NavTree::text('t');
        $template->f['value'] = 5;
        $copy = Extensions::copy($template);
        $view = self::document([new Node('paragraph', [NavTree::text('x')]), new Node('paragraph', [$copy])]);

        self::assertFalse(self::fullCheck($view));
        self::assertFalse(ViewCheck::passes($view, [], [spl_object_id($copy) => [$copy, $template]]));
    }

    private static function resolved(string $source): Node
    {
        $captured = null;
        $spy = new ExtensionDefinition('x.spy', '1', documentTransform: static function (Node $view) use (&$captured): Node {
            $captured = Extensions::copy($view);

            return $view;
        });
        Carve::render($source, ['extensions' => [$spy]]);
        self::assertInstanceOf(Node::class, $captured);

        return $captured;
    }

    /**
     * @param array<\MarkupCarve\Carve\Node> $children
     */
    private static function document(array $children): Node
    {
        $view = self::resolved("x\n");
        $view->children = $children;

        return $view;
    }

    private static function emphasis(Node $child): Node
    {
        return new Node('emphasis', [$child]);
    }

    private static function fullCheck(Node $view): bool
    {
        try {
            TreeReader::checkTreeOf($view, []);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}

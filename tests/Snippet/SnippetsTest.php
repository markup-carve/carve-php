<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Snippet;

use Closure;
use MarkupCarve\Carve\Carve;
use MarkupCarve\Carve\Diagnostics\Diagnostic;
use MarkupCarve\Carve\Extension\DocumentTransform;
use MarkupCarve\Carve\Json\TreeReader;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Snippet\Expander;
use PHPUnit\Framework\TestCase;

/**
 * Named snippets beyond the clause cases (carve#6395), as carve-js `test/snippets.test.ts` pins them.
 */
final class SnippetsTest extends TestCase
{
    protected function tearDown(): void
    {
        Expander::$testLimits = null;
    }

    public function testC2B236AGluedBlockAfterAQuotedValueHoldingABraceStillReads(): void
    {
        $tree = json_decode(Carve::toJson('{$x title="a}b"}{.foo}: Hi'), true);
        $this->assertSame(['classes' => ['foo'], 'keyValues' => [['title', 'a}b']]], $tree['children'][0]['attrs']);
    }

    public function testC2H215ATransformThatChangesTheViewKeepsTheCarriersOfTheUses(): void
    {
        $result = Carve::renderWithDocumentTransforms("{\$x}: Hello\n\n{{ \$x }}\n", [self::append()], ['roundtrip' => true]);
        $this->assertStringContainsString('<p data-carve-snippet="1:block:$x">Hello</p>', $result->html);
        $this->assertStringContainsString('<p>end</p>', $result->html);
    }

    public function testC2N090TheDefinitionsOfAnIncludeInsideAContainerJoinTheRegistry(): void
    {
        $result = Carve::renderWithDiagnostics("- {{ a.crv }}\n\n{{ \$v }}\n", ['includes' => self::files(['a.crv' => "{\$v}: X\n"])]);
        $this->assertStringContainsString('<p>X</p>', $result->html);
        $this->assertSame([], self::codes($result->diagnostics));
    }

    public function testC2H215ACopyThatRendersAsOneLinkCarriesTheCarriersOnItsAnchor(): void
    {
        $this->assertStringContainsString(
            '<a href="/u" data-carve-snippet="1:inline:$v">x</a>',
            Carve::render("{\$v}: [x](/u)\n\nA {{ \$v }}\n", ['roundtrip' => true]),
        );
        $this->assertStringContainsString(
            '<span data-carve-snippet="1:inline:$v">[x][k]</span>',
            Carve::render("{\$v}: [x][k]\n\nA {{ \$v }}\n", ['roundtrip' => true]),
        );
    }

    public function testC2N091TheRenderExpandsItsOwnCopyAndTheParsedTreeKeepsItsUses(): void
    {
        $parsed = Carve::parse("{\$x}: Hello\n\n{{ \$x }}\n");
        $this->assertSame('<p>Hello</p>', Carve::render($parsed));
        $types = array_map(static fn (Node $node): string => $node->type, $parsed->children ?? []);
        $this->assertSame(['snippet_definition', 'snippet_use'], $types);
    }

    public function testC2N095AnAuthoredDefinitionWithNoUseIsNoTransformFinding(): void
    {
        foreach (["{\$x}: Hello\n\nA\n", "{\$x}: [l][k]\n\n[k]: /u\n\n{{ \$x }}\n"] as $source) {
            $result = Carve::renderWithDocumentTransforms($source, [self::append()], ['strictLosses' => true]);
            $this->assertSame([], self::codes($result->diagnostics), $source);
        }
    }

    public function testC2H215ALoneLinkInsideLinkTextPutsTheCarriersOnASpan(): void
    {
        $result = Carve::renderWithDiagnostics("*[A]: Long\n\n{\$x}: [A](/inner)\n\n[{{ \$x }}](/outer)\n", ['roundtrip' => true]);
        $this->assertStringContainsString('<span data-carve-snippet="1:inline:$x"><abbr title="Long">A</abbr></span>', $result->html);
        $this->assertStringContainsString('<template data-carve-snippets>{$x}: [A](/inner)</template>', $result->html);
        $this->assertSame([], self::codes($result->diagnostics));
    }

    public function testC2H215AuthoredCarriersAreDroppedAndAMovedInlineNoteCarriesItsMark(): void
    {
        $authored = Carve::renderWithDiagnostics("{data-carve-snippet=\"1:inline:\$z\"}\nP\n", ['roundtrip' => true]);
        $this->assertSame('<p>P</p>', $authored->html);
        $this->assertSame(['carrier-attribute-dropped'], self::codes($authored->diagnostics));
        $this->assertStringContainsString(
            'data-carve-snippet-mark="1"',
            Carve::render("{\$s}: x^[note]\n\n[Go {{ \$s }}](/u)\n", ['roundtrip' => true]),
        );
    }

    public function testC2N094AKeyOfAKeyValueAttributeCountsWithItsValue(): void
    {
        // Two nodes, 64, and `text`, `X`, `paragraph`, `foo` and `v`, 18: 82 bytes.
        $source = "{\$x foo=v}: X\n\n{{ \$x }}\n";
        Expander::$testLimits = ['derivedTextBytes' => 82];
        $this->assertSame('<p foo="v">X</p>', Carve::render($source));
        Expander::$testLimits = ['derivedTextBytes' => 81];
        $this->assertSame(['derived-text-budget'], self::codes(Carve::renderWithDiagnostics($source)->diagnostics));
    }

    public function testC2N092ACopyUsedBeforeANestedIncludeResolvesInTheScopesAroundItsFile(): void
    {
        $includes = self::files(['a.crv' => "[k]: /from-a\n\n{{ b.crv }}\n", 'b.crv' => "{\$s}: [x][k]\n"]);
        $this->assertSame('<p><a href="/from-a">x</a></p>', Carve::render("{{ \$s }}\n\n{{ a.crv }}\n", ['includes' => $includes]));
    }

    public function testC2N092ANodeInTheMetadataOfACopyIsCopiedAndCounted(): void
    {
        $pos = ['startLine' => 1, 'endLine' => 1, 'startColumn' => 1, 'endColumn' => 2, 'startOffset' => 0, 'endOffset' => 1];
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'snippet_definition',
                    'name' => 'x',
                    'children' => [
                        [

                            'type' => 'span',
                            'metadata' => [
                                'title' => [['type' => 'text', 'value' => 'T', 'pos' => $pos]],
                            ],
                            'children' => [
                                ['type' => 'text', 'value' => 'X'],
                            ],
                        ],
                    ],
                ],
                ['type' => 'paragraph', 'children' => [['type' => 'snippet_use', 'name' => 'x', 'mode' => 'inline']]],
            ],
        ];
        Expander::$testLimits = ['snippetNodes' => 2];
        $this->assertSame('<p>{{ $x }}</p>', Carve::render($tree));
        Expander::$testLimits = ['snippetNodes' => 3];
        $this->assertSame('<p><span>X</span></p>', Carve::render($tree));
    }

    public function testC2N093ARefusedUseKeepsItsFindingThroughADocumentTransform(): void
    {
        $noop = new DocumentTransform('noop', static fn (Node $view): Node => $view);
        $fresh = new DocumentTransform('fresh', static function (Node $view): Node {
            $copy = new Node('document', $view->children);
            $copy->f = $view->f;

            return $copy;
        });
        foreach ([$noop, $fresh] as $transform) {
            $result = Carve::renderWithDocumentTransforms("A {{ \$missing }}\n", [$transform]);
            $this->assertSame(['snippet-unresolved'], self::codes($result->diagnostics), $transform->name);
        }
    }

    public function testC2W159ABreakInsideAnInlineContainerOfASuppliedDefinitionIsWrittenAsASpace(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'snippet_definition',
                    'name' => 'x',
                    'children' => [
                        [

                            'type' => 'emphasis',
                            'children' => [
                                ['type' => 'text', 'value' => 'a'], ['type' => 'soft_break'], ['type' => 'text', 'value' => 'b'],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $written = Carve::writeWithDiagnostics($tree);
        $this->assertSame("{\$x}: /a b/\n", $written->source);
        $this->assertCount(1, Carve::parse($written->source)->children ?? []);
    }

    public function testC2W159ABreakInARubyBaseOfASuppliedDefinitionIsWrittenAsASpace(): void
    {
        $tree = [

            'type' => 'document',
            'astVersion' => '2.0',
            'children' => [
                [

                    'type' => 'snippet_definition',
                    'name' => 'x',
                    'children' => [
                        [

                            'type' => 'ruby',
                            'pairs' => [
                                [
                                    'type' => 'ruby_pair', 'base' => [
                                        ['type' => 'text', 'value' => 'a'], ['type' => 'soft_break'], ['type' => 'text', 'value' => 'b'],
                                    ], 'annotation' => [['type' => 'text', 'value' => 'c']],
                                ],
                            ],
                        ],
                    ],
                ],
            ],
        ];
        $written = Carve::writeWithDiagnostics($tree);
        $this->assertCount(2, explode("\n", $written->source));
        $this->assertCount(1, Carve::parse($written->source)->children ?? []);
    }

    public function testC2N096ADefinitionOfAnIncludedFileThatTheIncludingFileUsesIsNoUnusedSnippet(): void
    {
        $found = Carve::lint("{{ child.crv }}\n\n{{ \$x }}\n", ['includes' => self::files(['child.crv' => "{\$x}: X\n\n{\$y}: Y\n"])]);
        $unused = array_values(array_filter($found, static fn (Diagnostic $entry): bool => $entry->code === 'unused-snippet'));
        $messages = array_map(static fn (Diagnostic $entry): string => $entry->message, $unused);
        $this->assertSame(['Snippet "$y" is defined but never used.'], $messages);
    }

    public function testC2N095ATransformThatEditsAnAuthoredDefinitionAddsNoSnippetNode(): void
    {
        $edit = new DocumentTransform('edit', static function (Node $view): Node {
            $definition = $view->children[0] ?? null;
            if ($definition !== null && $definition->type === 'snippet_definition') {
                $text = new Node('text');
                $text->f['value'] = 'Changed';
                $definition->children = [$text];
            }
            $view->children[] = self::paragraph('end');

            return $view;
        });
        $edited = Carve::renderWithDocumentTransforms("{\$x}: Hello\n\nA\n", [$edit], ['strictLosses' => true]);
        $this->assertSame([], self::codes($edited->diagnostics));
        $add = self::addDefinition('y');
        $added = Carve::renderWithDocumentTransforms("{\$x}: Hello\n\nA\n", [$add]);
        $this->assertSame(['snippet-unexpanded-transform'], self::codes($added->diagnostics));
    }

    public function testC2N095ADefinitionATransformAddsEntersNoRegistrySoItReportsNoDuplicate(): void
    {
        $result = Carve::renderWithDocumentTransforms("{\$x}: Hello\n\nA\n", [self::addDefinition('x')]);
        $this->assertSame(['snippet-unexpanded-transform'], self::codes($result->diagnostics));
    }

    public function testC2N092EachCopyHasAttributesOfItsOwn(): void
    {
        $html = Carve::render("{\$x .a}: Hi\n\n{{ \$x }}\n\n{{ \$x }}\n");
        $this->assertSame("<p class=\"a\">Hi</p>\n<p class=\"a\">Hi</p>", $html);
    }

    public function testC2H215ALinkThatHoldsAUseIsNoLoneElement(): void
    {
        $html = Carve::render("{\$n}: x[^f]\n\n{\$l}: [a {{ \$n }}](/u)\n\nB {{ \$l }}\n\n[^f]: F\n", ['roundtrip' => true]);
        $this->assertStringContainsString('<span data-carve-snippet="1:inline:$l"><a href="/u">', $html);
    }

    public function testC2N090AcrossFilesTheLosingDefinitionReportsDuplicateSnippetAndNoUnusedHint(): void
    {
        $found = Carve::lint("{\$x}: A\n\n{{ c.crv }}\n", ['includes' => self::files(['c.crv' => "{\$x}: B\n"])]);
        $this->assertSame(
            [['unused-snippet', null], ['duplicate-snippet', 'c.crv']],
            array_map(static fn (Diagnostic $entry): array => [$entry->code, $entry->file], $found),
        );
    }

    public function testC2N020ASectionIncludeDoesNotLintPastItsSectionForAnAppendedDefinition(): void
    {
        $files = self::files(['s.crv' => "# Pick\n\nP\n\n# Else\n\n> {\$v}:x\n\n{\$w}: W\n"]);
        $found = Carve::lint("{{ s.crv#Pick }}\n", ['includes' => $files]);
        $this->assertSame([], array_values(array_filter($found, static fn (Diagnostic $entry): bool => $entry->file === 's.crv')));
    }

    public function testC2N095ADefinitionAnIncludeBringsIsTheTransformsOwnInput(): void
    {
        $result = Carve::renderWithDocumentTransforms(
            "{{ d.crv }}\n",
            [self::append('e')],
            ['includes' => self::files(['d.crv' => "{\$x}: Hi\n"]), 'strictLosses' => true],
        );
        $this->assertSame(['<p>e</p>', []], [$result->html, self::codes($result->diagnostics)]);
    }

    public function testC2N096AFileIncludedTwiceKeepsTheUnusedHintOfItsWinningDefinition(): void
    {
        $codes = self::codes(Carve::lint("{{ c.crv }}\n\n{{ c.crv }}\n", ['includes' => self::files(['c.crv' => "{\$x}: X\n"])]));
        sort($codes);
        $this->assertSame(['duplicate-snippet', 'unused-snippet'], $codes);
    }

    public function testC2N092AfterATransformACopyStillResolvesInTheScopesAroundItsFile(): void
    {
        $includes = self::files(['a.crv' => "[k]: /from-a\n\n{{ b.crv }}\n", 'b.crv' => "{\$s}: [x][k]\n"]);
        $result = Carve::renderWithDocumentTransforms("{{ a.crv }}\n\n{{ \$s }}\n", [self::append('e')], ['includes' => $includes]);
        $this->assertSame("<p><a href=\"/from-a\">x</a></p>\n<p>e</p>", $result->html);
    }

    public function testC2A221AUseWhoseModeDoesNotMatchItsListIsRefused(): void
    {
        $this->expectExceptionMessage('snippet-use-mode-mismatch');
        TreeReader::read('{"type":"document","astVersion":"2.0","children":[{"type":"snippet_use","name":"v","mode":"inline"}]}');
    }

    /**
     * @param array<string, string> $files
     *
     * @return \Closure(array<string, mixed>): array<string, string>
     */
    private static function files(array $files): Closure
    {
        return static fn (array $request): array => isset($files[$request['path']])
            ? ['text' => $files[$request['path']], 'id' => $request['path']] : ['error' => 'not_found'];
    }

    private static function paragraph(string $text): Node
    {
        $value = new Node('text');
        $value->f['value'] = $text;

        return new Node('paragraph', [$value]);
    }

    private static function append(string $text = 'end'): DocumentTransform
    {
        return new DocumentTransform('append', static function (Node $view) use ($text): Node {
            $view->children[] = self::paragraph($text);

            return $view;
        });
    }

    private static function addDefinition(string $name): DocumentTransform
    {
        return new DocumentTransform('add', static function (Node $view) use ($name): Node {
            $text = new Node('text');
            $text->f['value'] = 'Y';
            $definition = new Node('snippet_definition', [$text]);
            $definition->f['name'] = $name;
            $view->children[] = $definition;

            return $view;
        });
    }

    /**
     * @param array<\MarkupCarve\Carve\Diagnostics\Diagnostic> $diagnostics
     *
     * @return array<string>
     */
    private static function codes(array $diagnostics): array
    {
        return array_map(static fn (Diagnostic $entry): string => $entry->code, $diagnostics);
    }
}

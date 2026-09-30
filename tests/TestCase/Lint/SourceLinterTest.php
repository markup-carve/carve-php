<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\SourceLinter;
use PHPUnit\Framework\TestCase;

class SourceLinterTest extends TestCase
{
    public function testReportsSharedTriggers(): void
    {
        $cases = [
            'heading-trailing-attribute' => "# Title {#id}\n",
            'fence-opener-fallback' => "``` php extra bad info\ncode\n```\n",
            'raw-block-syntax' => "```raw html\nx\n```\n",
            'blockquote-marker-without-space' => ">quoted\n",
            'block-marker-as-text' => "  ::: note\n",
            'fence-delimiter-indentation' => "  ```\n  x\n  ```\n",
            'list-item-body-detached' => "1. item\n\n  # heading\n",
            'list-item-block-overindented' => "-{.x1} item\n\n       # heading\n",
            'empty-include-path' => "{{ #section }}\n",
            'carve-version-unsupported' => "---\ncarve-version: 99.0\n---\n\nx\n",
            'unclosed-container-fence' => "::: note\nbody\n",
            'colon-fence-length-mismatch' => ":::: note\nbody\n:::\n",
            'fence-title-syntax' => "::: note Some Title\nbody\n:::\n",
            'footnotes-placement-in-container' => "Intro[^a].\n\n> ::: footnotes\n> :::\n\n[^a]: only note\n",
        ];
        foreach ($cases as $rule => $source) {
            $this->assertContains($rule, array_column((new SourceLinter())->lint($source), 'rule'), $source);
        }
    }

    public function testLiteralExamplesAndClosedContainersDoNotWarn(): void
    {
        foreach (
            [
                "```\n{{ #x }}\n>quoted\n  ::: note\n```\n",
                "%%%\n{{ #x }}\n>quoted\n%%%\n",
                "`hello\n>quoted\n`\n",
                "`a\n```\n`\n",
                "---\ncarve-version: \"0.1.0\"\n---\n\nx\n",
                "---\ncarve-version: '0.1.0'\n---\n\nx\n",
                "`{{ #x }}`\n",
                "\\{{ #x }}\n",
                "::: note\nbody\n:::\n",
                "> ::: note\n> body\n> :::\n",
                "- item\n\n  # Heading\n",
                "---\ncarve-version: 0.1.0\n---\n\nx\n",
            ] as $source
        ) {
            $this->assertSame([], (new SourceLinter())->lint($source), $source);
        }
    }

    public function testIncludeWarningUsesOriginalUnicodeBytes(): void
    {
        $source = "😀\r\n\r\n> {{ #missing }}\r\n";
        $warnings = (new SourceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame(3, $warnings[0]->line);
        $this->assertSame(3, $warnings[0]->column);
        $this->assertSame('{{ #missing }}', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    public function testFenceWarningsRespectTheOwnerAndCloserColumn(): void
    {
        foreach (["::: note\n::: tip\nx\n:::\n", "::: note\nx\n\n    :::\n", "a. ::: note\n   x\n"] as $source) {
            $this->assertContains('unclosed-container-fence', array_column((new SourceLinter())->lint($source), 'rule'), $source);
        }
        $this->assertSame([], (new SourceLinter())->lint(":::: note\n:::\ninner\n:::\n::::\n"));
    }

    public function testQuotedMarkupGetsSpecificWarningsAndMarkerSpans(): void
    {
        $this->assertContains('raw-block-syntax', array_column((new SourceLinter())->lint("> ```raw html\n> x\n> ```\n"), 'rule'));
        $this->assertContains('heading-trailing-attribute', array_column((new SourceLinter())->lint("> # T {#id}\n"), 'rule'));
        $source = "  {.cls} text\n";
        $warnings = (new SourceLinter())->lint($source);
        $this->assertCount(1, $warnings);
        $this->assertSame('{.', substr($source, $warnings[0]->start, $warnings[0]->end - $warnings[0]->start));
    }

    public function testOverindentedBlocksReportOnlyTheirOpeners(): void
    {
        foreach (
            [
                ["- a\n  > q\n   | c |\n   |---|\n   | 1 |\n", [3]],
                ["- a\n   | c |\n   |---|\n   | 1 |\n", [2]],
                ["- a\n   > q\n   > r\n", [2]],
                ["- a\n   > q\n   lazy\n   > r\n", [2]],
                ["- a\n    > q\n   > r\n", [2]],
                ["> - a\n>    | x |\n>    | y |\n", [2]],
                ["- a\n  - b\n     | x |\n     | y |\n", [3]],
                ["- a\n   | x |\n   > q\n   | y |\n", [2, 3, 4]],
                ["- a\n   > q\n   >\n   > r\n", [2]],
                ["- a\n   > q\n\n   > r\n", [2, 4]],
                ["- a\n   > ```\n   > code\n   > ```\n   > r\n", [2]],
                ["- a\n   > q\nlazy\n   > > r\n", [2]],
                ["- a\n   | x |\n    | y |\n", [2]],
                ["- a\n  | x |\n   | y |\n", []],
                ["- | x |\n   | y |\n", []],
                ["- a\n  > q\n   > r\n", []],
                ["> - a\n>    > q\n>    > r\n", [2]],
                ["> - a\n>\n>    > q\n", [3]],
                ["- > q\n   > r\n", []],
                ["- a\n   # a\n   # b\n", [2, 3]],
                ["- a\n   ---\n   ---\n", [2, 3]],
                ["- a\n   | a |\n\n   | b |\n", [2, 4]],
            ] as [$source, $expected]
        ) {
            $warnings = array_filter((new SourceLinter())->lint($source), static fn ($warning): bool => $warning->rule === 'list-item-block-overindented');
            $this->assertSame($expected, array_values(array_column($warnings, 'line')), $source);
        }
    }
}

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
}

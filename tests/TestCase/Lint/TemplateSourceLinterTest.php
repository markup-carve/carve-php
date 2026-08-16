<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\TemplateSourceLinter;
use PHPUnit\Framework\TestCase;

class TemplateSourceLinterTest extends TestCase
{
    public function testReportsBracedCommentsWhenTemplateTagsArePresent(): void
    {
        $source = "before {% note %}\n{% if page.ok %}\nafter {% endif %}";
        $warnings = (new TemplateSourceLinter())->lint($source);
        $this->assertCount(3, $warnings);
        $this->assertSame(
            array_fill(0, 3, TemplateSourceLinter::RULE_BRACED_COMMENT_IN_A_TEMPLATE_SOURCE),
            array_map(static fn ($warning): string => $warning->rule, $warnings),
        );
        $this->assertSame([1, 2, 3], array_map(static fn ($warning): int => $warning->line, $warnings));
    }

    public function testDoesNotReportAPlainBracedCommentOrOpaqueTemplateText(): void
    {
        $linter = new TemplateSourceLinter();
        $this->assertSame([], $linter->lint('a {% ordinary note %} b'));
        $this->assertSame([], $linter->lint('`{% if x %}` and {% ordinary note %}'));
    }
}

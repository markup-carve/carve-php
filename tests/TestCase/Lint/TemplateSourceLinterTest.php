<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Lint;

use MarkupCarve\Carve\Lint\TemplateSourceLinter;
use MarkupCarve\Carve\Node\Block\Comment;
use PHPUnit\Framework\TestCase;
use ReflectionMethod;

class TemplateSourceLinterTest extends TestCase
{
    public function testReportsOnlyTagShapedComments(): void
    {
        $source = "before {% note %}\n{% if page.ok %}\nafter {% endif %}";
        $warnings = (new TemplateSourceLinter())->lint($source);
        $this->assertCount(2, $warnings);
        $this->assertSame(
            array_fill(0, 2, TemplateSourceLinter::RULE_BRACED_COMMENT_IN_A_TEMPLATE_SOURCE),
            array_map(static fn ($warning): string => $warning->rule, $warnings),
        );
        $this->assertSame([2, 3], array_map(static fn ($warning): int => $warning->line, $warnings));
    }

    public function testRawPairDoesNotReportAnOrdinaryCommentBetweenThem(): void
    {
        $source = "{% raw %}\ntext {% note %}\n{% endraw %}";
        $warnings = (new TemplateSourceLinter())->lint($source);

        $this->assertCount(2, $warnings);
        $this->assertSame([1, 3], array_map(static fn ($warning): int => $warning->line, $warnings));
    }

    public function testRecognizesEveryTagShapeAndWalksContainers(): void
    {
        $source = implode("\n", [
            '> {% raw %}',
            '> {% endraw %}',
            '> {% endif %}',
            '> {% endfor %}',
            '> {% endblock %}',
            '> {% if page.ok %}',
            '> {% for item in items %}',
            '> {% block content %}',
        ]);
        $warnings = (new TemplateSourceLinter())->lint($source);

        $this->assertCount(8, $warnings);
        $this->assertSame(range(1, 8), array_map(static fn ($warning): int => $warning->line, $warnings));
    }

    public function testUnpositionedTagShapedCommentUsesDocumentStart(): void
    {
        $comment = new Comment('raw', null, true);
        $method = new ReflectionMethod(TemplateSourceLinter::class, 'warningForComment');
        $warning = $method->invoke(new TemplateSourceLinter(), $comment, [], 0);

        $this->assertSame(1, $warning->line);
        $this->assertSame(1, $warning->column);
        $this->assertSame(0, $warning->start);
        $this->assertSame(0, $warning->end);
    }

    public function testDoesNotReportAPlainBracedCommentOrOpaqueTemplateText(): void
    {
        $linter = new TemplateSourceLinter();
        $this->assertSame([], $linter->lint('a {% ordinary note %} b'));
        $this->assertSame([], $linter->lint('`{% if x %}` and {% ordinary note %}'));
    }
}

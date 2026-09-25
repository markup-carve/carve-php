<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Node\Block\CodeBlock;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AQuotedFenceTitleKeepsItsBackslashTest extends TestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function titleProvider(): array
    {
        return [
            'code title with a backslash' => ["```php \"a \\ b\"\nx\n```\n"],
            'admonition title with a backslash' => ["::: note \"a \\ b\"\nx\n:::\n"],
            'typed div title with a backslash' => ["::: widget \"a \\ b\"\nx\n:::\n"],
            'code title with paired backslashes' => ["```php \"a \\\\ b\"\nx\n```\n"],
            'admonition title with paired backslashes' => ["::: note \"a \\\\ b\"\nx\n:::\n"],
            'code title ending in a backslash' => ["```php \"a\\\"\nx\n```\n"],
            'admonition title ending in a backslash' => ["::: note \"a\\\"\nx\n:::\n"],
            'code title without a backslash' => ["```php \"plain\"\nx\n```\n"],
        ];
    }

    #[DataProvider('titleProvider')]
    public function testTheTitleSettlesAfterOnePass(string $source): void
    {
        $once = CarveConverter::toCarve($source);
        $this->assertSame($once, CarveConverter::toCarve($once));
        $this->assertSame(
            CarveConverter::create()->convert($source),
            CarveConverter::create()->convert($once),
        );
    }

    public function testAQuoteStillDropsWithItsEscapingBackslash(): void
    {
        $document = new Document();
        $document->appendChild(new CodeBlock('x', 'php', null, 'a\\"b'));
        $renderer = new CarveRenderer();
        $renderer->beginConversionDiagnosticCollection();

        $this->assertSame("```php \"ab\"\nx\n```\n", $renderer->render($document));
        $report = $renderer->finishConversionDiagnosticCollection();
        $this->assertSame(['field-unspellable'], array_column($report['diagnostics'], 'code'));
    }

    public function testLinkTitleStillUsesItsOwnEscapeRule(): void
    {
        $source = '[x](/u "a\"b")' . "\n";
        $this->assertSame($source, CarveConverter::toCarve($source));
    }
}

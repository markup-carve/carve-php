<?php

declare(strict_types=1);

namespace Carve\Test\TestCase\Transform;

use Carve\CarveConverter;
use Carve\Extension\InlineFootnotesExtension;
use Carve\Transform\InlineFootnotesToParenthesesTransform;
use PHPUnit\Framework\TestCase;

class InlineFootnotesToParenthesesTransformTest extends TestCase
{
    public function testTransformSupportsMarkdownFallbackWithoutMutatingOriginalDocument(): void
    {
        $input = 'Text[A footnote]{.fn} continues.';

        $markdown = CarveConverter::markdown();
        $document = $markdown->parse($input);
        $transformed = $markdown->transform($document, new InlineFootnotesToParenthesesTransform());

        $this->assertStringContainsString('TextA footnote continues.', $markdown->render($document));
        $this->assertStringContainsString('Text (A footnote) continues.', $markdown->render($transformed));
    }

    public function testTransformSupportsPlainTextFallback(): void
    {
        $input = 'Text[A footnote]{.fn} continues.';

        $converter = CarveConverter::plainText();
        $document = $converter->parse($input);
        $document = $converter->transform($document, new InlineFootnotesToParenthesesTransform());

        $this->assertStringContainsString('Text (A footnote) continues.', $converter->render($document));
    }

    public function testOriginalDocumentStillRendersAsHtmlInlineFootnoteAfterNonHtmlTransform(): void
    {
        $input = 'Text[Footnote]{.fn} after.';

        $plain = CarveConverter::plainText();
        $document = $plain->parse($input);
        $plainDocument = $plain->transform($document, new InlineFootnotesToParenthesesTransform());

        $this->assertStringContainsString('Text (Footnote) after.', $plain->render($plainDocument));

        $html = new CarveConverter();
        $html->addExtension(new InlineFootnotesExtension());
        $htmlOutput = $html->render($document);

        $this->assertStringContainsString('role="doc-noteref"', $htmlOutput);
        $this->assertStringContainsString('role="doc-endnotes"', $htmlOutput);
    }
}

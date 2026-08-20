<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Filter;

use MarkupCarve\Carve\Ast\AstCodec;
use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Filter\ProfileFilter;
use MarkupCarve\Carve\Profile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class FootnoteDefinitionProfileTest extends TestCase
{
    /**
     * @var string
     */
    private const SOURCE = "Text[^a] and[^a].\n\n[^a]: note\n";

    /**
     * @return array<string, array{0: string}>
     */
    public static function disallowedActions(): array
    {
        return [
            'strip' => [Profile::ACTION_STRIP],
            'to_text' => [Profile::ACTION_TO_TEXT],
        ];
    }

    #[DataProvider('disallowedActions')]
    public function testDenyingFootnoteDefinitionsRemovesTheDefinitionAndLeavesReferencesLiteral(string $action): void
    {
        $profile = Profile::full()
            ->denyBlock(['footnote'])
            ->onDisallowed($action);

        $html = (new CarveConverter(profile: $profile))->convert(self::SOURCE);

        $this->assertSame('<p>Text[^a] and[^a].</p>', trim($html));
        $this->assertStringNotContainsString('doc-endnotes', $html);
    }

    #[DataProvider('disallowedActions')]
    public function testDenyingFootnoteDefinitionsLeavesNoFootnoteNodeOrReferenceNumbers(string $action): void
    {
        $profile = Profile::full()
            ->denyBlock(['footnote'])
            ->onDisallowed($action);
        $document = (new CarveConverter())->parse(self::SOURCE);
        $filtered = (new ProfileFilter())->filter($document, $profile);
        $encoded = (new AstCodec())->encode($filtered);

        $footnotes = [];
        $referenceNumbers = [];
        $this->collectFootnoteShape($encoded, $footnotes, $referenceNumbers);

        $this->assertSame([], $footnotes);
        $this->assertSame([null, null], $referenceNumbers);
    }

    public function testUnprofiledFootnoteDocumentIsUnaffected(): void
    {
        $html = (new CarveConverter())->convert(self::SOURCE);

        $this->assertStringContainsString('<a id="fnref1" href="#fn1" role="doc-noteref"><sup>1</sup></a>', $html);
        $this->assertStringContainsString('<section role="doc-endnotes" aria-label="Footnotes">', $html);
        $this->assertStringContainsString('<p>note', $html);
    }

    /**
     * @param array<string, mixed> $node
     * @param list<array<string, mixed>> $footnotes
     * @param list<int|null> $referenceNumbers
     */
    private function collectFootnoteShape(array $node, array &$footnotes, array &$referenceNumbers): void
    {
        $type = $node['type'] ?? null;
        if ($type === 'footnote') {
            $footnotes[] = $node;
        } elseif ($type === 'footnote_ref') {
            $referenceNumbers[] = $node['number'] ?? null;
        }

        foreach (($node['children'] ?? []) as $child) {
            if (is_array($child)) {
                $this->collectFootnoteShape($child, $footnotes, $referenceNumbers);
            }
        }
    }
}

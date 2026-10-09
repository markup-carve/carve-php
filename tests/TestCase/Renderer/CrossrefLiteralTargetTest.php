<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Exception\SourceUnspellableException;
use MarkupCarve\Carve\Node\Block\Paragraph;
use MarkupCarve\Carve\Node\Document;
use MarkupCarve\Carve\Node\Inline\HeadingRef;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrossrefLiteralTargetTest extends TestCase
{
    /**
     * @return iterable<string, array{string}>
     */
    public static function literalTargets(): iterable
    {
        foreach (['p\\an', 'ls\\-greet', 'a\\\\b', 'a\\', '日本語'] as $target) {
            yield $target => [$target];
        }
    }

    #[DataProvider('literalTargets')]
    public function testTheTargetKeepsItsLiteralBackslashes(string $target): void
    {
        $source = 'See </#' . $target . ">.\n";
        $once = CarveConverter::toCarve($source);
        $this->assertSame($source, $once);
        $this->assertSame($once, CarveConverter::toCarve($once));
        $converter = new CarveConverter();
        $reference = $converter->parse($source)->getChildren()[0]->getChildren()[1];
        $this->assertInstanceOf(HeadingRef::class, $reference);
        $this->assertSame($target, $reference->getTargetId());
        $this->assertSame($converter->convert($source), $converter->convert($once));
    }

    public function testLiteralTargetsInTableAndLinkHostsReadBack(): void
    {
        foreach (["| </#a\\> |\n", "| </#a\\|b> |\n", "[x </#a\\]>](u)\n"] as $source) {
            $once = CarveConverter::toCarve($source);
            $this->assertSame($once, CarveConverter::toCarve($once));
            $converter = new CarveConverter();
            $this->assertSame($converter->convert($source), $converter->convert($once));
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unspellableTargets(): iterable
    {
        foreach (['', 'a>b', 'a b', "a\tb", "a\nb", "a\rb", "a\0b"] as $target) {
            yield json_encode($target, JSON_THROW_ON_ERROR) => [$target];
        }
    }

    #[DataProvider('unspellableTargets')]
    public function testAnIngestedTargetWithNoSpellingIsRefused(string $target): void
    {
        $document = new Document();
        $paragraph = new Paragraph();
        $paragraph->appendChild(new HeadingRef($target));
        $document->appendChild($paragraph);
        $this->expectException(SourceUnspellableException::class);
        CarveConverter::carve()->render($document);
    }
}

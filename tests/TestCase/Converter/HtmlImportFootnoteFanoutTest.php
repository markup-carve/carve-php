<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use DOMElement;
use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;
use ReflectionProperty;

class HtmlImportFootnoteFanoutTest extends TestCase
{
    use ScalingGuardTrait;

    private static function source(string $shape, int $n): string
    {
        if ($shape === 'separators') {
            return '<p>Body<a href="#fn1" role="doc-noteref">1</a>.</p><section>'
                . str_repeat("<hr> \n<!--layout-->", $n) . '<p id="fn1">Note.</p></section><p>Tail.</p>';
        }
        if ($shape === 'long-inverse-class' || $shape === 'long-inverse-ref-class') {
            $role = $shape === 'long-inverse-class' ? ' role="doc-noteref"' : '';

            return '<p>' . str_repeat('<a id="r" href="#fn1" role="doc-noteref">1</a>', $n)
                . '</p><section><p>Note.<a id="fn1" href="#r" class="footnote-back ' . str_repeat('noise ', $n)
                . '"' . $role . '>back</a></p></section>';
        }
        if ($shape === 'duplicate-identities') {
            return '<p>' . str_repeat('<a id="r" href="#fn1" role="doc-noteref">1</a>', $n)
                . '</p><section><p id="fn1">Note.' . str_repeat('<a href="#r" class="footnote-back">back</a>', $n)
                . '</p></section>';
        }
        $refs = '';
        $notes = '';
        for ($i = 0; $i < $n; $i++) {
            if ($shape === 'backlinks' || $shape === 'wrapped-backlinks') {
                $refs .= '<a id="ref' . $i . '" href="#fn1" role="doc-noteref">1</a>';
                $back = '<a href="#ref' . $i . '" class="footnote-back">back</a>';
                $notes .= $shape === 'wrapped-backlinks' ? '<span>' . $back . ' </span>' : $back;
            } else {
                $refs .= '<a href="#fn' . $i . '" role="doc-noteref">1</a>';
                $note = '<p id="fn' . $i . '">Note.</p>';
                $notes .= $shape === 'empty-wrappers' ? '<div>' . $note . "</div> \n<!--layout-->" : $note;
            }
        }
        if ($shape === 'backlinks' || $shape === 'wrapped-backlinks') {
            return '<p>Body ' . $refs . '</p><section><p id="fn1">Note.' . $notes . '</p></section>';
        }

        return '<p>' . $refs . '</p><div id="endnotes">' . $notes . '</div><p>Tail.</p>';
    }

    public function testRepeatedReferenceIdentitiesKeepAllReferences(): void
    {
        $value = (new HtmlToCarve(importAdapter: 'word'))->convert(self::source('duplicate-identities', 20));
        self::assertSame(20, substr_count(explode("\n\n", $value)[0], '[^1]'));
        self::assertStringNotContainsString('back', $value);
    }

    public function testASharedInverseWithManyClassesKeepsAllReferences(): void
    {
        foreach (['long-inverse-class', 'long-inverse-ref-class'] as $shape) {
            $value = (new HtmlToCarve(importAdapter: 'word'))->convert(self::source($shape, 64));
            self::assertSame(64, substr_count(explode("\n\n", $value)[0], '[^1]'));
            self::assertStringContainsString('[^1]: Note.', $value);
            self::assertStringNotContainsString('back', $value);
        }
    }

    public function testEveryReferenceBindsAndItsWrappedBacklinksAreRemoved(): void
    {
        $value = (new HtmlToCarve(importAdapter: 'word'))->convert(self::source('wrapped-backlinks', 16));
        self::assertSame(16, substr_count(explode("\n\n", $value)[0], '[^1]'));
        self::assertStringContainsString('[^1]: Note.', $value);
        self::assertStringNotContainsString('back', $value);
    }

    public function testNestedBacklinksKeepTheirCleanupOrder(): void
    {
        $back = '<a href="#r" class="footnote-back">back</a>';
        $source = '<p>Body<a id="r" href="#fn1" role="doc-noteref">1</a>.</p>'
            . '<p id="fn1">Note.<span id="outer">' . $back . '<span>' . $back . '</span>' . $back . '</span></p>';
        self::assertSame("Body[^1].\n\n[^1]: Note.\n", (new HtmlToCarve(importAdapter: 'word'))->convert($source));
    }

    public function testManyAliasesAndRepeatedFragmentsShareOneDefinition(): void
    {
        $refs = '';
        $targets = '';
        for ($i = 0; $i < 20; $i++) {
            $refs .= '<a href="#alias' . $i . '" role="doc-noteref">1</a>';
            $targets .= '<a id="alias' . $i . '" href="#unused" class="footnote-back">1</a>';
        }
        $refs .= str_repeat('<a href="#alias0" role="doc-noteref">1</a>', 2);
        $value = (new HtmlToCarve(importAdapter: 'word'))->convert('<p>' . $refs . '</p><section><p>' . $targets . 'Note.</p></section>');
        self::assertSame(22, substr_count(explode("\n\n", $value)[0], '[^1]'));
        self::assertStringEndsWith("\n\n[^1]: Note.\n", $value);
    }

    public function testTargetCountOverridesStillControlTheWrapperClimb(): void
    {
        $converter = new class (importAdapter: 'word') extends HtmlToCarve {
            public int $lookups = 0;

            protected function countFootnoteTargets(DOMElement $node, array $used): int
            {
                $this->lookups++;

                return 1;
            }
        };
        $value = $converter->convert(self::source('shared-wrapper', 2));
        self::assertGreaterThanOrEqual(2, $converter->lookups);
        self::assertStringContainsString('[^1]:', $value);
        self::assertStringNotContainsString('[^2]:', $value);
    }

    public function testReusingTheConverterDoesNotRetainAnEarlierDocument(): void
    {
        $converter = new HtmlToCarve(importAdapter: 'word');
        $converter->convert(self::source('empty-wrappers', 12));
        self::assertSame(
            (new HtmlToCarve(importAdapter: 'word'))->convert(self::source('backlinks', 16)),
            $converter->convert(self::source('backlinks', 16)),
        );
    }

    public function testClassOverridesObserveImmediateBacklinkParentRemoval(): void
    {
        $converter = new class (importAdapter: 'word') extends HtmlToCarve {
            protected function hasClass(DOMElement $node, string $className): bool
            {
                if ($className === 'footnote-back' && $node->textContent === 'conditional') {
                    $previous = $node->parentNode?->previousSibling;

                    return !($previous instanceof DOMElement && strtolower($previous->tagName) === 'span');
                }

                return parent::hasClass($node, $className);
            }
        };
        $value = $converter->convert('<p>Body<a id="r" href="#fn1" role="doc-noteref">1</a></p><section><p id="fn1">Note.<span><a href="#r">back</a></span><span><a href="#unused">conditional</a></span></p></section>');
        self::assertStringNotContainsString('conditional', $value);
        self::assertStringContainsString('[^1]: Note.', $value);
    }

    public function testConversionReleasesItsFootnoteIndexes(): void
    {
        foreach (
            [
                new HtmlToCarve(importAdapter: 'word'), new class (importAdapter: 'word') extends HtmlToCarve {
                },
            ] as $converter
        ) {
            $converter->convert(self::source('empty-wrappers', 12));
            foreach (['footnoteTargetCounts', 'footnoteCountElements', 'footnoteAnchorIndex', 'footnoteInverseIndex', 'footnoteMarkerIndex', 'footnotePruningCounts'] as $property) {
                self::assertNull((new ReflectionProperty(HtmlToCarve::class, $property))->getValue($converter));
            }
            self::assertSame((new HtmlToCarve(importAdapter: 'word'))->convert(self::source('shared-wrapper', 12)), $converter->convert(self::source('shared-wrapper', 12)));
        }
    }

    /**
     * @return array<string, array{string, int, int}>
     */
    public static function scalingShapes(): array
    {
        return [
            'shared-note backlinks' => ['backlinks', 1024, 4096],
            'wrapped backlinks' => ['wrapped-backlinks', 1024, 4096],
            'shared definition wrapper' => ['shared-wrapper', 2048, 8192],
            'empty definition wrappers' => ['empty-wrappers', 4096, 16384],
            'footnote separators' => ['separators', 512, 2048],
            'duplicate reference identities' => ['duplicate-identities', 1024, 4096],
            'long inverse backlink classes' => ['long-inverse-class', 1024, 4096],
            'long inverse reference classes' => ['long-inverse-ref-class', 1024, 4096],
        ];
    }

    #[Group('scaling')]
    public function testInheritedFootnoteMethodsStillImportNearLinearly(): void
    {
        $converter = new class (importAdapter: 'word') extends HtmlToCarve {
        };
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->convertToAstWithReport($source),
            self::source('shared-wrapper', 2048),
            self::source('shared-wrapper', 8192),
            'inherited shared-wrapper',
            2048,
            8192,
            maxPerByteRatio: 2.0,
        );
    }

    #[Group('scaling')]
    #[DataProvider('scalingShapes')]
    public function testFanoutImportsNearLinearly(string $shape, int $small, int $large): void
    {
        $converter = new HtmlToCarve(importAdapter: 'word');
        $this->assertConversionScalesLinearly(
            static fn (string $source) => $converter->convertToAstWithReport($source),
            self::source($shape, $small),
            self::source($shape, $large),
            $shape,
            $small,
            $large,
            maxPerByteRatio: 2.0,
        );
    }
}

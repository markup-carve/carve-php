<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Parser;

use MarkupCarve\Carve\CarveConverter;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The corpus documents that pin PART 9 §24 C3, carried here until the pin moves.
 *
 * `tests/spec` predates markup-carve/carve#1781 and carve#1791: those rulings
 * re-cut existing documents of categories 419 and 422 and added category 423, so
 * the corpus run stays GREEN while the divergence is live
 * (markup-carve/carve-php#1783). A gate that cannot fail is worse than none, so
 * the documents are written out here byte for byte instead.
 *
 * THEY ARE COPIES WITH A LIFETIME. When the submodule reaches a spec carrying
 * these goldens, `CarveCorpusTest` covers them and this file is redundant - it
 * is not a second opinion about what they should say. The source and the
 * expected HTML are the spec's own bytes, taken at carve `6dac47e2`.
 */
final class TheInnermostContainerBaseCorpusTest extends TestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function corpusProvider(): array
    {
        return [
            '419-a-definition-list-inside-a-footnote-body-carries-its-authored-base-2' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n      > quote\n\nsee[^n]\n",
                "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <dl>\n        <dt>term</dt>\n        <dd>\n          <p>definition</p>\n          <blockquote><p>quote</p></blockquote>\n        </dd>\n      </dl>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            '419-a-definition-list-inside-a-footnote-body-carries-its-authored-base-3' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n\n     > quote\n\nsee[^n]\n",
                "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <dl>\n        <dt>term</dt>\n        <dd>definition</dd>\n      </dl>\n      <blockquote><p>quote</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            '419-a-definition-list-inside-a-footnote-body-carries-its-authored-base' => [
                "[^n]: intro\n\n  :: term\n  :  definition\n\n     > quote\n\nsee[^n]\n",
                "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <dl>\n        <dt>term</dt>\n        <dd>\n          <p>definition</p>\n          <blockquote><p>quote</p></blockquote>\n        </dd>\n      </dl>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            '422-a-recognized-opener-in-a-body-needs-no-blank-line-above-it-8' => [
                "- intro\n\n  :: term\n  :  definition\n   > quote\n",
                "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>definition</dd>\n    </dl>\n    <blockquote><p>quote</p></blockquote>\n  </li>\n</ul>\n",
            ],
            '422-a-recognized-opener-in-a-body-needs-no-blank-line-above-it-9' => [
                "[^n]: intro\n\n   :: term\n   :  definition\n    > quote\n\nsee[^n]\n",
                "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <dl>\n        <dt>term</dt>\n        <dd>definition</dd>\n      </dl>\n      <blockquote><p>quote</p></blockquote>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            '423-one-authored-base-rule-reaches-a-definition-nested-in-a-list-item-2' => [
                "[^n]: intro\n\n  - item\n\n    > quote\n\nsee[^n]\n",
                "<p>see<a id=\"fnref1\" href=\"#fn1\" role=\"doc-noteref\"><sup>1</sup></a></p>\n<section role=\"doc-endnotes\" aria-label=\"Footnotes\">\n  <hr>\n  <ol>\n    <li id=\"fn1\">\n      <p>intro</p>\n      <ul>\n        <li>item\n          <blockquote><p>quote</p></blockquote>\n        </li>\n      </ul>\n      <p><a href=\"#fnref1\" role=\"doc-backlink\" aria-label=\"Back to reference\">↩</a></p>\n    </li>\n  </ol>\n</section>\n",
            ],
            '423-one-authored-base-rule-reaches-a-definition-nested-in-a-list-item' => [
                "- intro\n\n   :: term\n   :  definition\n\n      > quote\n",
                "<ul>\n  <li>intro\n    <dl>\n      <dt>term</dt>\n      <dd>\n        <p>definition</p>\n        <blockquote><p>quote</p></blockquote>\n      </dd>\n    </dl>\n  </li>\n</ul>\n",
            ],
        ];
    }

    #[DataProvider('corpusProvider')]
    public function testTheDocumentRendersToItsGolden(string $source, string $expected): void
    {
        self::assertSame(trim($expected), trim((new CarveConverter())->convert($source)));
    }

    /**
     * The provider is READ, so the sweep above can fail.
     */
    public function testEveryPinnedDocumentIsRead(): void
    {
        self::assertGreaterThanOrEqual(6, count(self::corpusProvider()));
    }
}

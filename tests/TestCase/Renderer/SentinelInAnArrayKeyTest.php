<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Renderer;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Renderer\CarveRenderer;
use PHPUnit\Framework\TestCase;

/**
 * The sentinel scan reads array KEYS, not only values.
 *
 * carve-php#809 made the writer choose its verbatim sentinels from code points
 * the document does not contain, which fixed every code-block case. The walk it
 * added pushes array VALUES onto its stack and drops keys - and a document's
 * abbreviations are keyed by the TERM, which is written back out as
 * `*[term]: expansion`.
 *
 * So a term carrying one of the default sentinels was invisible to the picker,
 * the defaults were kept, and `restoreVerbatim()` rewrote the author's
 * character: U+E001 came back as a space. Same defect as carve#678, one
 * container in.
 *
 * A term like this cannot be PARSED - `abbreviation_term` is letters and digits
 * - so this is the API path, which the AST codec and every consumer of
 * `setAbbreviations()` use.
 */
class SentinelInAnArrayKeyTest extends TestCase
{
    public function testAnAbbreviationTermKeepsItsPrivateUseCharacter(): void
    {
        $term = 'A' . (string)mb_chr(0xE001, 'UTF-8');
        $document = (new CarveConverter())->parse("text\n");
        $document->setAbbreviations([$term => 'Expansion']);

        $out = (new CarveRenderer())->render($document);

        $this->assertStringContainsString($term, $out, 'the term lost its private-use character');
    }

    /**
     * The value side already worked, and must keep working: a fix that scanned
     * keys INSTEAD of values would pass the test above and break this one.
     */
    public function testAnExpansionKeepsItsPrivateUseCharacter(): void
    {
        $expansion = 'Hyper' . (string)mb_chr(0xE002, 'UTF-8') . 'Text';
        $document = (new CarveConverter())->parse("text\n");
        $document->setAbbreviations(['HTML' => $expansion]);

        $out = (new CarveRenderer())->render($document);

        $this->assertStringContainsString($expansion, $out, 'the expansion lost its private-use character');
    }
}

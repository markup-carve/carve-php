<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

use MarkupCarve\Carve\Converter\HtmlToCarve;
use MarkupCarve\Carve\Test\TestCase\ScalingGuardTrait;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Recognizing footnote-shaped HTML must not cost the document twice.
 *
 * The adapter pass pairs references with notes, and three of its steps started
 * out asking a question of every candidate about every OTHER candidate: which
 * candidate reads the same mutual pair from the other end, which block contains
 * another block, and whether an anchor sits inside a note. On a document that
 * is mostly notes each of those is quadratic, and the shape that exposes it is
 * exactly the one a real export has - one reference per note, every note in one
 * list. Measured before the fix at 800 notes: 0.603s against a 0.038s `generic`
 * baseline on the same document, growing 4x per doubling.
 *
 * The `generic` sample is not decoration. It is the control: it walks the same
 * document through the same importer with the adapter pass switched off, so a
 * reading that blames the pass has to survive the same document measuring
 * linear without it.
 *
 * Wall-clock, so it lives in the excluded `scaling` group with the other
 * guards; ScalingGuardTrait carries the calibration and why the ratio is per
 * input byte rather than per total.
 */
#[Group('scaling')]
class FootnoteAdapterScaleTest extends TestCase
{
    use ScalingGuardTrait;

    /**
     * One note is two blocks and an anchor pair, so the inline default of
     * 12500/50000 would build a document two orders of magnitude past what the
     * shape needs. 250/1000 keeps the same 4x multiple.
     *
     * @var int
     */
    private const NOTES = 250;

    /**
     * A reference per note in the body, and every note in one list - the shape
     * Pandoc, Word and Google Docs all produce.
     */
    private static function document(int $notes): string
    {
        $body = '';
        $definitions = '';
        for ($index = 1; $index <= $notes; $index++) {
            $body .= '<p>text ' . $index . '<a href="#fn' . $index . '" id="fnref' . $index . '">'
                . '<sup>' . $index . '</sup></a> tail.</p>' . "\n";
            $definitions .= '<li id="fn' . $index . '"><p>note ' . $index
                . '<a href="#fnref' . $index . '">back</a></p></li>' . "\n";
        }

        return $body . '<section class="footnotes"><hr /><ol>' . $definitions . '</ol></section>';
    }

    public function testTheAdapterPassScalesLinearly(): void
    {
        $converter = new HtmlToCarve(importAdapter: 'word');

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            self::document(self::NOTES),
            self::document(self::NOTES * 4),
            'footnote-shaped HTML under the word adapter',
            self::NOTES,
            self::NOTES * 4,
        );
    }

    public function testTheSameDocumentScalesLinearlyWithoutTheAdapter(): void
    {
        $converter = new HtmlToCarve();

        $this->assertConversionScalesLinearly(
            static function (string $input) use ($converter): void {
                $converter->convert($input);
            },
            self::document(self::NOTES),
            self::document(self::NOTES * 4),
            'the same document under the generic adapter',
            self::NOTES,
            self::NOTES * 4,
        );
    }
}

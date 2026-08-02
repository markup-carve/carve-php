<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A code fence's opener metadata did not survive the bridge (carve-php#519).
 *
 * Two losses with one cause. `getHeader()` is the quoted title as the OPENER
 * carries it, and the writer removes the matching `title` attribute when the
 * two agree - so losing the header made the title come back TWICE, once in the
 * opener and once as a stray attribute line that a re-parse reads as separate
 * content. `getLabel()` is the `[NPM]` a group extension reads as a tab name,
 * and nothing carried it at all.
 */
class CodeFenceMetadataTest extends TestCase
{
    protected function roundTrip(string $source): string
    {
        $proseMirror = (new ProseMirrorRenderer())->render((new CarveConverter())->parse($source));

        return CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($proseMirror));
    }

    protected function canonical(string $source): string
    {
        return CarveConverter::carve()->render((new CarveConverter())->parse($source));
    }

    public function testALabelSurvives(): void
    {
        $source = "``` php [NPM]\nnpm install x\n```\n";

        $this->assertSame($this->canonical($source), $this->roundTrip($source));
    }

    /**
     * The title must not come back twice. Asserting equality alone would pass a
     * writer that dropped it entirely, so the duplicate is named directly.
     */
    public function testAQuotedTitleIsNotDuplicatedAsAnAttributeLine(): void
    {
        $source = "``` php \"src/Auth.php\"\nok = true;\n```\n";
        $back = $this->roundTrip($source);

        $this->assertSame($this->canonical($source), $back);
        $this->assertStringNotContainsString('{title=', $back, 'the title was emitted twice');
    }

    public function testATitleAndALabelTogetherBothSurvive(): void
    {
        $source = "``` php \"src/Auth.php\" [Composer]\ncomposer require x\n```\n";

        $this->assertSame($this->canonical($source), $this->roundTrip($source));
    }

    /**
     * A fence with no language still carries its title.
     */
    public function testATitleWithoutALanguageSurvives(): void
    {
        $source = "``` \"notes.txt\"\nremember the milk\n```\n";

        $this->assertSame($this->canonical($source), $this->roundTrip($source));
    }

    /**
     * A plain fence gains neither key - an editor reading every code block
     * should not meet attributes that mean nothing.
     */
    public function testAPlainFenceCarriesNeitherKey(): void
    {
        $proseMirror = (new ProseMirrorRenderer())->render(
            (new CarveConverter())->parse("``` php\nx\n```\n"),
        );
        $json = json_encode($proseMirror, JSON_THROW_ON_ERROR);

        $this->assertStringNotContainsString('carveHeader', $json);
        $this->assertStringNotContainsString('carveLabel', $json);
    }
}

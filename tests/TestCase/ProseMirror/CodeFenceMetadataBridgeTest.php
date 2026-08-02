<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\ProseMirror;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\ProseMirror\ProseMirrorRenderer;
use MarkupCarve\Carve\ProseMirror\ProseMirrorToCarve;
use PHPUnit\Framework\TestCase;

/**
 * A code fence's own metadata survives the ProseMirror bridge (carve-php#519).
 *
 * The fence's title and label are the CONSTRUCT's, not author attributes, and
 * conflating the two lost them in both directions: a `[label]` had nothing
 * carrying it, and a quoted title arrived as a plain `title` attribute, so the
 * writer emitted it twice - once as the fence title and once as a `{title=...}`
 * attribute line the author never wrote.
 *
 * These assert on canonical Carve rather than HTML: core ignores a fence label,
 * so the rendering is identical either way and an HTML comparison cannot see
 * the loss at all.
 */
class CodeFenceMetadataBridgeTest extends TestCase
{
    protected function roundTrip(string $carve): string
    {
        $document = (new CarveConverter())->parse($carve);
        $payload = (new ProseMirrorRenderer())->render($document);

        return CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($payload));
    }

    protected function assertRoundTrips(string $carve): void
    {
        $this->assertSame(
            trim(CarveConverter::carve()->render((new CarveConverter())->parse($carve))),
            trim($this->roundTrip($carve)),
        );
    }

    public function testAFenceLabelSurvives(): void
    {
        $this->assertRoundTrips("``` php [NPM]\nnpm install x\n```\n");
    }

    public function testAFenceTitleIsNotAlsoEmittedAsAnAttributeLine(): void
    {
        $carve = "``` php \"src/Auth.php\"\nok\n```\n";

        $this->assertStringNotContainsString('{title=', $this->roundTrip($carve));
        $this->assertRoundTrips($carve);
    }

    public function testATitleAndALabelTogetherBothSurvive(): void
    {
        $this->assertRoundTrips("``` php \"src/Auth.php\" [Composer]\ncomposer require x\n```\n");
    }

    /**
     * Two titles on purpose: one from an attribute line, one from the fence.
     * The author's must stay an attribute rather than overwrite the fence's -
     * getting this wrong replaced the header and produced a different document.
     */
    public function testAnAuthorTitleAttributeDoesNotOverwriteTheFenceTitle(): void
    {
        $this->assertRoundTrips(
            "{title=\"from the attribute line\"}\n``` php \"from the header\"\ncode\n```\n",
        );
    }

    public function testAPlainFenceIsUnchanged(): void
    {
        $this->assertRoundTrips("``` php\ncode\n```\n");
    }

    /**
     * A payload predating carveFenceTitle put the fence's title in `title`;
     * that is still the best guess available, so it is honored when the
     * explicit key is absent.
     */
    public function testALegacyPayloadStillRestoresTheFenceTitle(): void
    {
        $payload = [
            'type' => 'doc',
            'content' => [
                [
                    'type' => 'codeBlock',
                    'attrs' => ['language' => 'php', 'title' => 'src/Auth.php'],
                    'content' => [['type' => 'text', 'text' => 'code']],
                ],
            ],
        ];

        $written = CarveConverter::carve()->render((new ProseMirrorToCarve())->convert($payload));

        $this->assertStringContainsString('"src/Auth.php"', $written);
        $this->assertStringNotContainsString('{title=', $written);
    }
}

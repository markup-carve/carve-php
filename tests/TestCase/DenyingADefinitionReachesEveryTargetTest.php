<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Profile;
use MarkupCarve\Carve\Renderer\AnsiRenderer;
use MarkupCarve\Carve\Renderer\MarkdownRenderer;
use MarkupCarve\Carve\Renderer\PlainTextRenderer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Denying a definition reaches every target, not just HTML.
 *
 * `docs/profiles.md` names the two definition kinds as one case, and this engine
 * treated them differently: `abbreviation_def` sat in `NON_DENIABLE_TYPES`, so a
 * host denying it got no error, no violation and the definition line anyway on
 * markdown, plain and ansi (carve-php#858). carve-js and carve-rs both drop it.
 *
 * The docblock beside that list already carried the argument against the entry:
 * `frontmatter` used to sit there on the same "renders nothing" reasoning and was
 * removed because the spec's vocabulary names it, so a profile CAN name it and
 * "keeping it here meant a host denying frontmatter was silently ignored". The
 * AST schema declares `abbreviation_def` too.
 *
 * WHAT MUST SURVIVE THE DENY is the expansion. profiles.md is explicit: denying
 * the definition denies the definition, and the inline `abbreviation` it feeds is
 * a separate profile entry. Both other engines still emit `<abbr>` after the
 * deny, and so must this one - which is why the fix cannot simply clear the
 * abbreviation map.
 */
class DenyingADefinitionReachesEveryTargetTest extends TestCase
{
    protected string $source = "HTML is fine.\n\n*[HTML]: HyperText\n";

    protected function denying(string $action): Profile
    {
        return Profile::full()->denyBlock(['abbreviation_def'])->onDisallowed($action);
    }

    public function testTheBaselineEmitsTheDefinitionLine(): void
    {
        // Without this the assertions below could pass because the line is never
        // emitted at all, which is a different engine and a green test.
        $this->assertStringContainsString(
            '*[HTML]: HyperText',
            CarveConverter::markdown()->convert($this->source),
        );
        $this->assertStringContainsString(
            '*[HTML]: HyperText',
            CarveConverter::plainText()->convert($this->source),
        );
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function actionProvider(): array
    {
        return [
            'strip' => [Profile::ACTION_STRIP],
            'to_text' => [Profile::ACTION_TO_TEXT],
        ];
    }

    #[DataProvider('actionProvider')]
    public function testTheMarkdownTargetDropsTheDefinitionLine(string $action): void
    {
        $out = CarveConverter::create(renderer: new MarkdownRenderer(), profile: $this->denying($action))
            ->convert($this->source);

        $this->assertStringNotContainsString('*[HTML]:', $out);
    }

    #[DataProvider('actionProvider')]
    public function testThePlainTargetDropsTheDefinitionLine(string $action): void
    {
        $out = CarveConverter::create(renderer: new PlainTextRenderer(), profile: $this->denying($action))
            ->convert($this->source);

        $this->assertStringNotContainsString('*[HTML]:', $out);
    }

    #[DataProvider('actionProvider')]
    public function testTheAnsiTargetDropsTheDefinitionLine(string $action): void
    {
        $out = CarveConverter::create(renderer: new AnsiRenderer(), profile: $this->denying($action))
            ->convert($this->source);

        $this->assertStringNotContainsString('*[HTML]:', $out);
    }

    #[DataProvider('actionProvider')]
    public function testTheExpansionSurvivesTheDeny(string $action): void
    {
        // The half that must NOT change: the inline abbreviation is its own
        // profile entry, and both other engines keep expanding after this deny.
        $html = CarveConverter::create(profile: $this->denying($action))->convert($this->source);
        $this->assertStringContainsString('<abbr title="HyperText">HTML</abbr>', $html);

        $markdown = CarveConverter::create(renderer: new MarkdownRenderer(), profile: $this->denying($action))
            ->convert($this->source);
        $this->assertStringContainsString('<abbr title="HyperText">HTML</abbr>', $markdown);
    }

    #[DataProvider('actionProvider')]
    public function testDenyingTheInlineIsASeparateDecision(string $action): void
    {
        // Denying the definition must not take the inline with it, and denying
        // the inline must not need the definition denied.
        $inlineDenied = CarveConverter::create(
            profile: Profile::full()->denyInline(['abbreviation'])->onDisallowed($action),
        )->convert($this->source);

        $this->assertStringNotContainsString('<abbr', $inlineDenied);
        // Measured against carve-js, which answers per ACTION: strip takes the
        // node and its text with it, to_text leaves the word behind. This engine
        // already agreed on both; the assertion is here so a fix to the
        // definition half cannot quietly change the inline half.
        if ($action === Profile::ACTION_STRIP) {
            $this->assertSame("<p> is fine.</p>\n", $inlineDenied);
        } else {
            $this->assertStringContainsString('HTML is fine.', $inlineDenied);
        }
    }
}

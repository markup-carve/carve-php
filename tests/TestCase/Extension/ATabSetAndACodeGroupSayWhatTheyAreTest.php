<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Extension;

use MarkupCarve\Carve\CarveConverter;
use MarkupCarve\Carve\Extension\CodeGroupExtension;
use MarkupCarve\Carve\Extension\TabsExtension;
use MarkupCarve\Carve\Renderer\HtmlRenderer;
use MarkupCarve\Carve\Renderer\RenderMode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Each tab of a tab set was already named by its own `<label>`; the SET was
 * anonymous, so a reader could hear the parts and never the thing they belong
 * to (markup-carve/carve#1468). The wrapper now carries a `role` and an
 * accessible name together - a role with no name is a grouping a reader cannot
 * identify.
 *
 * The name resolves by the Extensions §1.5 ladder: an `aria-label` or
 * `aria-labelledby` the AUTHOR wrote on the block wins, then the extension's
 * own `groupLabel` option, then the render's `labels` map under `tabsGroup` /
 * `codeGroup`, then `Tabs` / `Code examples`. One `labels` map localizes a
 * whole document, which is the point of §16a - a host must not have to
 * configure the same string twice.
 *
 * Both attributes are APPENDED, so naming the set never moves an attribute the
 * author placed.
 *
 * Measured against carve-js `d47a5795`, which ships the same ladder.
 */
class ATabSetAndACodeGroupSayWhatTheyAreTest extends TestCase
{
    /**
     * @var string
     */
    protected const TABS = ":::: tabs\n::: tab [A]\nx\n:::\n::::\n";

    /**
     * @var string
     */
    protected const CODE_GROUP = "::: code-group\n``` php [Install]\ncomposer require x\n```\n:::\n";

    /**
     * The first line of the render - the wrapper's open tag.
     *
     * @param string $source
     * @param array<\MarkupCarve\Carve\Extension\ExtensionInterface> $extensions
     * @param array<string, string> $labels
     * @param string|null $mode A `RenderMode` value, or null for the default.
     */
    protected function wrapper(string $source, array $extensions, array $labels = [], ?string $mode = null): string
    {
        $converter = $mode !== null
            ? new CarveConverter(labels: $labels, mode: $mode)
            : new CarveConverter(labels: $labels);
        foreach ($extensions as $extension) {
            $converter->addExtension($extension);
        }

        return explode("\n", $converter->convert($source))[0];
    }

    public function testATabSetNamesItself(): void
    {
        $this->assertSame(
            '<div class="tabs" role="group" aria-label="Tabs">',
            $this->wrapper(self::TABS, [new TabsExtension()]),
        );
    }

    public function testACodeGroupNamesItself(): void
    {
        $this->assertSame(
            '<div class="code-group" role="group" aria-label="Code examples">',
            $this->wrapper(self::CODE_GROUP, [new CodeGroupExtension()]),
        );
    }

    public function testTheLabelsMapCarriesBothNames(): void
    {
        $this->assertSame(
            '<div class="tabs" role="group" aria-label="Reiter">',
            $this->wrapper(self::TABS, [new TabsExtension()], ['tabsGroup' => 'Reiter']),
        );
        $this->assertSame(
            '<div class="code-group" role="group" aria-label="Beispiele">',
            $this->wrapper(self::CODE_GROUP, [new CodeGroupExtension()], ['codeGroup' => 'Beispiele']),
        );
    }

    public function testTheExtensionOptionWinsOverTheMap(): void
    {
        $this->assertSame(
            '<div class="tabs" role="group" aria-label="Chooser">',
            $this->wrapper(self::TABS, [new TabsExtension(groupLabel: 'Chooser')], ['tabsGroup' => 'Reiter']),
        );
        $this->assertSame(
            '<div class="code-group" role="group" aria-label="Snippets">',
            $this->wrapper(self::CODE_GROUP, [new CodeGroupExtension(groupLabel: 'Snippets')], ['codeGroup' => 'Beispiele']),
        );
    }

    /**
     * An empty name is a host saying "do not name this", not a host forgetting
     * to. The role still stands - it says what the element is - but an
     * `aria-label=""` would be a name that announces nothing.
     */
    public function testAnEmptyNameWritesTheRoleAlone(): void
    {
        $this->assertSame(
            '<div class="tabs" role="group">',
            $this->wrapper(self::TABS, [new TabsExtension(groupLabel: '')]),
        );
        $this->assertSame(
            '<div class="code-group" role="group">',
            $this->wrapper(self::CODE_GROUP, [new CodeGroupExtension(groupLabel: '')]),
        );
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function authoredNameProvider(): array
    {
        return [
            'aria-label' => ['{aria-label="Choose"}', '<div aria-label="Choose" class="tabs" role="group">'],
            'aria-labelledby' => ['{aria-labelledby="h"}', '<div aria-labelledby="h" class="tabs" role="group">'],
            // HTML attribute names are ASCII-case-insensitive, so the check is.
            'ARIA-LABEL' => ['{ARIA-LABEL="Up"}', '<div ARIA-LABEL="Up" class="tabs" role="group">'],
        ];
    }

    #[DataProvider('authoredNameProvider')]
    public function testAnAuthoredNameIsNeverJoinedBySecondOne(string $attributes, string $expected): void
    {
        $wrapper = $this->wrapper($attributes . "\n" . self::TABS, [new TabsExtension()]);

        $this->assertSame($expected, $wrapper);
        $this->assertSame(1, substr_count(strtolower($wrapper), 'aria-label'));
    }

    /**
     * The author's role is the more specific statement, so it stands - and the
     * name is still written, because a role the author chose still needs one.
     */
    public function testAnAuthoredRoleStandsAndStillGetsAName(): void
    {
        $this->assertSame(
            '<div role="region" class="tabs" aria-label="Tabs">',
            $this->wrapper('{role="region"}' . "\n" . self::TABS, [new TabsExtension()]),
        );
    }

    /**
     * ARIA mode claims `tablist` - it has the tab/panel roles to associate.
     * The CSS mode has none, so `group` is all it can honestly claim.
     */
    public function testAriaModeKeepsItsTablistRoleAndTakesTheName(): void
    {
        $this->assertSame(
            '<div class="tabs" role="tablist" aria-label="Tabs">',
            $this->wrapper(self::TABS, [new TabsExtension(mode: TabsExtension::MODE_ARIA)]),
        );
    }

    /**
     * A static render has no radios at all, so the group name is the only thing
     * telling a reader what the sections belong to.
     */
    public function testAStaticRenderNamesTheGroupToo(): void
    {
        $this->assertSame(
            '<div class="tabs" role="group" aria-label="Tabs">',
            $this->wrapper(self::TABS, [new TabsExtension()], [], RenderMode::STATIC),
        );
        $this->assertSame(
            '<div class="code-group" role="group" aria-label="Code examples">',
            $this->wrapper(self::CODE_GROUP, [new CodeGroupExtension()], [], RenderMode::STATIC),
        );
    }

    /**
     * The keys live in the ONE map the whole document reads, beside the core
     * strings, rather than each extension carrying its own.
     */
    public function testTheKeysLiveInTheSharedLabelMap(): void
    {
        $this->assertArrayHasKey('tabsGroup', HtmlRenderer::LABEL_DEFAULTS);
        $this->assertArrayHasKey('codeGroup', HtmlRenderer::LABEL_DEFAULTS);
        $this->assertSame('Tabs', HtmlRenderer::LABEL_DEFAULTS['tabsGroup']);
        $this->assertSame('Code examples', HtmlRenderer::LABEL_DEFAULTS['codeGroup']);
    }
}

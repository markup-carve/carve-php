<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Extension;

use Closure;
use MarkupCarve\Carve\Node;
use MarkupCarve\Carve\Resolve\PlainText;

/**
 * The named standard matchers in the 0.2 extension catalog.
 */
final class Standard
{
    /**
     * @var string
     */
    private const FENCED = 'carve.fencedRender.';

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * @return array<\MarkupCarve\Carve\Extension\ExtensionDefinition>|null
     */
    public static function definitions(string $name): ?array
    {
        return match ($name) {
            'carve.tabs' => Tabs::extensions(),
            'carve.tab' => [Tabs::extensions()[0]],
            'carve.wiki' => [
                new ExtensionDefinition(
                    'carve.wiki',
                    '1', matcher: ['form' => 'inline', 'name' => 'wiki'], transform: self::inline(...),
                ),
            ],
            'carve.cite' => [Citations::citeDefinition()],
            'carve.codeGroup' => CodeGroup::bundle()->withoutRenderers()->definitions(),
            'carve.details' => Details::bundle()->withoutRenderers()->definitions(),
            'carve.mathBlock' => MathBlock::bundle()->withoutRenderers()->definitions(),
            'carve.spoiler', 'carve.spoilerBlock' => array_values(array_filter(
                Spoiler::bundle()->withoutRenderers()->definitions(),
                static fn (ExtensionDefinition $definition): bool => $definition->name === $name,
            )),
            'carve.imgFence.img' => [ImgFence::definition('img')],
            'carve.imgFence.image' => [ImgFence::definition('image')],
            'carve.colorSwatch' => ColorSwatch::bundle()->withoutRenderers()->definitions(),
            'carve.listTable' => ListTable::bundle()->withoutRenderers()->definitions(),
            'carve.term' => [Glossary::termDefinition()->withoutRenderers()],
            // C2-X-072: the glossary and the index register their inline matcher with them.
            'carve.glossary' => [
                Glossary::termDefinition()->withoutRenderers(),
                new ExtensionDefinition(Glossary::GLOSSARY, '1', ['body'], documentTransform: Glossary::transform(...)),
            ],
            'carve.indexTerm' => [IndexTerms::markerDefinition()->withoutRenderers()],
            'carve.index' => [
                IndexTerms::markerDefinition()->withoutRenderers(),
                new ExtensionDefinition(
                    IndexTerms::INDEX,
                    '1',
                    ['body'],
                    documentTransform: static fn (Node $view): Node => IndexTerms::transform($view, 'Back to'),
                ),
            ],
            'carve.codeCallouts' => [
                ...CodeCallouts::bundle()->withoutRenderers()->definitions(),
                new ExtensionDefinition($name, '1', documentTransform: CodeCallouts::transform(...)),
            ],
            'carve.tabNormalize' => [
                new ExtensionDefinition($name, '1', documentTransform: Transforms::tabNormalize()->transforms()[0]->run),
            ],
            'carve.autolink' => [Navigation::autolink()],
            'carve.externalLinks' => [Navigation::externalLinks()],
            'carve.headingNumbers' => [Navigation::headingNumbers()],
            'carve.headingPermalinks' => [Navigation::headingPermalinks()],
            'carve.headingReference' => [Navigation::headingReference()],
            'carve.semanticSpan' => SemanticSpan::bundle()->definitions(),
            'carve.smartQuotes' => SmartQuotes::bundle()->definitions(),
            'carve.toc' => [Navigation::tableOfContents()],
            'carve.tocPlacement' => [Navigation::tocPlacement()],
            default => str_starts_with($name, self::FENCED) ? self::fenced($name) : null,
        };
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-X-071: one catalog row per diagram fence language.
     *
     * @return array<\MarkupCarve\Carve\Extension\ExtensionDefinition>|null
     */
    private static function fenced(string $name): ?array
    {
        foreach (FencedRender::presets()->definitions() as $definition) {
            if ($definition->name === $name) {
                return [$definition->withoutRenderers()];
            }
        }

        return null;
    }

    public static function renderer(string $name, string $mode, string $tabsMode = 'css'): ?Closure
    {
        $direct = match ($name) {
            'carve.tabs' => $mode === 'static' ? Tabs::renderStatic(...)
                : ($tabsMode === 'aria' ? Tabs::renderAria(...) : Tabs::render(...)),
            default => null,
        };
        if ($direct !== null) {
            return $direct;
        }
        foreach (self::rendererBundle($name, $tabsMode)?->definitions() ?? [] as $definition) {
            if ($definition->name === $name) {
                return $mode === 'static' ? $definition->buildTimeRenderer : $definition->renderer;
            }
        }

        return null;
    }

    /**
     * The row whose renderer choice a node without a row of its own follows, as `carve.codeCallouts` for
     * `carve.codeCallouts.code` (C2-X-072).
     */
    public static function row(string $name): ?string
    {
        $dot = strrpos($name, '.');
        if (self::definitions($name) !== null || $dot === false) {
            return null;
        }
        $row = substr($name, 0, $dot);

        return self::definitions($row) !== null ? $row : null;
    }

    /**
     * Whether `'standard'` names a renderer for the row: its own, or that of a node it registers.
     */
    public static function rendered(string $name): bool
    {
        if (self::renderer($name, 'interactive') !== null) {
            return true;
        }
        foreach (self::definitions($name) ?? [] as $definition) {
            if (self::renderer($definition->name, 'interactive') !== null) {
                return true;
            }
        }

        return false;
    }

    // phpcs:disable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    private static function rendererBundle(string $name, string $standardMode): ?Bundle
    {
        return match ($name) {
            'carve.imgFence.img', 'carve.imgFence.image' => ImgFence::bundle(),
            'carve.codeGroup' => CodeGroup::bundle($standardMode),
            'carve.details' => Details::bundle(),
            'carve.spoiler', 'carve.spoilerBlock' => Spoiler::bundle(),
            'carve.codeCallouts.code', 'carve.codeCallouts.list' => CodeCallouts::bundle(),
            'carve.colorSwatch' => ColorSwatch::bundle(),
            'carve.listTable' => ListTable::bundle(),
            'carve.term', 'carve.glossary' => Glossary::bundle(),
            'carve.indexTerm', 'carve.index' => IndexTerms::bundle(),
            'carve.toc' => new Bundle([
                Toc::tableOfContents(minLevel: 1, maxLevel: 6, ordered: false, cssClass: 'toc', bottom: false, props: []),
            ]),
            'carve.tocPlacement' => new Bundle([Toc::placement()]),
            'carve.mathBlock' => MathBlock::bundle(),
            'carve.wiki' => Wikilinks::bundle(),
            default => str_starts_with($name, self::FENCED) ? FencedRender::presets() : null,
        };
    }

    // phpcs:enable Generic.Metrics.CyclomaticComplexity.MaxExceeded

    /**
     * C2-X-057: the plain text of the parsed content is the inline fallback.
     */
    public static function inline(Claim $claim): Node
    {
        $node = new Node('inline_extension', []);
        $node->f['name'] = 'carve.' . $claim->name;
        $node->f['version'] = '1';
        $text = new Node('text');
        $plain = PlainText::of($claim->node->children ?? []);
        $bar = $claim->name === 'wiki' ? strpos($plain, '|') : false;
        $node->f['props'] = ['target' => $bar === false ? $plain : substr($plain, 0, $bar)];
        $text->f['value'] = $bar === false ? $plain : substr($plain, $bar + 1);
        $node->f['fallback'] = [$text];

        return $node;
    }
}

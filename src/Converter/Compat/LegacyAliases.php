<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Compat;

// Resolve class paths from the latest tagged release when older consumers load them.
$legacyClasses = [
    'MarkupCarve\\Carve\\Converter\\BbcodeMark' => 'MarkupCarve\\Carve\\Converter\\Bbcode\\Mark',
    'MarkupCarve\\Carve\\Converter\\DjotEmbeddedListStack' => 'MarkupCarve\\Carve\\Converter\\Djot\\EmbeddedListStack',
    'MarkupCarve\\Carve\\Converter\\DjotEmphasis' => 'MarkupCarve\\Carve\\Converter\\Djot\\Emphasis',
    'MarkupCarve\\Carve\\Converter\\DjotEmphasisRenderer' => 'MarkupCarve\\Carve\\Converter\\Djot\\EmphasisRenderer',
    'MarkupCarve\\Carve\\Converter\\DjotEmphasisSpan' => 'MarkupCarve\\Carve\\Converter\\Djot\\EmphasisSpan',
    'MarkupCarve\\Carve\\Converter\\DjotHeadingTargets' => 'MarkupCarve\\Carve\\Converter\\Djot\\HeadingTargets',
    'MarkupCarve\\Carve\\Converter\\DjotImageChildTextRenderer' => 'MarkupCarve\\Carve\\Converter\\Djot\\ImageChildTextRenderer',
    'MarkupCarve\\Carve\\Converter\\DjotListContext' => 'MarkupCarve\\Carve\\Converter\\Djot\\ListContext',
    'MarkupCarve\\Carve\\Converter\\DjotNoteContext' => 'MarkupCarve\\Carve\\Converter\\Djot\\NoteContext',
    'MarkupCarve\\Carve\\Converter\\DjotNoteStack' => 'MarkupCarve\\Carve\\Converter\\Djot\\NoteStack',
    'MarkupCarve\\Carve\\Converter\\DjotPlaceholderPrefix' => 'MarkupCarve\\Carve\\Converter\\Djot\\PlaceholderPrefix',
    'MarkupCarve\\Carve\\Converter\\DjotTightDefinitionDescriptions' => 'MarkupCarve\\Carve\\Converter\\Djot\\TightDefinitionDescriptions',
    'MarkupCarve\\Carve\\Converter\\HtmlAttributePolicy' => 'MarkupCarve\\Carve\\Converter\\Html\\AttributePolicy',
    'MarkupCarve\\Carve\\Converter\\HtmlCarrierNames' => 'MarkupCarve\\Carve\\Converter\\Html\\CarrierNames',
    'MarkupCarve\\Carve\\Converter\\HtmlDomLoader' => 'MarkupCarve\\Carve\\Converter\\Html\\DomLoader',
    'MarkupCarve\\Carve\\Converter\\HtmlImportNodes' => 'MarkupCarve\\Carve\\Converter\\Html\\ImportNodes',
    'MarkupCarve\\Carve\\Converter\\HtmlImportSession' => 'MarkupCarve\\Carve\\Converter\\Html\\ImportSession',
    'MarkupCarve\\Carve\\Converter\\HtmlImportedAttributes' => 'MarkupCarve\\Carve\\Converter\\Html\\ImportedAttributes',
    'MarkupCarve\\Carve\\Converter\\HtmlMathTex' => 'MarkupCarve\\Carve\\Converter\\Html\\MathTex',
    'MarkupCarve\\Carve\\Converter\\HtmlPrecedingLabels' => 'MarkupCarve\\Carve\\Converter\\Html\\PrecedingLabels',
    'MarkupCarve\\Carve\\Converter\\HtmlTableStructure' => 'MarkupCarve\\Carve\\Converter\\Html\\TableStructure',
    'MarkupCarve\\Carve\\Converter\\MarkdownAssessment' => 'MarkupCarve\\Carve\\Converter\\Markdown\\Assessment',
    'MarkupCarve\\Carve\\Converter\\MarkdownBareLinks' => 'MarkupCarve\\Carve\\Converter\\Markdown\\BareLinks',
    'MarkupCarve\\Carve\\Converter\\MarkdownCarrierDefinition' => 'MarkupCarve\\Carve\\Converter\\Markdown\\CarrierDefinition',
    'MarkupCarve\\Carve\\Converter\\MarkdownDelimiterRun' => 'MarkupCarve\\Carve\\Converter\\Markdown\\DelimiterRun',
    'MarkupCarve\\Carve\\Converter\\MarkdownEmailText' => 'MarkupCarve\\Carve\\Converter\\Markdown\\EmailText',
    'MarkupCarve\\Carve\\Converter\\MarkdownEmphasis' => 'MarkupCarve\\Carve\\Converter\\Markdown\\Emphasis',
    'MarkupCarve\\Carve\\Converter\\MarkdownListMarkers' => 'MarkupCarve\\Carve\\Converter\\Markdown\\ListMarkers',
    'MarkupCarve\\Carve\\Converter\\MarkdownReferenceDefinition' => 'MarkupCarve\\Carve\\Converter\\Markdown\\ReferenceDefinition',
];

spl_autoload_register(static function (string $class) use ($legacyClasses): void {
    if (isset($legacyClasses[$class])) {
        class_alias($legacyClasses[$class], $class);
    }
});

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

use MarkupCarve\Carve\Renderer\CarveRenderer;

final class InlineContext
{
    /**
     * @var array<string, string|null>
     */
    public array $authoredDestinations = [];

    public bool $breakableCodeSlot = false;

    public bool $nativeCodeSlot = false;

    public bool $flattenedEmphasis = false;

    public bool $convertAbbreviations = true;

    public bool $convertAttributes = false;

    public bool $convertFencedDivs = false;

    public bool $convertHighlight = false;

    public bool $convertInlineFootnotes = false;

    public bool $convertMath = false;

    public bool $convertRawHtml = false;

    public ?int $inlineRunSourceLine = null;

    public bool $inlineRunContinuesPrevious = false;

    /**
     * @var array<string, true>|null
     */
    public ?array $resolvedCrossrefs = null;

    public CarveRenderer $importWriter;

    /**
     * @var array<string, true>
     */
    public array $authoredReferenceLabelKeys = [];

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $codeFallbackDiagnostics = [];

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $flattenedAnchorDiagnostics = [];

    /**
     * @var array<string, string>
     */
    public array $complexReferenceTargets = [];

    /**
     * @var array<string, true>
     */
    public array $definedReferenceLabels = [];

    /**
     * Normalized labels whose first definition has an empty destination,
     * mapped to its decoded title and whether the title was present.
     *
     * @var array<string, array{title: string, hasTitle: bool}>
     */
    public array $emptyDestinationLabels = [];

    /**
     * @var array<string, list<array{offset: int, line: int}>>
     */
    public array $foldedHeadingSources = [];

    /**
     * @var array<string, string>
     */
    public array $importedFootnoteLabels = [];

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $leadingWhitespaceDiagnostics = [];

    /**
     * @var list<string>
     */
    public array $markdownFootnoteLabels = [];

    /**
     * @var list<bool>
     */
    public array $pointyContinuationStrip = [];

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $rawSpanWhitespaceDiagnostics = [];

    /**
     * @var array<string, string>
     */
    public array $referenceDefinitionLabels = [];
}

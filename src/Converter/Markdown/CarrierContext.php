<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\Markdown;

final class CarrierContext
{
    /**
     * @var list<array{payload: string, depth: int, closer: bool, caption: bool, fence: bool, fenceTightAfter: bool, style: bool, styleTightAfter: bool, styleBlankBeforeOuter: bool, equation: string|null}>
     */
    public array $carrierSlots = [];

    /**
     * @var array<string, string>
     */
    public array $carrierLiteralText = [];

    /**
     * @var array<int, true>
     */
    public array $carrierParagraphLines = [];

    public bool $carrierDamaged = false;

    public bool $carrierFallbackMode = false;

    /**
     * @var list<\MarkupCarve\Carve\Converter\MigrationDiagnostic>
     */
    public array $carrierDiagnostics = [];

    public string $carrierToken = '';
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Parser;

/**
 * One source line as a definition description body reads it.
 *
 * @internal
 */
final class DescriptionBodyLine
{
    public bool $releasedMarkerEntry = false;

    public bool $noteReleased = false;

    /**
     * The last body entry as it stood when the line arrived.
     */
    public string $lastBody = '';

    public string $authoredLine = '';

    public int $indent = 0;

    public string $trimmedCont = '';

    public ?int $lastBodyKey = null;

    /**
     * @var array{type: string, marker: string, content: string, start?: int, checked?: bool, taskMarker?: string, style?: string, marker_indent?: int, ambiguous?: bool, alpha_start?: int, alpha_style?: string, bareMarker?: bool, attributes?: array<string, string|list<string>>, attributesWidth?: int}|null
     */
    public ?array $marker = null;

    public bool $belowDescriptionTextOpener = false;

    public bool $termPastTheColumn = false;

    public bool $definitionPastTheColumn = false;

    public bool $attributePastTheColumn = false;

    /**
     * @var array{fence: string, length: int, format: string}|array{fence: string, char: string, length: int, info: string, header: string|null, label: string|null, indent: string}|null
     */
    public ?array $paragraphFence = null;

    public bool $paragraphFenceHasNoCloser = false;

    public function __construct(
        public string $contLine,
        public int $contColumn,
    ) {
    }
}

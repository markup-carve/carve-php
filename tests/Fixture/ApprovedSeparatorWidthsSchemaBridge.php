<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\Fixture;

use PHPUnit\Framework\Assert;

/**
 * Approved carve#2919 field, staged before the shared schema is published.
 * Remove this bridge when the spec pin contains the field.
 */
final class ApprovedSeparatorWidthsSchemaBridge
{
    /**
     * @param array<string, mixed> $published
     *
     * @return array<string, mixed>
     */
    public static function schema(array $published): array
    {
        $old = $published['$defs']['table']['properties'];
        Assert::assertArrayNotHasKey('separatorWidths', $old, 'The spec pin has caught up with carve#2919; remove the approved-schema bridge.');
        $properties = [];
        foreach ($old as $name => $property) {
            $properties[$name] = $property;
            if ($name === 'rows') {
                $properties['separatorWidths'] = [
                    'type' => 'array',
                    'minItems' => 1,
                    'description' => 'Separator-row dash widths AS AUTHORED (PART 11 section 6h), one entry per separator cell, each the count of `-` characters that cell spelled with alignment colons excluded: `|:-----|---:|` records [5, 3]. PRESENCE records that the author wrote a separator row at all; absent means the header was spelled with `|=` cells, which is a different source form for the same rows and alignments. An author-choice field in the sense of PART 12 section 3, like `list.bulletChar` and `thematic_break.marker`: the canonical writer reproduces it. THE FIELD IS DELIBERATELY UNBOUNDED ABOVE: a parse records what the author typed, and nothing amplifies there. The 1000 bound in PART 10 section 13a is the HTML carrier\'s, where six characters of attribute would otherwise buy a megabyte of separator row, so do not add a `maximum` here thinking it was overlooked.',
                    'items' => ['type' => 'integer', 'minimum' => 1],
                ];
            }
        }
        $published['$defs']['table']['properties'] = $properties;

        return $published;
    }
}

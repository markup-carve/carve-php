<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Converter;

// Adjust Native Djot fixture HTML for the image-only paragraph ruling.
final class ImageParagraphFixture
{
    public static function normalized(string $html): string
    {
        return preg_replace_callback('/<p(\s[^>]*)?>\s*(<img\b[^>]*>)\s*<\/p>/', static function (array $match): string {
            if (!isset($match[1]) || $match[1] === '') {
                return $match[2];
            }

            return substr($match[2], 0, -1) . $match[1] . '>';
        }, $html) ?? $html;
    }
}

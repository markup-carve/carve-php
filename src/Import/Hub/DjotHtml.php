<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\DjotConverter;

/**
 * Djot to HTML with `php-collective/djot`; raw content of every format is carried as in Markdown.
 */
final class DjotHtml
{
    public static function html(string $input, Sink $sink): string
    {
        $parser = new DjotBlockParser();
        $renderer = (new DjotHtmlRenderer())->readReferencesOf($parser)->writeRawTo($sink)->setSections(false);
        $converter = new DjotConverter(parser: $parser, renderer: $renderer);

        return $converter->convert($input);
    }
}

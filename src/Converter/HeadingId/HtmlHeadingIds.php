<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Converter\HeadingId;

use DOMDocument;
use DOMElement;
use DOMXPath;
use function libxml_clear_errors;
use function libxml_use_internal_errors;
use function trim;

/**
 * Reads heading ids out of rendered HTML, in document order.
 *
 * Used both to scrape a published Djot page (see {@see RenderedHtmlIds}) and to
 * read back the ids a Carve render produced, so the migrator compares the live
 * id against what Carve would actually generate - never a re-derived slug, which
 * cannot account for custom slugging or extensions.
 */
final class HtmlHeadingIds
{
    /**
     * Every heading id in document order; '' for a heading with no id.
     *
     * The id is read from the `<h1>`..`<h6>` element, falling back to its
     * wrapping `<section>` - Carve and Djot both render `<section id="...">
     * <hN>...</hN></section>`, while flat / GitHub-style renderers put the id on
     * the heading itself. A permalink anchor child (`<a>` inside the heading) is
     * ignored - the id lives on the heading or its section, not the anchor.
     *
     * @return array<int, string>
     */
    public static function extract(string $html): array
    {
        if (trim($html) === '') {
            return [];
        }

        $dom = new DOMDocument();
        $previous = libxml_use_internal_errors(true);
        // Wrap in a UTF-8 container so a fragment (no <html>/<body>) parses and
        // multibyte ids survive. LIBXML flags suppress HTML5-tag warnings.
        $dom->loadHTML(
            '<?xml encoding="UTF-8"?><div>' . $html . '</div>',
            LIBXML_NOERROR | LIBXML_NOWARNING,
        );
        libxml_use_internal_errors($previous);
        libxml_clear_errors();

        $ids = [];
        $xpath = new DOMXPath($dom);
        // A union expression yields nodes in document order.
        $nodes = $xpath->query('//h1|//h2|//h3|//h4|//h5|//h6');
        if ($nodes !== false) {
            foreach ($nodes as $node) {
                if (!$node instanceof DOMElement) {
                    $ids[] = '';

                    continue;
                }
                $id = $node->getAttribute('id');
                $parent = $node->parentNode;
                if ($id === '' && $parent instanceof DOMElement && $parent->tagName === 'section') {
                    $id = $parent->getAttribute('id');
                }
                $ids[] = $id;
            }
        }

        return $ids;
    }
}

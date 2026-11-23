<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use Djot\DjotConverter;
use Djot\Event\RenderEvent;
use Djot\Node\Block\Heading;
use Djot\Node\Block\RawBlock;
use Djot\Node\Inline\RawInline;

/**
 * Djot to HTML with `php-collective/djot`; raw content of every format is carried as in Markdown.
 */
final class DjotHtml
{
    public static function html(string $input, Sink $sink): string
    {
        $renderer = (new DjotHtmlRenderer())->setSections(false);
        $converter = new DjotConverter(renderer: $renderer);
        $converter->on('render.raw_inline', static function (RenderEvent $event): void {
            $node = $event->getNode();
            if ($node instanceof RawInline) {
                $event->setHtml(Sink::rawInline($node->getFormat(), $node->getContent()));
                $event->preventDefault();
            }
        });
        $converter->on('render.raw_block', static function (RenderEvent $event) use ($sink): void {
            $node = $event->getNode();
            if ($node instanceof RawBlock) {
                $event->setHtml($sink->rawBlock($node->getFormat(), $node->getContent()) . "\n");
                $event->preventDefault();
            }
        });
        // Carve generates its own section ids, so only an authored id is written.
        $converter->on('render.heading', static function (RenderEvent $event) use ($renderer): void {
            $node = $event->getNode();
            if (!$node instanceof Heading) {
                return;
            }
            $level = $node->getLevel();
            $event->setHtml('<h' . $level . $renderer->renderAttributesExcluding($node, []) . '>' . $event->getChildrenHtml()
                . '</h' . $level . ">\n");
            $event->preventDefault();
        });

        return $converter->convert($input);
    }
}

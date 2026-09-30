<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Renderer;

final class RenderTarget
{
    /**
     * @var string
     */
    public const HTML = 'html';

    /**
     * @var string
     */
    public const MARKDOWN = 'markdown';

    /**
     * @var string
     */
    public const PLAIN = 'plain';

    /**
     * @var string
     */
    public const ANSI = 'ansi';

    /**
     * @var string
     */
    public const CARVE = 'carve';
}

<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Test\TestCase\Ast;

class ReplacementApplicationNode extends ApplicationNodeWithNodeShapedState
{
    protected string $marker = '';

    public function __construct()
    {
        parent::__construct();
        $this->marker = 'replacement';
    }
}

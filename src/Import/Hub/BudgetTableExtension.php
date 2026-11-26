<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Import\Hub;

use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Extension\ConfigurableExtensionInterface;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Extension\Table\TableCell;
use League\CommonMark\Extension\Table\TableCellRenderer;
use League\CommonMark\Extension\Table\TableExtension;
use League\CommonMark\Extension\Table\TableRenderer;
use League\CommonMark\Extension\Table\TableRow;
use League\CommonMark\Extension\Table\TableRowRenderer;
use League\CommonMark\Extension\Table\TableSection;
use League\CommonMark\Extension\Table\TableSectionRenderer;
use League\Config\ConfigurationBuilderInterface;

/**
 * league/commonmark's table extension with its rows checked against the inline budget. The hub sets no `table/wrap`.
 */
final class BudgetTableExtension implements ConfigurableExtensionInterface
{
    public function __construct(private InlineBudgetParser $budget)
    {
    }

    public function configureSchema(ConfigurationBuilderInterface $builder): void
    {
        (new TableExtension())->configureSchema($builder);
    }

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $config = $environment->getConfiguration();
        /** @var int $cells */
        $cells = $config->get('table/max_autocompleted_cells');
        /** @var array<'center'|'left'|'right', array<string, array<string>|bool|string>> $alignment */
        $alignment = $config->get('table/alignment_attributes');
        $environment
            ->addBlockStartParser(new TableBudgetStartParser($this->budget, $cells))
            ->addRenderer(Table::class, new TableRenderer())
            ->addRenderer(TableSection::class, new TableSectionRenderer())
            ->addRenderer(TableRow::class, new TableRowRenderer())
            ->addRenderer(TableCell::class, new TableCellRenderer($alignment));
    }
}

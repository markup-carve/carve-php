<?php

declare(strict_types=1);

namespace MarkupCarve\Carve\Writer;

use RuntimeException;

/**
 * Internal stop signal for a block group whose writer reparses exhausted C2-W-133.
 */
final class RepairRefused extends RuntimeException
{
}

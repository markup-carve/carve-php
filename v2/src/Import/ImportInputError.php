<?php

declare(strict_types=1);

namespace MarkupCarve\Carve2\Import;

use MarkupCarve\Carve2\CarveException;
use RuntimeException;

/**
 * C2-M-006: an input the importer refuses; the command line reports it as `import-input-invalid`.
 */
final class ImportInputError extends RuntimeException implements CarveException
{
}

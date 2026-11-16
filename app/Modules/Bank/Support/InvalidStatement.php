<?php

namespace App\Modules\Bank\Support;

use RuntimeException;

/**
 * The whole file is unusable (nothing can be imported from it), as opposed to a single bad row.
 */
class InvalidStatement extends RuntimeException {}

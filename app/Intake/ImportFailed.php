<?php

namespace App\Intake;

use RuntimeException;

/**
 * An import error whose message is the German text shown on the failed item (R6).
 * Never put tokens or Graph response text into it.
 */
class ImportFailed extends RuntimeException {}

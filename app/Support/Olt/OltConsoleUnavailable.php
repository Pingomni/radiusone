<?php

declare(strict_types=1);

namespace App\Support\Olt;

use RuntimeException;

/** The console service is not running here (not installed yet, or stopped): the portal falls back to its line terminal. */
final class OltConsoleUnavailable extends RuntimeException {}

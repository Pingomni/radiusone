<?php

declare(strict_types=1);

namespace App\Support;

/** Only raise when delivery is known not to have happened (e.g. pre-connection failure). */
final class NotificationRetry extends \RuntimeException {}

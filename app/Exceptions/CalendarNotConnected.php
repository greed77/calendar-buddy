<?php

namespace App\Exceptions;

use RuntimeException;

/** The user never granted calendar access, or Google revoked it. */
class CalendarNotConnected extends RuntimeException
{
}

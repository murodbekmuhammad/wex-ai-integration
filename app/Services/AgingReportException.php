<?php

namespace App\Services;

use RuntimeException;

/**
 * @class AgingReportException
 *
 * @package App\Services
 *
 * An aging report PDF couldn't be read reliably, e.g. its rows don't add up
 * to its grand total. The message is written for the user.
 */
class AgingReportException extends RuntimeException {}

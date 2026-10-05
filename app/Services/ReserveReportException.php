<?php

namespace App\Services;

use RuntimeException;

/**
 * @class ReserveReportException
 *
 * @package App\Services
 *
 * A reserve account detail PDF couldn't be read reliably, e.g. its rows
 * don't add up to its grand total. The message is written for the user.
 */
class ReserveReportException extends RuntimeException {}

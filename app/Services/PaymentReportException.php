<?php

namespace App\Services;

use RuntimeException;

/**
 * @class PaymentReportException
 *
 * @package App\Services
 *
 * A daily payment report PDF couldn't be read reliably, e.g. its rows don't
 * add up to its grand total. The message is written for the user.
 */
class PaymentReportException extends RuntimeException {}

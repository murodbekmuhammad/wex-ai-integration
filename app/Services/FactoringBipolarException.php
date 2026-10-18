<?php

namespace App\Services;

use RuntimeException;

/**
 * @class FactoringBipolarException
 *
 * @package App\Services
 *
 * The shared Google Sheet doesn't have the tabs the FACTORING-BIPOLAR tab is
 * built from, e.g. the payment report agent hasn't run yet. The message is
 * written for the user.
 */
class FactoringBipolarException extends RuntimeException {}

<?php

namespace App\Services;

use RuntimeException;

/**
 * @class TableBuildException
 *
 * @package App\Services
 *
 * Claude answered, but not with a usable table. The message is written for
 * the user.
 */
class TableBuildException extends RuntimeException {}

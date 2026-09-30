<?php

namespace App\Services;

use RuntimeException;

/**
 * @class AgentToolException
 *
 * @package App\Services
 *
 * An agent tool couldn't do what Claude asked. The message goes back to
 * Claude as the tool's error result, so it should say what to do instead.
 */
class AgentToolException extends RuntimeException {}

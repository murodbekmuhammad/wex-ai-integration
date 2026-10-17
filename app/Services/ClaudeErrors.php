<?php

namespace App\Services;

use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\RateLimitException;

/**
 * @class ClaudeErrors
 *
 * @package App\Services
 *
 * Turns failures from the Claude API into messages the user can act on.
 */
class ClaudeErrors
{
    /**
     * What the user is told when a run fails for a reason other than Claude.
     */
    public const UNEXPECTED = 'Something went wrong on our side. Please try again; if it keeps happening, let us know.';

    /**
     * describe
     *
     * A readable explanation of what went wrong.
     *
     * @param APIException $e
     * @return string
     */
    public static function describe(APIException $e): string
    {
        return match (true) {
            $e instanceof AuthenticationException => 'Claude rejected the API key. Check ANTHROPIC_API_KEY in .env.',
            $e instanceof RateLimitException => 'Claude is busy right now. Please try again in a minute.',
            $e instanceof APIConnectionException => 'Could not connect to Claude. Please try again.',
            str_contains($e->getMessage(), 'anthropic-workspace-id') => "This API key isn't tied to a workspace. Set ANTHROPIC_WORKSPACE_ID in .env, or use a key created inside a workspace.",
            (bool) preg_match('/Workspace `[^`]*` not found/', $e->getMessage()) => "ANTHROPIC_WORKSPACE_ID doesn't match a workspace in this API key's organization. Check the ID, or use a key created inside the workspace.",
            default => 'Claude returned an error: '.(data_get($e->body, 'error.message') ?: $e->getMessage()),
        };
    }

    /**
     * shouldReport
     *
     * Whether the failure is worth logging. A bad key or rate limiting is the
     * user's to fix and would only add noise.
     *
     * @param APIException $e
     * @return bool
     */
    public static function shouldReport(APIException $e): bool
    {
        return ! ($e instanceof AuthenticationException || $e instanceof RateLimitException);
    }

    /**
     * fatal
     *
     * The PHP error that ended the request, when it was fatal: one that
     * can't be caught, like running out of memory or time. Exceptions are
     * caught where they happen, so only these end a request this way.
     *
     * @return array{type: int, message: string, file: string, line: int}|null
     */
    public static function fatal(): ?array
    {
        $error = error_get_last();

        return $error && in_array($error['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_PARSE], true) ? $error : null;
    }

    /**
     * describeFatal
     *
     * A fatal PHP error (see fatal) as a message the user can act on.
     *
     * @param array{message: string} $error
     * @return string
     */
    public static function describeFatal(array $error): string
    {
        return match (true) {
            str_contains($error['message'], 'Allowed memory size') => 'The run ran out of memory on the server. Please try again; if it keeps happening, the server needs a higher PHP memory limit.',
            str_contains($error['message'], 'Maximum execution time') => 'The run took longer than the server allows. Please try again.',
            default => self::UNEXPECTED,
        };
    }
}

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
}

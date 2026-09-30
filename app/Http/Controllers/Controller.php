<?php

namespace App\Http\Controllers;

use Anthropic\Core\Exceptions\APIException;
use App\Services\ClaudeErrors;
use Closure;
use Illuminate\Http\JsonResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @class Controller
 *
 * @package App\Http\Controllers
 */
abstract class Controller
{
    /**
     * streamText
     *
     * Stream a generator of answer text back to the browser, turning Claude's
     * failures into a readable note at the end of the answer.
     *
     * @param Closure(): iterable<int, string> $answer
     * @param int|null $timeLimit seconds the answer may take; the usual Claude time limit when null
     * @return StreamedResponse
     */
    protected function streamText(Closure $answer, ?int $timeLimit = null): StreamedResponse
    {
        return response()->stream(function () use ($answer, $timeLimit) {
            set_time_limit($timeLimit ?? config('services.anthropic.time_limit'));

            try {
                foreach ($answer() as $text) {
                    echo $text;
                    if (ob_get_level() > 0) {
                        ob_flush();
                    }
                    flush();
                }
            } catch (APIException $e) {
                if (ClaudeErrors::shouldReport($e)) {
                    report($e);
                }

                echo "\n\n[".ClaudeErrors::describe($e).']';
            }
        }, 200, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * streamEvents
     *
     * Stream a generator of events back to the browser as newline-delimited
     * JSON, one event per line. A Claude failure becomes a final "error"
     * event.
     *
     * @param Closure(): iterable<int, array<string, mixed>> $events
     * @param int|null $timeLimit seconds the stream may take; the usual Claude time limit when null
     * @return StreamedResponse
     */
    protected function streamEvents(Closure $events, ?int $timeLimit = null): StreamedResponse
    {
        return response()->stream(function () use ($events, $timeLimit) {
            set_time_limit($timeLimit ?? config('services.anthropic.time_limit'));

            $send = function (array $event): void {
                echo json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n";
                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                foreach ($events() as $event) {
                    $send($event);
                }
            } catch (APIException $e) {
                if (ClaudeErrors::shouldReport($e)) {
                    report($e);
                }

                $send(['type' => 'error', 'text' => ClaudeErrors::describe($e)]);
            }
        }, 200, [
            'Content-Type' => 'application/x-ndjson; charset=utf-8',
            'Cache-Control' => 'no-cache',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    /**
     * notConfigured
     *
     * The answer when no Claude API key is set.
     *
     * @return JsonResponse
     */
    protected function notConfigured(): JsonResponse
    {
        return response()->json(['message' => 'Claude is not set up yet: add ANTHROPIC_API_KEY to .env.'], 503);
    }
}

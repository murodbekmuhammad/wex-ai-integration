<?php

namespace App\Http\Controllers;

use Anthropic\Core\Exceptions\APIException;
use App\Http\Requests\AnalyzePdfsRequest;
use App\Http\Requests\RunAgentRequest;
use App\Services\ClaudeErrors;
use App\Services\EmailAssistant;
use App\Services\PdfAnalyst;
use App\Services\ReportAgent;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @class AssistantController
 *
 * @package App\Http\Controllers
 */
class AssistantController extends Controller
{
    /**
     * __construct
     *
     * @param EmailAssistant $assistant
     * @param PdfAnalyst $analyst
     * @param ReportAgent $agent
     */
    public function __construct(private EmailAssistant $assistant, private PdfAnalyst $analyst, private ReportAgent $agent) {}

    /**
     * ask
     *
     * Ask Claude a question about the user's synced emails (optionally only
     * those from certain senders or sent to one receiver). The answer is
     * streamed back as plain text.
     *
     * @param Request $request
     * @return StreamedResponse|JsonResponse
     */
    public function ask(Request $request): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'question' => ['required', 'string', 'max:2000'],
            'senders' => ['nullable', 'array'],
            'senders.*' => ['string', 'max:255'],
            'receiver' => ['nullable', 'string', 'max:255'],
        ]);

        if (! config('services.anthropic.key')) {
            return $this->notConfigured();
        }

        $emails = $request->user()->emails()
            ->when($validated['senders'] ?? null, fn ($query, $senders) => $query->whereIn('sender_email', $senders))
            ->when($validated['receiver'] ?? null, fn ($query, $receiver) => $query->whereJsonContains('receivers', $receiver))
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(EmailAssistant::MAX_EMAILS)
            ->get();

        if ($emails->isEmpty()) {
            return response()->json(['message' => 'There are no emails to ask about yet.'], 422);
        }

        return $this->stream(fn () => $this->assistant->ask($emails, $validated['question']));
    }

    /**
     * analyze
     *
     * Ask Claude to read the PDFs the user ticked, or those collected within
     * the date range (optionally only from certain senders), and answer a
     * question about them. The analysis is streamed back as plain text.
     *
     * @param AnalyzePdfsRequest $request
     * @return StreamedResponse|JsonResponse
     */
    public function analyze(AnalyzePdfsRequest $request): StreamedResponse|JsonResponse
    {
        if (! config('services.anthropic.key')) {
            return $this->notConfigured();
        }

        $documents = $request->documents();

        if ($documents->isEmpty()) {
            return response()->json(['message' => 'Collect some PDFs first, then ask about them.'], 422);
        }

        return $this->stream(fn () => $this->analyst->analyze($documents, $request->validated('question')));
    }

    /**
     * agent
     *
     * Have the report agent carry out the user's task, such as building a
     * table from invoice PDFs, uploading it to Google Sheets and emailing the
     * link to the signed-in user. Each step, then the agent's summary, is
     * streamed back as plain text.
     *
     * @param RunAgentRequest $request
     * @return StreamedResponse|JsonResponse
     */
    public function agent(RunAgentRequest $request): StreamedResponse|JsonResponse
    {
        if (! config('services.anthropic.key')) {
            return $this->notConfigured();
        }

        return $this->stream(
            fn () => $this->agent->run($request->user(), $request->validated('task')),
            config('services.anthropic.agent_time_limit'),
        );
    }

    /**
     * stream
     *
     * Stream a generator of answer text back to the browser, turning Claude's
     * failures into a readable note at the end of the answer.
     *
     * @param Closure(): iterable<int, string> $answer
     * @param int|null $timeLimit seconds the answer may take; the usual Claude time limit when null
     * @return StreamedResponse
     */
    private function stream(Closure $answer, ?int $timeLimit = null): StreamedResponse
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
     * notConfigured
     *
     * @return JsonResponse
     */
    private function notConfigured(): JsonResponse
    {
        return response()->json(['message' => 'Claude is not set up yet: add ANTHROPIC_API_KEY to .env.'], 503);
    }
}

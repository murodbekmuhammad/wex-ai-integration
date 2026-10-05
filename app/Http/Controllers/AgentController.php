<?php

namespace App\Http\Controllers;

use Anthropic\Core\Exceptions\APIException;
use App\Http\Requests\RunAgentRequest;
use App\Models\AgentRun;
use App\Models\User;
use App\Services\ClaudeErrors;
use App\Services\ReportAgent;
use Generator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @class AgentController
 *
 * @package App\Http\Controllers
 *
 * Lists the agents from config/agents.php and runs them, streaming each step
 * and the agent's summary back as newline-delimited JSON events. Every run
 * of a configured agent is saved, and its past results can be listed.
 */
class AgentController extends Controller
{
    /**
     * The most saved results listed at once.
     */
    public const LISTED_RUNS = 20;

    /**
     * __construct
     *
     * @param ReportAgent $agent
     */
    public function __construct(private ReportAgent $agent) {}

    /**
     * index
     *
     * The agents the user can run, without their internal task and tools.
     *
     * @return JsonResponse
     */
    public function index(): JsonResponse
    {
        $agents = collect(config('agents'))
            ->map(fn (array $agent, string $key) => ['key' => $key, 'name' => $agent['name'], 'description' => $agent['description']])
            ->values();

        return response()->json(['agents' => $agents]);
    }

    /**
     * run
     *
     * Run one of the configured agents with its fixed task and tools, the
     * way the user's agent settings say: its report updates the agent's
     * existing Google Sheet or goes to a new one, and it reads every PDF of
     * its report type or only the picked ones. The result is saved when the run
     * ends.
     *
     * @param Request $request
     * @param string $key an agent key from config/agents.php
     * @return StreamedResponse|JsonResponse
     */
    public function run(Request $request, string $key): StreamedResponse|JsonResponse
    {
        $agent = config("agents.{$key}");

        abort_unless(is_array($agent), 404);

        if (! config('services.anthropic.key')) {
            return $this->notConfigured();
        }

        $user = $request->user();
        $setting = $user->agentSettingFor($key);
        $sheetMode = $setting->sheet_mode;

        return $this->streamEvents(
            fn () => $this->record(
                $user,
                $key,
                $sheetMode,
                $this->agent->run($user, $agent['task'], $agent['tools'], $key, $sheetMode === AgentRun::SHEET_NEW, $setting->pdf_document_ids),
            ),
            config('services.anthropic.agent_time_limit'),
        );
    }

    /**
     * runs
     *
     * The user's saved results of every agent, newest first, or of one agent
     * when the "agent" query parameter names it.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function runs(Request $request): JsonResponse
    {
        $key = $request->validate(['agent' => ['nullable', 'string', Rule::in(array_keys(config('agents')))]])['agent'] ?? null;

        $runs = $request->user()->agentRuns()
            ->when($key, fn ($query, string $key) => $query->where('agent_key', $key))
            ->latest()
            ->orderByDesc('id')
            ->limit(self::LISTED_RUNS)
            ->get();

        return response()->json(['runs' => $runs]);
    }

    /**
     * task
     *
     * Have the report agent carry out a task the user typed, with every
     * tool available.
     *
     * @param RunAgentRequest $request
     * @return StreamedResponse|JsonResponse
     */
    public function task(RunAgentRequest $request): StreamedResponse|JsonResponse
    {
        if (! config('services.anthropic.key')) {
            return $this->notConfigured();
        }

        return $this->streamEvents(
            fn () => $this->agent->run($request->user(), $request->validated('task')),
            config('services.anthropic.agent_time_limit'),
        );
    }

    /**
     * record
     *
     * Pass the agent's events through, noting its steps, sheet link and
     * summary, and save them as an AgentRun once the run ends, whether it
     * finished, hit the turn limit or Claude failed.
     *
     * @param User $user
     * @param string $key
     * @param string $sheetMode
     * @param Generator<int, array<string, mixed>> $events
     * @return Generator<int, array<string, mixed>>
     * @throws APIException
     */
    private function record(User $user, string $key, string $sheetMode, Generator $events): Generator
    {
        $startedAt = now();
        $steps = [];
        $sheetUrl = null;
        $status = 'failed';
        $summary = null;

        try {
            foreach ($events as $event) {
                if ($event['type'] === 'step') {
                    $steps[] = ['tool' => $event['tool'], 'label' => $event['label'], 'ok' => null, 'text' => null];
                } elseif ($event['type'] === 'result') {
                    $steps[array_key_last($steps)] = [...end($steps), 'ok' => $event['ok'], 'text' => $event['text']];
                    $sheetUrl = $event['link'] ?? $sheetUrl;
                } elseif ($event['type'] === 'summary' || $event['type'] === 'stopped') {
                    $status = $event['type'] === 'summary' ? 'completed' : 'stopped';
                    $summary = $event['text'];
                }

                yield $event;
            }
        } catch (APIException $e) {
            $summary = ClaudeErrors::describe($e);

            throw $e;
        } finally {
            $user->agentRuns()->create([
                'agent_key' => $key,
                'sheet_mode' => $sheetMode,
                'status' => $status,
                'summary' => $summary,
                'google_sheet_url' => $sheetUrl,
                'steps' => $steps,
                'started_at' => $startedAt,
                'finished_at' => now(),
            ]);
        }
    }
}

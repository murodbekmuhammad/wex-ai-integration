<?php

namespace App\Http\Controllers;

use App\Http\Requests\RunAgentRequest;
use App\Services\ReportAgent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @class AgentController
 *
 * @package App\Http\Controllers
 *
 * Lists the agents from config/agents.php and runs them, streaming each step
 * and the agent's summary back as plain text.
 */
class AgentController extends Controller
{
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
     * Run one of the configured agents with its fixed task and tools.
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

        return $this->streamText(
            fn () => $this->agent->run($request->user(), $agent['task'], $agent['tools']),
            config('services.anthropic.agent_time_limit'),
        );
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

        return $this->streamText(
            fn () => $this->agent->run($request->user(), $request->validated('task')),
            config('services.anthropic.agent_time_limit'),
        );
    }
}

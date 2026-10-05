<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAgentSettingRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * @class AgentSettingController
 *
 * @package App\Http\Controllers
 *
 * Shows and saves the user's agent settings, separately for each agent in
 * config/agents.php: the Google Sheet a run writes to and the PDFs of the
 * agent's report type it may use.
 */
class AgentSettingController extends Controller
{
    /**
     * show
     *
     * One agent's settings, or the defaults when none are saved, with the
     * PDFs of its report type collected from the user's mailbox to pick
     * from, and the agents to switch between. The first agent is shown when
     * none is asked for.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $agents = config('agents');
        $key = $request->validate(['agent' => ['nullable', 'string', Rule::in(array_keys($agents))]])['agent'] ?? array_key_first($agents);
        $user = $request->user();

        $pdfs = $user->pdfDocuments()
            ->where('report_type', $agents[$key]['report_type'])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get(['id', 'filename', 'sender', 'sender_email', 'subject', 'sent_at']);

        return response()->json([
            'agent' => $key,
            'agents' => collect($agents)
                ->map(fn (array $agent, string $agentKey) => ['key' => $agentKey, 'name' => $agent['name'], 'report_type' => $agent['report_type']])
                ->values(),
            'settings' => $user->agentSettingFor($key),
            'pdfs' => $pdfs,
        ]);
    }

    /**
     * update
     *
     * Save one agent's settings; the other agents' settings stay as they are.
     *
     * @param SaveAgentSettingRequest $request
     * @return JsonResponse
     */
    public function update(SaveAgentSettingRequest $request): JsonResponse
    {
        $setting = $request->user()->agentSettings()->updateOrCreate(['agent_key' => $request->validated('agent_key')], [
            'sheet_mode' => $request->validated('sheet_mode'),
            'pdf_document_ids' => $request->validated('pdf_document_ids'),
        ]);

        return response()->json(['settings' => $setting]);
    }
}

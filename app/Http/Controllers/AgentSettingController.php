<?php

namespace App\Http\Controllers;

use App\Http\Requests\SaveAgentSettingRequest;
use App\Models\AgentSetting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @class AgentSettingController
 *
 * @package App\Http\Controllers
 *
 * Shows and saves the user's agent settings: the Google Sheet a run writes
 * to and the invoice aging PDFs the agent may use.
 */
class AgentSettingController extends Controller
{
    /**
     * show
     *
     * The user's settings, or the defaults when none are saved, with the
     * invoice aging PDFs collected from their mailbox to pick from.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function show(Request $request): JsonResponse
    {
        $user = $request->user();

        $pdfs = $user->pdfDocuments()
            ->where('report_type', 'invoice_aging')
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get(['id', 'filename', 'sender', 'sender_email', 'subject', 'sent_at']);

        return response()->json([
            'settings' => $user->agentSetting ?? new AgentSetting,
            'pdfs' => $pdfs,
        ]);
    }

    /**
     * update
     *
     * @param SaveAgentSettingRequest $request
     * @return JsonResponse
     */
    public function update(SaveAgentSettingRequest $request): JsonResponse
    {
        $setting = $request->user()->agentSetting()->updateOrCreate([], [
            'sheet_mode' => $request->validated('sheet_mode'),
            'pdf_document_ids' => $request->validated('pdf_document_ids'),
        ]);

        return response()->json(['settings' => $setting]);
    }
}

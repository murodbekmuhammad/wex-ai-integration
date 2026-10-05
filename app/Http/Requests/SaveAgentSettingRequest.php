<?php

namespace App\Http\Requests;

use App\Models\AgentRun;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * @class SaveAgentSettingRequest
 *
 * @package App\Http\Requests
 *
 * One agent's settings: the agent (config/agents.php), the Google Sheet to
 * use, and the PDFs of the agent's report type it may read. No PDF list
 * means all of them.
 */
class SaveAgentSettingRequest extends FormRequest
{
    /**
     * authorize
     *
     * The route already sits behind the auth middleware.
     *
     * @return bool
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * rules
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $reportType = config('agents')[$this->string('agent_key')->value()]['report_type'] ?? '';

        return [
            'agent_key' => ['required', 'string', Rule::in(array_keys(config('agents')))],
            'sheet_mode' => ['required', Rule::in([AgentRun::SHEET_EXISTING, AgentRun::SHEET_NEW])],
            'pdf_document_ids' => ['nullable', 'array', 'min:1'],
            'pdf_document_ids.*' => [
                'integer',
                'distinct',
                Rule::exists('pdf_documents', 'id')
                    ->where('user_id', $this->user()->id)
                    ->where('report_type', $reportType),
            ],
        ];
    }

    /**
     * messages
     *
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'pdf_document_ids.min' => 'Pick at least one PDF, or choose all PDFs.',
            'pdf_document_ids.*.exists' => 'One of the picked PDFs is not one of your PDFs of this agent\'s report type.',
        ];
    }
}

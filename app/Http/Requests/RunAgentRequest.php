<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * @class RunAgentRequest
 *
 * @package App\Http\Requests
 *
 * A task for the report agent. Results are emailed to the signed-in user.
 */
class RunAgentRequest extends FormRequest
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
        return [
            'task' => ['required', 'string', 'max:2000'],
        ];
    }
}

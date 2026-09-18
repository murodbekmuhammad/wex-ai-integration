<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;

/**
 * @class BuildTableRequest
 *
 * @package App\Http\Requests
 *
 * Ask Claude for a table built from PDFs, or for changes to one of the
 * user's existing tables (table_id).
 */
class BuildTableRequest extends AnalyzePdfsRequest
{
    /**
     * rules
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'table_id' => ['nullable', 'integer'],
        ];
    }
}

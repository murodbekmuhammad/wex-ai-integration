<?php

namespace App\Http\Requests;

use App\Models\PdfDocument;
use App\Services\PdfAnalyst;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Collection;

/**
 * @class AnalyzePdfsRequest
 *
 * @package App\Http\Requests
 *
 * A question for Claude about either the PDFs the user ticked, or the PDFs
 * matching the sender and date filters.
 */
class AnalyzePdfsRequest extends PdfFilterRequest
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
            'question' => ['required', 'string', 'max:2000'],
            'document_ids' => ['nullable', 'array', 'max:'.PdfAnalyst::MAX_DOCUMENTS],
            'document_ids.*' => ['integer'],
        ];
    }

    /**
     * documents
     *
     * The user's PDFs to hand to Claude, newest first: the ticked ones when
     * there are any, otherwise the newest that match the filters.
     *
     * @return Collection<int, PdfDocument>
     */
    public function documents(): Collection
    {
        $ids = $this->validated('document_ids');

        return ($ids ? $this->user()->pdfDocuments()->whereIn('id', $ids) : $this->filteredDocuments())
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->limit(PdfAnalyst::MAX_DOCUMENTS)
            ->get();
    }
}

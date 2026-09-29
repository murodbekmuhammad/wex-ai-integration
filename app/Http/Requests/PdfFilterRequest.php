<?php

namespace App\Http\Requests;

use App\Models\PdfDocument;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

/**
 * @class PdfFilterRequest
 *
 * @package App\Http\Requests
 *
 * Which collected PDFs a request is about: those mailed by the given senders
 * (any sender when none are given) between two dates, which default to the
 * last week, optionally of one report type.
 */
class PdfFilterRequest extends FormRequest
{
    /**
     * authorize
     *
     * Routes using this request already sit behind the auth middleware.
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
            'senders' => ['nullable', 'array'],
            'senders.*' => ['string', 'max:255'],
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from'],
            'report_type' => ['nullable', 'string', Rule::in(array_keys(config('report_types')))],
        ];
    }

    /**
     * reportType
     *
     * The report type key from config/report_types.php to filter by; null
     * means every PDF.
     *
     * @return string|null
     */
    public function reportType(): ?string
    {
        return $this->validated('report_type');
    }

    /**
     * senders
     *
     * The bare sender addresses to filter by; empty means any sender.
     *
     * @return array<int, string>
     */
    public function senders(): array
    {
        return $this->validated('senders') ?? [];
    }

    /**
     * filteredDocuments
     *
     * The user's PDFs from the filtered senders within the date range,
     * narrowed to one report type when one is picked.
     *
     * @return HasMany<PdfDocument, User>
     */
    public function filteredDocuments(): HasMany
    {
        return $this->user()->pdfDocuments()
            ->when($this->senders(), fn ($query, $senders) => $query->whereIn('sender_email', $senders))
            ->when($this->reportType(), fn ($query, $type) => $query->where('report_type', $type))
            ->whereBetween('sent_at', [$this->sentFrom(), $this->sentUntil()]);
    }

    /**
     * sentFrom
     *
     * Start of the first day of the range.
     *
     * @return Carbon
     */
    public function sentFrom(): Carbon
    {
        return Carbon::parse($this->validated('from'))->startOfDay();
    }

    /**
     * sentUntil
     *
     * End of the last day of the range.
     *
     * @return Carbon
     */
    public function sentUntil(): Carbon
    {
        return Carbon::parse($this->validated('to'))->endOfDay();
    }

    /**
     * prepareForValidation
     *
     * Fill in the last week for any date the client left out or blank.
     *
     * @return void
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => $this->input('from') ?: now()->subWeek()->toDateString(),
            'to' => $this->input('to') ?: now()->toDateString(),
        ]);
    }
}

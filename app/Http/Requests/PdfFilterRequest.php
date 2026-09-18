<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * @class PdfFilterRequest
 *
 * @package App\Http\Requests
 *
 * Which collected PDFs a request is about: those mailed by the given senders
 * (any sender when none are given) between two dates, which default to the
 * last week.
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
        ];
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

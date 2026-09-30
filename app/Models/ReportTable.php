<?php

namespace App\Models;

use Database\Factories\ReportTableFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

/**
 * @class ReportTable
 *
 * @package App\Models
 *
 * A table Claude built from the user's collected PDFs.
 */
#[Fillable(['title', 'request', 'summary', 'columns', 'rows', 'warnings', 'pdf_document_ids', 'google_sheet_id', 'google_sheet_url', 'report_key'])]
#[Hidden(['user_id'])]
class ReportTable extends Model
{
    /** @use HasFactory<ReportTableFactory> */
    use HasFactory;

    /**
     * casts
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'columns' => 'array',
            'rows' => 'array',
            'warnings' => 'array',
            'pdf_document_ids' => 'array',
        ];
    }

    /**
     * user
     *
     * The user who asked for this table.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * documents
     *
     * The collected PDFs this table was built from that still exist.
     *
     * @return Collection<int, PdfDocument>
     */
    public function documents(): Collection
    {
        return $this->user->pdfDocuments()
            ->whereIn('id', $this->pdf_document_ids)
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();
    }

    /**
     * downloadName
     *
     * A safe file name for the table with the given extension.
     *
     * @param string $extension
     * @return string
     */
    public function downloadName(string $extension): string
    {
        return (Str::slug($this->title) ?: 'table').'.'.$extension;
    }
}

<?php

namespace App\Mail;

use App\Models\ReportTable;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * @class TableSheetLink
 *
 * @package App\Mail
 *
 * Emails the link to a table's Google Sheet, with a short note from the agent.
 */
class TableSheetLink extends Mailable
{
    use Queueable, SerializesModels;

    /**
     * __construct
     *
     * @param ReportTable $table a table that has been uploaded to Google Sheets
     * @param string $note what the agent wants the reader to know
     */
    public function __construct(public ReportTable $table, public string $note) {}

    /**
     * envelope
     *
     * @return Envelope
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->table->title,
        );
    }

    /**
     * content
     *
     * @return Content
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.table-sheet-link',
        );
    }
}

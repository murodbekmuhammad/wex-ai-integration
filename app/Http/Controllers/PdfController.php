<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdfFilterRequest;
use App\Services\PdfCollector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * @class PdfController
 *
 * @package App\Http\Controllers
 */
class PdfController extends Controller
{
    /**
     * __construct
     *
     * @param PdfCollector $collector
     */
    public function __construct(private PdfCollector $collector) {}

    /**
     * index
     *
     * List the PDFs collected from the user's mailbox within the date range,
     * newest first, optionally only those from certain senders. Also returns
     * every address that has sent a collected PDF, for the sender picker.
     *
     * @param PdfFilterRequest $request
     * @return JsonResponse
     */
    public function index(PdfFilterRequest $request): JsonResponse
    {
        $senders = $request->user()->pdfDocuments()
            ->distinct()
            ->orderBy('sender_email')
            ->pluck('sender_email')
            ->filter()
            ->values();

        $documents = $request->user()->pdfDocuments()
            ->when($request->senders(), fn ($query, $senders) => $query->whereIn('sender_email', $senders))
            ->whereBetween('sent_at', [$request->sentFrom(), $request->sentUntil()])
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'documents' => $documents,
            'total_size' => $documents->sum('size'),
            'senders' => $senders,
        ]);
    }

    /**
     * collect
     *
     * Search Gmail for PDFs the filtered senders mailed within the date range
     * and store the ones that aren't collected yet.
     *
     * @param PdfFilterRequest $request
     * @return JsonResponse
     * @throws AuthenticationException
     */
    public function collect(PdfFilterRequest $request): JsonResponse
    {
        $collected = $this->collector->collect(
            $request->user(),
            $request->senders(),
            $request->sentFrom(),
            $request->sentUntil(),
            config('services.google.pdf_scan_limit'),
        );

        return response()->json(['collected' => $collected]);
    }

    /**
     * download
     *
     * Send one collected PDF to the browser as a file download.
     *
     * @param Request $request
     * @param int $id
     * @return StreamedResponse
     */
    public function download(Request $request, int $id): StreamedResponse
    {
        $document = $request->user()->pdfDocuments()->findOrFail($id);
        $disk = Storage::disk();

        abort_unless($disk->exists($document->path), 404, 'This PDF is no longer stored. Collect it again.');

        // Slashes aren't allowed in a Content-Disposition filename.
        $filename = str_replace(['/', '\\'], '_', $document->filename);

        return $disk->download($document->path, $filename, ['Content-Type' => 'application/pdf']);
    }
}

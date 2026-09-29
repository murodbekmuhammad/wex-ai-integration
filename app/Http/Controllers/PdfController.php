<?php

namespace App\Http\Controllers;

use App\Http\Requests\PdfFilterRequest;
use App\Services\PdfClassifier;
use App\Services\PdfCollector;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
     * @param PdfClassifier $classifier
     */
    public function __construct(private PdfCollector $collector, private PdfClassifier $classifier) {}

    /**
     * index
     *
     * List the PDFs collected from the user's mailbox within the date range,
     * newest first, optionally only those from certain senders or of one
     * report type. Also returns every address that has sent a collected PDF,
     * for the sender picker, and the configured report types.
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

        $documents = $request->filteredDocuments()
            ->orderByDesc('sent_at')
            ->orderByDesc('id')
            ->get();

        return response()->json([
            'documents' => $documents,
            'total_size' => $documents->sum('size'),
            'senders' => $senders,
            'report_types' => collect(config('report_types'))->keys()
                ->map(fn (string $key) => ['key' => $key, 'label' => Str::ucfirst(str_replace('_', ' ', $key))])
                ->values(),
        ]);
    }

    /**
     * collect
     *
     * Search Gmail for PDFs the filtered senders mailed within the date range
     * and store the ones that aren't collected yet, then have Claude tag the
     * PDFs in the range it hasn't read yet with their report type.
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

        $classified = 0;

        if (config('services.anthropic.key')) {
            set_time_limit(config('services.anthropic.time_limit'));

            // Every PDF in the range, not only the picked type: untagged ones have no type yet.
            $pending = $request->user()->pdfDocuments()
                ->when($request->senders(), fn ($query, $senders) => $query->whereIn('sender_email', $senders))
                ->whereBetween('sent_at', [$request->sentFrom(), $request->sentUntil()])
                ->whereNull('classified_at')
                ->orderByDesc('sent_at')
                ->limit(PdfClassifier::MAX_DOCUMENTS)
                ->get();

            $classified = $this->classifier->classifyPending($pending);
        }

        return response()->json(['collected' => $collected, 'classified' => $classified]);
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

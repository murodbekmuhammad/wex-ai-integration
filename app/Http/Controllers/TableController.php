<?php

namespace App\Http\Controllers;

use Anthropic\Core\Exceptions\APIException;
use App\Http\Requests\BuildTableRequest;
use App\Services\ClaudeErrors;
use App\Services\GoogleSheets;
use App\Services\TableBuildException;
use App\Services\TableBuilder;
use App\Services\TableExporter;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * @class TableController
 *
 * @package App\Http\Controllers
 */
class TableController extends Controller
{
    /**
     * __construct
     *
     * @param TableBuilder $builder
     * @param TableExporter $exporter
     * @param GoogleSheets $sheets
     */
    public function __construct(private TableBuilder $builder, private TableExporter $exporter, private GoogleSheets $sheets) {}

    /**
     * index
     *
     * List the user's saved tables, newest first.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $tables = $request->user()->reportTables()
            ->select('id', 'title', 'request', 'created_at')
            ->latest()
            ->orderByDesc('id')
            ->get();

        return response()->json(['tables' => $tables]);
    }

    /**
     * show
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json($request->user()->reportTables()->findOrFail($id));
    }

    /**
     * store
     *
     * Have Claude build a table from the ticked or filtered PDFs, or revise
     * an existing table (which reuses that table's PDFs). The result is
     * saved as a new table, so the previous version stays downloadable.
     *
     * @param BuildTableRequest $request
     * @return JsonResponse
     */
    public function store(BuildTableRequest $request): JsonResponse
    {
        if (! config('services.anthropic.key')) {
            return response()->json(['message' => 'Claude is not set up yet: add ANTHROPIC_API_KEY to .env.'], 503);
        }

        $current = $request->validated('table_id')
            ? $request->user()->reportTables()->findOrFail($request->validated('table_id'))
            : null;

        $documents = $current ? $current->documents() : $request->documents();

        if ($documents->isEmpty() && ! $current) {
            return response()->json(['message' => 'Collect some PDFs first, then ask for a table.'], 422);
        }

        set_time_limit(config('services.anthropic.time_limit'));

        try {
            $built = $this->builder->build($documents, $request->validated('question'), $current);
        } catch (TableBuildException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (APIException $e) {
            if (ClaudeErrors::shouldReport($e)) {
                report($e);
            }

            return response()->json(['message' => ClaudeErrors::describe($e)], 502);
        }

        $table = $request->user()->reportTables()->create([
            ...$built,
            'request' => $request->validated('question'),
            'pdf_document_ids' => $documents->pluck('id')->all(),
        ]);

        return response()->json($table, 201);
    }

    /**
     * download
     *
     * Download a table as an Excel workbook or a PDF.
     *
     * @param Request $request
     * @param int $id
     * @param string $format "xlsx" or "pdf"
     * @return Response
     */
    public function download(Request $request, int $id, string $format): Response
    {
        $table = $request->user()->reportTables()->findOrFail($id);

        return $format === 'pdf' ? $this->exporter->pdf($table) : $this->exporter->xlsx($table);
    }

    /**
     * uploadToGoogleSheets
     *
     * Upload the table's Excel workbook to the user's Google Drive as a
     * Google Sheet. A table that was uploaded before and is still in Drive
     * isn't uploaded again; its existing sheet is returned instead.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     * @throws AuthenticationException
     */
    public function uploadToGoogleSheets(Request $request, int $id): JsonResponse
    {
        $user = $request->user();
        $table = $user->reportTables()->findOrFail($id);

        try {
            if (! $table->google_sheet_id || ! $this->sheets->exists($user, $table->google_sheet_id)) {
                $sheet = $this->sheets->upload($user, $table->title, $this->exporter->xlsxContents($table));

                $table->update(['google_sheet_id' => $sheet['id'], 'google_sheet_url' => $sheet['url']]);
            }
        } catch (AuthorizationException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        } catch (RequestException $e) {
            report($e);

            return response()->json(['message' => 'Google Drive could not take the file right now. Please try again.'], 502);
        }

        return response()->json(['google_sheet_url' => $table->google_sheet_url]);
    }

    /**
     * destroy
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     */
    public function destroy(Request $request, int $id): JsonResponse
    {
        $request->user()->reportTables()->findOrFail($id)->delete();

        return response()->json(['ok' => true]);
    }
}

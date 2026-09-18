<?php

namespace App\Http\Controllers;

use App\Services\GmailService;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * @class EmailController
 *
 * @package App\Http\Controllers
 */
class EmailController extends Controller
{
    /**
     * Columns the inbox can be sorted by.
     */
    private const SORTABLE = ['sent_at', 'sender', 'receivers', 'subject'];

    /**
     * __construct
     *
     * @param GmailService $gmail
     */
    public function __construct(private GmailService $gmail) {}

    /**
     * index
     *
     * List the user's synced emails, optionally filtered by sender addresses
     * and a receiver address, and sorted by one of the SORTABLE columns. Also
     * returns every sender and receiver address so the UI can build its
     * filter controls.
     *
     * @param Request $request
     * @return JsonResponse
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'senders' => ['nullable', 'array'],
            'senders.*' => ['string', 'max:255'],
            'receiver' => ['nullable', 'string', 'max:255'],
            'sort' => ['nullable', 'in:'.implode(',', self::SORTABLE)],
            'direction' => ['nullable', 'in:asc,desc'],
        ]);

        $user = $request->user();

        $receivers = $user->emails()->pluck('receivers')->flatten()->unique()->sort()->values();
        $senders = $user->emails()->distinct()->orderBy('sender_email')->pluck('sender_email')->filter()->values();

        $page = $user->emails()
            ->when($validated['senders'] ?? null, fn ($query, $senders) => $query->whereIn('sender_email', $senders))
            ->when($validated['receiver'] ?? null, fn ($query, $receiver) => $query->whereJsonContains('receivers', $receiver))
            ->orderBy($validated['sort'] ?? 'sent_at', $validated['direction'] ?? 'desc')
            ->orderByDesc('id')
            ->paginate(15)
            ->withQueryString();

        return response()->json([
            'emails' => $page,
            'senders' => $senders,
            'receivers' => $receivers,
        ]);
    }

    /**
     * sync
     *
     * Pull the newest messages from Gmail.
     *
     * @param Request $request
     * @return JsonResponse
     * @throws AuthenticationException
     */
    public function sync(Request $request): JsonResponse
    {
        $synced = $this->gmail->sync($request->user(), config('services.google.sync_limit'));

        return response()->json(['synced' => $synced]);
    }

    /**
     * show
     *
     * Fetch one message's full body from Gmail.
     *
     * @param Request $request
     * @param int $id
     * @return JsonResponse
     * @throws AuthenticationException
     */
    public function show(Request $request, int $id): JsonResponse
    {
        $email = $request->user()->emails()->findOrFail($id);

        return response()->json($this->gmail->body($request->user(), $email->gmail_id));
    }
}

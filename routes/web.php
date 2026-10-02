<?php

use App\Http\Controllers\AgentController;
use App\Http\Controllers\AgentSettingController;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\EmailController;
use App\Http\Controllers\PdfController;
use App\Http\Controllers\TableController;
use Illuminate\Support\Facades\Route;

// Each page is the Vue app; the current user (or null) and the page to show are handed to it on load.
Route::get('/', fn () => view('app', [
    'user' => auth()->user(),
    'error' => session('error'),
    'page' => 'agents',
]));
Route::get('/settings', fn () => view('app', [
    'user' => auth()->user(),
    'error' => session('error'),
    'page' => 'settings',
]));
Route::get('/workspace', fn () => view('app', [
    'user' => auth()->user(),
    'error' => session('error'),
    'page' => 'workspace',
]));

Route::get('/auth/google', [AuthController::class, 'redirect']);
Route::get('/auth/google/callback', [AuthController::class, 'callback']);

Route::middleware('auth')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/emails', [EmailController::class, 'index']);
    Route::post('/emails/sync', [EmailController::class, 'sync']);
    Route::get('/emails/{id}', [EmailController::class, 'show'])->whereNumber('id');
    Route::get('/pdfs', [PdfController::class, 'index']);
    Route::post('/pdfs/collect', [PdfController::class, 'collect'])->middleware('throttle:10,1');
    Route::get('/pdfs/{id}/download', [PdfController::class, 'download'])->whereNumber('id');
    Route::post('/ask', [AssistantController::class, 'ask'])->middleware('throttle:10,1');
    Route::post('/analyze', [AssistantController::class, 'analyze'])->middleware('throttle:10,1');
    Route::get('/agents', [AgentController::class, 'index']);
    Route::post('/agents/{key}/run', [AgentController::class, 'run'])->middleware('throttle:5,1');
    Route::get('/agents/{key}/runs', [AgentController::class, 'runs']);
    Route::get('/agent-settings', [AgentSettingController::class, 'show']);
    Route::put('/agent-settings', [AgentSettingController::class, 'update']);
    Route::post('/agent', [AgentController::class, 'task'])->middleware('throttle:5,1');
    Route::get('/tables', [TableController::class, 'index']);
    Route::post('/tables', [TableController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/tables/{id}', [TableController::class, 'show'])->whereNumber('id');
    Route::delete('/tables/{id}', [TableController::class, 'destroy'])->whereNumber('id');
    Route::get('/tables/{id}/download/{format}', [TableController::class, 'download'])
        ->whereNumber('id')
        ->whereIn('format', ['xlsx', 'pdf']);
    Route::post('/tables/{id}/google-sheet', [TableController::class, 'uploadToGoogleSheets'])
        ->whereNumber('id')
        ->middleware('throttle:10,1');
});

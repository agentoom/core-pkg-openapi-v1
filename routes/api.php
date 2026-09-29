<?php

use Agentoom\OpenApiV1\Http\Controllers\ChatCompletionController;
use Agentoom\OpenApiV1\Http\Controllers\ModelController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware([
    'openapi.auth.error',
    'openapi.api.key',
])->group(function () {
    Route::get('/models', ModelController::class);
    Route::post('/chat/completions', ChatCompletionController::class);
});

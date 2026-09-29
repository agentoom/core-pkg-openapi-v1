<?php

use Agentoom\OpenApiV1\Livewire\Admin\OpenApiTokenCreateEdit;
use Agentoom\OpenApiV1\Livewire\Admin\OpenApiTokenIndex;
use Illuminate\Support\Facades\Route;

Route::middleware(['web', 'auth', 'admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('openapi-tokens', OpenApiTokenIndex::class)
        ->middleware('permission:openapi-tokens.view')
        ->name('openapi-tokens.index');

    Route::get('openapi-tokens/create', OpenApiTokenCreateEdit::class)
        ->middleware('permission:openapi-tokens.create')
        ->name('openapi-tokens.create');

    Route::get('openapi-tokens/{token}/edit', OpenApiTokenCreateEdit::class)
        ->middleware('permission:openapi-tokens.edit')
        ->name('openapi-tokens.edit');
});

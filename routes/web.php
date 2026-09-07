<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::view('/docs', 'scribe.index')->name('scribe');

Route::get('/docs.postman', function () {
    $path = base_path('docs/postman/Restaurant-SaaS-API.postman_collection.json');

    abort_unless(is_file($path), 404);

    return response()->file($path, [
        'Content-Type' => 'application/json; charset=UTF-8',
    ]);
})->name('scribe.postman');

Route::get('/docs.openapi', function () {
    $path = collect([
        storage_path('app/scribe/openapi.yaml'),
        storage_path('app/private/scribe/openapi.yaml'),
    ])->first(fn (string $candidate): bool => is_file($candidate));

    abort_if($path === null, 404);

    return response()->file($path, [
        'Content-Type' => 'application/yaml; charset=UTF-8',
    ]);
})->name('scribe.openapi');

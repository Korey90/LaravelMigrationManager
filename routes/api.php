<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\MigrationController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::post('/parse-migrations', [MigrationController::class, 'parseMigrations']);
Route::post('/execute-query', [MigrationController::class, 'executeQuery']);
Route::post('/save-table-data', [MigrationController::class, 'saveTableData']);

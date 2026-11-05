<?php

use App\Http\Controllers\Api\V1\Mobile\KnowledgeTasksController as Tasks;
use App\Http\Middleware\EnsureKnowledgeFeature;
use Illuminate\Support\Facades\Route;

Route::middleware(EnsureKnowledgeFeature::class)->group(function (): void {
    Route::get('tasks', [Tasks::class, 'index'])->middleware('spark.ability:data:read');
    Route::post('tasks', [Tasks::class, 'store'])->middleware('spark.ability:data:write');
    Route::post('tasks/bulk-actions', [Tasks::class, 'bulk'])->middleware('spark.ability:data:write');
    Route::get('tasks/{id}', [Tasks::class, 'show'])->middleware('spark.ability:data:read');
    Route::get('tasks/{id}/history', [Tasks::class, 'history'])->middleware('spark.ability:data:read');
    Route::patch('tasks/{id}', [Tasks::class, 'update'])->middleware('spark.ability:data:write');
    Route::post('tasks/{id}/actions', [Tasks::class, 'action'])->middleware('spark.ability:data:write');
    Route::get('projects', [Tasks::class, 'projects'])->middleware('spark.ability:data:read');
    Route::post('projects', [Tasks::class, 'projectStore'])->middleware('spark.ability:data:write');
    Route::get('projects/{id}', [Tasks::class, 'projectShow'])->middleware('spark.ability:data:read');
    Route::patch('projects/{id}', [Tasks::class, 'projectUpdate'])->middleware('spark.ability:data:write');
    Route::delete('projects/{id}', [Tasks::class, 'projectDelete'])->middleware('spark.ability:data:write');

});

<?php

use App\Http\Controllers\Api\VideoController;
use Illuminate\Support\Facades\Route;

/*
| Promoted home-page video. Public: GET active/. Admin (perm ads.manage): CRUD.
| Static `active` BEFORE `{id}`.
*/
Route::prefix('videos')->group(function () {
    Route::get('active', [VideoController::class, 'active']);

    Route::middleware(['api.auth', 'perm:ads.manage'])->group(function () {
        Route::get('/', [VideoController::class, 'index']);
        Route::post('/', [VideoController::class, 'store']);
        Route::match(['put', 'patch'], '{id}', [VideoController::class, 'update'])->whereNumber('id');
        Route::delete('{id}', [VideoController::class, 'destroy'])->whereNumber('id');
    });
});

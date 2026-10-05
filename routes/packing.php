<?php

use App\Http\Controllers\PackingChecklistController;
use Illuminate\Support\Facades\Route;

Route::middleware('tenant.type:store')->group(function () {
    Route::get('/belpost/packing.pdf', [PackingChecklistController::class, 'belpost'])->name('belpost.packingPdf');
    Route::get('/europochta/packing.pdf', [PackingChecklistController::class, 'europochta'])->name('europochta.packingPdf');
    Route::get('/courier/packing.pdf', [PackingChecklistController::class, 'courier'])->name('courier.packingPdf');
});

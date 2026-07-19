<?php

use App\Http\Controllers\AddressController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BelpostController;
use App\Http\Controllers\EvropostController;
use App\Http\Controllers\FinanceController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\TenantSettingController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

// Auth
Route::get('/login', [LoginController::class, 'showLoginForm'])->name('login')->middleware('guest');
Route::post('/login', [LoginController::class, 'login'])->name('login.post')->middleware('guest');
Route::post('/logout', [LoginController::class, 'logout'])->name('logout');

// Root redirect
Route::get('/', fn () => redirect('/orders'));

// Orders (protected, tenant-scoped via middleware in controller)
Route::prefix('orders')->name('orders.')->group(function () {
    Route::get('/', [OrderController::class, 'index'])->name('index');
    Route::get('/import', [OrderController::class, 'importPage'])->name('import');
    Route::post('/import-csv', [OrderController::class, 'importCsv'])->name('importCsv');
    Route::get('/create', [OrderController::class, 'create'])->name('create');
    Route::post('/', [OrderController::class, 'store'])->name('store');
    Route::post('/refresh-tracking', [OrderController::class, 'refreshTracking'])->name('refreshTracking');
    Route::get('/{order}', [OrderController::class, 'show'])->name('show');
    Route::put('/{order}', [OrderController::class, 'update'])->name('update');
    Route::patch('/{order}/status', [OrderController::class, 'updateStatus'])->name('updateStatus');
    Route::patch('/{order}/delivery-type', [OrderController::class, 'updateDeliveryType'])->name('updateDeliveryType');
    Route::delete('/{order}', [OrderController::class, 'destroy'])->name('destroy');
});

// Belpost (protected, tenant-scoped via middleware in controller)
Route::prefix('belpost')->name('belpost.')->group(function () {
    Route::get('/', [BelpostController::class, 'index'])->name('index');
    Route::post('/batches', [BelpostController::class, 'createBatch'])->name('batches.create');
    Route::post('/batches/{batch}/items', [BelpostController::class, 'processOrder'])->name('batches.processOrder');
    Route::post('/batches/{batch}/commit', [BelpostController::class, 'commit'])->name('batches.commit');
    Route::get('/batches/{batch}/pdf', [BelpostController::class, 'downloadPdf'])->name('batches.pdf');
});

// Europochta (Phase 4)
Route::prefix('europochta')->name('europochta.')->group(function () {
    Route::get('/', [EvropostController::class, 'index'])->name('index');
    Route::post('/orders/{order}/register', [EvropostController::class, 'register'])->name('register');
    Route::post('/register-all', [EvropostController::class, 'registerAll'])->name('registerAll');
});

// Products (Phase 4)
Route::prefix('products')->name('products.')->group(function () {
    Route::get('/', [ProductController::class, 'index'])->name('index');
    Route::post('/', [ProductController::class, 'store'])->name('store');
    Route::put('/{product}', [ProductController::class, 'update'])->name('update');
    Route::delete('/{product}', [ProductController::class, 'destroy'])->name('destroy');
});

// Finance (Phase 5)
Route::prefix('finances')->name('finances.')->group(function () {
    Route::get('/', [FinanceController::class, 'index'])->name('index');
    Route::post('/expenses', [FinanceController::class, 'storeExpense'])->name('expenses.store');
    Route::delete('/expenses/{expense}', [FinanceController::class, 'destroyExpense'])->name('expenses.destroy');
    Route::post('/income', [FinanceController::class, 'storeIncome'])->name('income.store');
    Route::delete('/income/{income}', [FinanceController::class, 'destroyIncome'])->name('income.destroy');
});

// Settings (Phase 5)
Route::prefix('settings')->name('settings.')->group(function () {
    Route::get('/', [TenantSettingController::class, 'index'])->name('index');
    Route::patch('/theme', [TenantSettingController::class, 'updateTheme'])->name('theme');
    Route::post('/', [TenantSettingController::class, 'update'])->name('update');
    Route::post('/generate-webhook-secret', [TenantSettingController::class, 'generateWebhookSecret'])->name('generateWebhookSecret');
});

// User management (Phase 5 — admin only)
Route::prefix('users')->name('users.')->group(function () {
    Route::get('/', [UserController::class, 'index'])->name('index');
    Route::post('/', [UserController::class, 'store'])->name('store');
    Route::put('/{user}', [UserController::class, 'update'])->name('update');
    Route::delete('/{user}', [UserController::class, 'destroy'])->name('destroy');
});

// Authenticated AJAX endpoints (session-based auth, inside web middleware group)
Route::prefix('api')->name('api.')->group(function () {
    Route::get('/address/search', [AddressController::class, 'search'])->name('address.search');
    Route::get('/belpost/batches/{batch}/status', [BelpostController::class, 'batchStatus'])->name('belpost.batchStatus');
    Route::get('/orders/tracking-status', [OrderController::class, 'trackingStatus'])->name('orders.trackingStatus');
    Route::post('/tracking/auto-notice/dismiss', [OrderController::class, 'dismissTrackingNotice'])->name('tracking.dismissNotice');
});

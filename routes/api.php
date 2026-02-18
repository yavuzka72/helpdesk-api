<?php

use App\Http\Controllers\Api\CompanyController;
use App\Http\Controllers\Api\DashboardController;
use App\Http\Controllers\Api\NotificationController;
use App\Http\Controllers\Api\ServiceReportController;
use App\Http\Controllers\Api\ServiceRequestController;
use App\Http\Controllers\Api\TicketController;
use App\Http\Controllers\Api\TicketMessageController;
use App\Http\Controllers\Api\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::apiResource('companies', CompanyController::class);
    Route::apiResource('users', UserController::class);
    Route::apiResource('tickets', TicketController::class);
    Route::post('tickets/{ticket}/convert-to-service', [TicketController::class, 'convertToService']);
    Route::apiResource('ticket-messages', TicketMessageController::class);
    Route::apiResource('service-requests', ServiceRequestController::class);
    Route::apiResource('service-reports', ServiceReportController::class);
    Route::get('service-requests/{serviceRequest}/report', [ServiceReportController::class, 'showForService']);
    Route::get('service-requests/{serviceRequest}/pdf', [ServiceReportController::class, 'generatePdf']);
    Route::get('notifications', [NotificationController::class, 'index']);
    Route::post('notifications/{id}/read', [NotificationController::class, 'markAsRead']);
});

Route::middleware(['auth:sanctum', 'role:admin'])->group(function (): void {
    Route::get('dashboard', [DashboardController::class, 'index']);
    Route::get('activities', [DashboardController::class, 'activity']);
});

Route::middleware(['auth:sanctum', 'role:technician'])->group(function (): void {
    Route::post('service-requests/{serviceRequest}/report', [ServiceReportController::class, 'storeForService']);
});

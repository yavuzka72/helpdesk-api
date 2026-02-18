<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\ServiceRequest;
use App\Models\Ticket;
use Illuminate\Http\JsonResponse;

class DashboardController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'tickets' => [
                'open' => Ticket::query()->where('status', 'open')->count(),
                'in_progress' => Ticket::query()->where('status', 'in_progress')->count(),
                'critical' => Ticket::query()->where('priority', 'critical')->count(),
                'today' => Ticket::query()->whereDate('created_at', today())->count(),
            ],
            'services' => [
                'pending' => ServiceRequest::query()->where('status', 'pending')->count(),
                'in_progress' => ServiceRequest::query()->where('status', 'in_progress')->count(),
                'completed_today' => ServiceRequest::query()->where('status', 'completed')->whereDate('updated_at', today())->count(),
                'emergency' => ServiceRequest::query()->where('service_type', 'emergency')->count(),
            ],
            'sla' => [
                'breached' => Ticket::query()->where('is_sla_breached', true)->count(),
                'due_today' => Ticket::query()->whereDate('resolution_due_at', today())->count(),
            ],
        ]);
    }

    public function activity(): JsonResponse
    {
        $activities = ActivityLog::query()
            ->with('user:id,name,email')
            ->latest()
            ->limit(20)
            ->get();

        return response()->json($activities);
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Helpers\Notifier;
use App\Http\Controllers\Controller;
use App\Models\ServiceReport;
use App\Models\ServiceRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ServiceReportController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = ServiceReport::query()->with(['serviceRequest:id,request_number', 'technician:id,name,email']);

        if ($request->filled('service_request_id')) {
            $query->where('service_request_id', $request->integer('service_request_id'));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'service_request_id' => ['required', 'exists:service_requests,id'],
            'work_summary' => ['required', 'string'],
            'parts_used' => ['nullable', 'string'],
            'total_minutes' => ['nullable', 'integer', 'min:0'],
            'customer_signature' => ['nullable', 'string'],
        ]);

        $serviceReport = DB::transaction(function () use ($data, $request): ServiceReport {
            $service = ServiceRequest::query()->lockForUpdate()->findOrFail($data['service_request_id']);
            if ($service->report()->exists()) {
                throw new HttpResponseException(response()->json([
                    'message' => 'Bu servis için zaten rapor oluşturulmuş.',
                ], 400));
            }

            $report = ServiceReport::create([
                'service_request_id' => $service->id,
                'technician_id' => $request->user()->id,
                'work_summary' => $data['work_summary'],
                'parts_used' => $data['parts_used'] ?? null,
                'total_minutes' => $data['total_minutes'] ?? null,
                'customer_signature' => $data['customer_signature'] ?? null,
            ]);

            $service->update(['status' => 'completed']);
            ActivityLogger::log('completed_service', $service, [
                'report_id' => $report->id,
            ]);

            Notifier::send(
                $service->created_by,
                'service_completed',
                'Servis Tamamlandı',
                'Servis talebiniz tamamlandı.',
                $service
            );

            return $report;
        });

        return response()->json($serviceReport->load(['serviceRequest', 'technician']), 201);
    }

    public function show(ServiceReport $serviceReport): JsonResponse
    {
        return response()->json($serviceReport->load(['serviceRequest', 'technician']));
    }

    public function update(Request $request, ServiceReport $serviceReport): JsonResponse
    {
        $data = $request->validate([
            'work_summary' => ['nullable', 'string'],
            'parts_used' => ['nullable', 'string'],
            'total_minutes' => ['nullable', 'integer', 'min:0'],
            'customer_signature' => ['nullable', 'string'],
        ]);

        $serviceReport->update($data);

        return response()->json($serviceReport->load(['serviceRequest', 'technician']));
    }

    public function destroy(ServiceReport $serviceReport): JsonResponse
    {
        $serviceReport->delete();

        return response()->json([], 204);
    }

    public function storeForService(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $data = $request->validate([
            'work_summary' => ['required', 'string'],
            'parts_used' => ['nullable', 'string'],
            'total_minutes' => ['nullable', 'integer', 'min:0'],
            'customer_signature' => ['nullable', 'string'],
        ]);

        try {
            $report = DB::transaction(function () use ($serviceRequest, $data, $request): ServiceReport {
                $lockedService = ServiceRequest::query()->lockForUpdate()->findOrFail($serviceRequest->id);

                if ($lockedService->report()->exists()) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Bu servis için zaten rapor oluşturulmuş.',
                    ], 400));
                }

                $report = ServiceReport::create([
                    'service_request_id' => $lockedService->id,
                    'technician_id' => $request->user()->id,
                    'work_summary' => $data['work_summary'],
                    'parts_used' => $data['parts_used'] ?? null,
                    'total_minutes' => $data['total_minutes'] ?? null,
                    'customer_signature' => $data['customer_signature'] ?? null,
                ]);

                $lockedService->update(['status' => 'completed']);
                ActivityLogger::log('completed_service', $lockedService, [
                    'report_id' => $report->id,
                ]);

                Notifier::send(
                    $lockedService->created_by,
                    'service_completed',
                    'Servis Tamamlandı',
                    'Servis talebiniz tamamlandı.',
                    $lockedService
                );

                return $report;
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Hata oluştu',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Servis raporu oluşturuldu.',
            'report' => $report->load(['serviceRequest', 'technician']),
        ], 201);
    }

    public function showForService(ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json($serviceRequest->load('report.technician')->report);
    }

    public function generatePdf(ServiceRequest $serviceRequest): BinaryFileResponse
    {
        $serviceRequest->load([
            'company',
            'creator',
            'assignedUser',
            'report.technician',
        ]);

        $pdf = Pdf::loadView('pdf.service_report', [
            'service' => $serviceRequest,
        ]);

        return $pdf->download('service-report-'.$serviceRequest->request_number.'.pdf');
    }
}

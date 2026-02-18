<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Helpers\Notifier;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ServiceRequestController extends Controller
{
    public function index(): JsonResponse
    {
        $query = ServiceRequest::query()
            ->with(['ticket:id,ticket_number,title,status,has_service_request', 'company:id,name', 'creator:id,name,email', 'assignedUser:id,name,email', 'attachments']);

        $user = request()->user();
        if ($user?->role === 'technician') {
            $query->where('assigned_to', $user->id);
        }
        if ($user?->role === 'customer') {
            $query->where('company_id', $user->company_id);
        }

        $requests = $query
            ->latest()
            ->paginate(15);

        return response()->json($requests);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'request_number' => ['nullable', 'string', 'max:30', 'unique:service_requests,request_number'],
            'ticket_id' => ['nullable', 'exists:tickets,id', 'unique:service_requests,ticket_id'],
            'company_id' => ['required', 'exists:companies,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'service_type' => ['required', Rule::in(['hardware', 'onsite_install', 'maintenance', 'emergency'])],
            'description' => ['required', 'string'],
            'address' => ['required', 'string'],
            'scheduled_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'assigned', 'in_progress', 'completed', 'cancelled'])],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip,rar'],
        ]);

        $data['request_number'] = $data['request_number'] ?? $this->generateRequestNumber();
        $data['created_by'] = $request->user()->id;

        $serviceRequest = ServiceRequest::create($data);
        $this->saveAttachments($serviceRequest, $request, 'service-requests');
        ActivityLogger::log('created_service_request', $serviceRequest);

        if (! empty($data['ticket_id'])) {
            Ticket::query()->whereKey($data['ticket_id'])->update(['has_service_request' => true]);
        }

        if (! empty($serviceRequest->assigned_to)) {
            Notifier::send(
                $serviceRequest->assigned_to,
                'service_assigned',
                'Yeni Servis Atandı',
                'Size yeni bir servis talebi atandı.',
                $serviceRequest
            );
        }

        return response()->json($serviceRequest->load(['ticket', 'company', 'creator', 'assignedUser', 'attachments']), 201);
    }

    public function show(ServiceRequest $serviceRequest): JsonResponse
    {
        return response()->json($serviceRequest->load(['ticket', 'company', 'creator', 'assignedUser', 'report.technician', 'attachments']));
    }

    public function update(Request $request, ServiceRequest $serviceRequest): JsonResponse
    {
        $data = $request->validate([
            'request_number' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('service_requests', 'request_number')->ignore($serviceRequest->id)],
            'ticket_id' => ['nullable', 'exists:tickets,id', Rule::unique('service_requests', 'ticket_id')->ignore($serviceRequest->id)],
            'company_id' => ['sometimes', 'required', 'exists:companies,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'service_type' => ['sometimes', 'required', Rule::in(['hardware', 'onsite_install', 'maintenance', 'emergency'])],
            'description' => ['sometimes', 'required', 'string'],
            'address' => ['sometimes', 'required', 'string'],
            'scheduled_date' => ['nullable', 'date'],
            'status' => ['sometimes', Rule::in(['pending', 'approved', 'assigned', 'in_progress', 'completed', 'cancelled'])],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip,rar'],
        ]);

        $previousTicketId = $serviceRequest->ticket_id;
        $previousAssignedTo = $serviceRequest->assigned_to;
        $previousStatus = $serviceRequest->status;
        $serviceRequest->update($data);
        $this->saveAttachments($serviceRequest, $request, 'service-requests');

        if (array_key_exists('ticket_id', $data)) {
            if (! empty($previousTicketId) && $previousTicketId !== $serviceRequest->ticket_id) {
                Ticket::query()->whereKey($previousTicketId)->update(['has_service_request' => false]);
            }

            if (! empty($serviceRequest->ticket_id)) {
                Ticket::query()->whereKey($serviceRequest->ticket_id)->update(['has_service_request' => true]);
            }
        }

        if (array_key_exists('status', $data) && $previousStatus !== $serviceRequest->status) {
            ActivityLogger::log('updated_service_status', $serviceRequest, [
                'old_status' => $previousStatus,
                'new_status' => $serviceRequest->status,
            ]);

            Notifier::send(
                $serviceRequest->created_by,
                'service_status_updated',
                'Servis Durumu Güncellendi',
                "Servis durumu {$serviceRequest->status} olarak güncellendi.",
                $serviceRequest
            );
        }

        if (array_key_exists('assigned_to', $data) && $serviceRequest->assigned_to && $previousAssignedTo !== $serviceRequest->assigned_to) {
            ActivityLogger::log('assigned_service_request', $serviceRequest, [
                'assigned_to' => $serviceRequest->assigned_to,
            ]);

            Notifier::send(
                $serviceRequest->assigned_to,
                'service_assigned',
                'Yeni Servis Atandı',
                'Size yeni bir servis talebi atandı.',
                $serviceRequest
            );
        }

        return response()->json($serviceRequest->load(['ticket', 'company', 'creator', 'assignedUser', 'attachments']));
    }

    public function destroy(ServiceRequest $serviceRequest): JsonResponse
    {
        if (! empty($serviceRequest->ticket_id)) {
            Ticket::query()->whereKey($serviceRequest->ticket_id)->update(['has_service_request' => false]);
        }

        $serviceRequest->delete();

        return response()->json([], 204);
    }

    private function generateRequestNumber(): string
    {
        do {
            $number = 'SRV-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (ServiceRequest::query()->where('request_number', $number)->exists());

        return $number;
    }

    private function saveAttachments(Model $model, Request $request, string $folder): void
    {
        $files = $request->file('attachments', []);
        foreach ($files as $file) {
            $path = $file->store("attachments/{$folder}", 'public');

            $model->attachments()->create([
                'user_id' => $request->user()->id,
                'disk' => 'public',
                'path' => $path,
                'original_name' => $file->getClientOriginalName(),
                'mime_type' => $file->getMimeType(),
                'size' => $file->getSize(),
            ]);
        }
    }
}

<?php

namespace App\Http\Controllers\Api;

use App\Helpers\ActivityLogger;
use App\Helpers\Notifier;
use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\SlaSetting;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    public function index(): JsonResponse
    {
        Ticket::query()
            ->whereNotIn('status', ['resolved', 'closed'])
            ->whereNotNull('resolution_due_at')
            ->where('resolution_due_at', '<', now())
            ->update(['is_sla_breached' => true]);

        $query = Ticket::query()
            ->with(['company:id,name', 'creator:id,name,email', 'assignedUser:id,name,email', 'serviceRequest:id,ticket_id,request_number,status', 'attachments']);

        $user = request()->user();
        if ($user?->role === 'technician') {
            $query->where('assigned_to', $user->id);
        }
        if ($user?->role === 'customer') {
            $query->where('company_id', $user->company_id);
        }

        $tickets = $query
            ->latest()
            ->paginate(15);

        return response()->json($tickets);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_number' => ['nullable', 'string', 'max:30', 'unique:tickets,ticket_number'],
            'company_id' => ['required', 'exists:companies,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'category' => ['required', Rule::in(['software', 'network', 'consulting'])],
            'title' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['sometimes', Rule::in(['open', 'assigned', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'has_service_request' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip,rar'],
        ]);

        $priority = $data['priority'] ?? 'medium';
        $sla = SlaSetting::query()->where('priority', $priority)->first();

        $data['ticket_number'] = $data['ticket_number'] ?? $this->generateTicketNumber();
        $data['created_by'] = $request->user()->id;
        $data['priority'] = $priority;
        $data['response_due_at'] = $sla ? now()->addMinutes($sla->response_minutes) : null;
        $data['resolution_due_at'] = $sla ? now()->addMinutes($sla->resolution_minutes) : null;

        $ticket = Ticket::create($data);
        $this->saveAttachments($ticket, $request, 'tickets');
        ActivityLogger::log('created_ticket', $ticket);

        if (! empty($ticket->assigned_to)) {
            Notifier::send(
                $ticket->assigned_to,
                'ticket_assigned',
                'Yeni Ticket Atandı',
                'Size yeni bir ticket atandı.',
                $ticket
            );
        }

        return response()->json($ticket->load(['company', 'creator', 'assignedUser', 'serviceRequest', 'attachments']), 201);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        return response()->json($ticket->load(['company', 'creator', 'assignedUser', 'messages.user', 'serviceRequest', 'attachments']));
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'ticket_number' => ['sometimes', 'required', 'string', 'max:30', Rule::unique('tickets', 'ticket_number')->ignore($ticket->id)],
            'company_id' => ['sometimes', 'required', 'exists:companies,id'],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'category' => ['sometimes', 'required', Rule::in(['software', 'network', 'consulting'])],
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string'],
            'priority' => ['sometimes', Rule::in(['low', 'medium', 'high', 'critical'])],
            'status' => ['sometimes', Rule::in(['open', 'assigned', 'in_progress', 'waiting_customer', 'resolved', 'closed'])],
            'has_service_request' => ['sometimes', 'boolean'],
            'attachments' => ['sometimes', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240', 'mimes:jpg,jpeg,png,webp,pdf,doc,docx,xls,xlsx,txt,zip,rar'],
        ]);

        $oldStatus = $ticket->status;
        $oldAssignedTo = $ticket->assigned_to;
        $ticket->update($data);
        $this->saveAttachments($ticket, $request, 'tickets');

        if (array_key_exists('status', $data) && $oldStatus !== $ticket->status) {
            ActivityLogger::log('updated_ticket_status', $ticket, [
                'old_status' => $oldStatus,
                'new_status' => $ticket->status,
            ]);

            Notifier::send(
                $ticket->created_by,
                'ticket_status_updated',
                'Ticket Durumu Güncellendi',
                "Ticket durumu {$ticket->status} olarak güncellendi.",
                $ticket
            );
        }

        if (array_key_exists('assigned_to', $data) && $ticket->assigned_to && $oldAssignedTo !== $ticket->assigned_to) {
            ActivityLogger::log('assigned_ticket', $ticket, [
                'assigned_to' => $ticket->assigned_to,
            ]);

            Notifier::send(
                $ticket->assigned_to,
                'ticket_assigned',
                'Yeni Ticket Atandı',
                'Size yeni bir ticket atandı.',
                $ticket
            );
        }

        return response()->json($ticket->load(['company', 'creator', 'assignedUser', 'serviceRequest', 'attachments']));
    }

    public function destroy(Ticket $ticket): JsonResponse
    {
        $ticket->delete();

        return response()->json([], 204);
    }

    public function convertToService(Request $request, Ticket $ticket): JsonResponse
    {
        $data = $request->validate([
            'address' => ['nullable', 'string'],
            'service_type' => ['sometimes', Rule::in(['hardware', 'onsite_install', 'maintenance', 'emergency'])],
            'assigned_to' => ['nullable', 'exists:users,id'],
            'ticket_status' => ['sometimes', Rule::in(['assigned', 'in_progress', 'resolved'])],
        ]);

        try {
            $serviceRequest = DB::transaction(function () use ($ticket, $data, $request): ServiceRequest {
                $lockedTicket = Ticket::query()
                    ->lockForUpdate()
                    ->with('serviceRequest')
                    ->findOrFail($ticket->id);

                if ($lockedTicket->has_service_request || $lockedTicket->serviceRequest()->exists()) {
                    throw new HttpResponseException(response()->json([
                        'message' => 'Bu ticket için zaten servis talebi oluşturulmuş.',
                    ], 400));
                }

                $serviceRequest = ServiceRequest::create([
                    'request_number' => $this->generateRequestNumber(),
                    'ticket_id' => $lockedTicket->id,
                    'company_id' => $lockedTicket->company_id,
                    'created_by' => $request->user()->id,
                    'assigned_to' => $data['assigned_to'] ?? null,
                    'service_type' => $data['service_type'] ?? 'hardware',
                    'description' => $lockedTicket->description,
                    'address' => $data['address'] ?? 'Adres girilmedi',
                    'status' => 'pending',
                ]);

                $lockedTicket->update([
                    'has_service_request' => true,
                    'status' => $data['ticket_status'] ?? 'in_progress',
                ]);

                ActivityLogger::log('created_service_request', $serviceRequest, [
                    'ticket_id' => $lockedTicket->id,
                ]);

                if (! empty($serviceRequest->assigned_to)) {
                    Notifier::send(
                        $serviceRequest->assigned_to,
                        'service_assigned',
                        'Yeni Servis Atandı',
                        'Size yeni bir servis talebi atandı.',
                        $serviceRequest
                    );
                }

                return $serviceRequest;
            });
        } catch (HttpResponseException $e) {
            return $e->getResponse();
        } catch (\Throwable $e) {
            return response()->json([
                'message' => 'Bir hata oluştu.',
                'error' => $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message' => 'Servis talebi başarıyla oluşturuldu.',
            'service_request' => $serviceRequest->load(['ticket', 'company', 'creator', 'assignee']),
        ], 201);
    }

    private function generateTicketNumber(): string
    {
        do {
            $number = 'TCK-'.now()->format('Ymd').'-'.Str::upper(Str::random(5));
        } while (Ticket::query()->where('ticket_number', $number)->exists());

        return $number;
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

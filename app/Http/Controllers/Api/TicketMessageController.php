<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketMessage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketMessageController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $query = TicketMessage::query()->with(['ticket:id,ticket_number,title', 'user:id,name,email']);

        if ($request->filled('ticket_id')) {
            $query->where('ticket_id', $request->integer('ticket_id'));
        }

        return response()->json($query->latest()->paginate(20));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'ticket_id' => ['required', 'exists:tickets,id'],
            'user_id' => ['required', 'exists:users,id'],
            'message' => ['required', 'string'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $message = TicketMessage::create($data);

        return response()->json($message->load(['ticket', 'user']), 201);
    }

    public function show(TicketMessage $ticketMessage): JsonResponse
    {
        return response()->json($ticketMessage->load(['ticket', 'user']));
    }

    public function update(Request $request, TicketMessage $ticketMessage): JsonResponse
    {
        $data = $request->validate([
            'message' => ['sometimes', 'required', 'string'],
            'is_internal' => ['sometimes', 'boolean'],
        ]);

        $ticketMessage->update($data);

        return response()->json($ticketMessage->load(['ticket', 'user']));
    }

    public function destroy(TicketMessage $ticketMessage): JsonResponse
    {
        $ticketMessage->delete();

        return response()->json([], 204);
    }
}

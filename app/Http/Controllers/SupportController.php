<?php

namespace App\Http\Controllers;

use App\Mail\SupportTicketMail;
use App\Models\SupportTicket;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class SupportController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $ticket = SupportTicket::create([
            'user_id' => $request->user()->id,
            'subject' => $data['subject'],
            'message' => $data['message'],
            'status' => 'open',
            'support_email' => 'tr3slogprueba@gmail.com',
        ]);

        try {
            Mail::to($ticket->support_email)
                ->send(new SupportTicketMail($ticket));
        } catch (\Throwable $e) {
            report($e);
            if (config('app.debug')) {
                return response()->json(['message' => $e->getMessage()], 500);
            }
        }

        return response()->json([
            'message' => 'Caso enviado. Un coordinador lo revisará en los próximos días.',
            'ticket' => $ticket,
        ], 201);
    }

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportTicket::class);

        return response()->json(
            SupportTicket::with('user')->orderByDesc('created_at')->get()
        );
    }

    public function show(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('view', $ticket);

        return response()->json($ticket->load('user'));
    }

    public function update(Request $request, SupportTicket $ticket): JsonResponse
    {
        $this->authorize('update', $ticket);

        $data = $request->validate([
            'status' => 'sometimes|in:open,pending,resolved,closed',
        ]);

        $ticket->update($data);

        return response()->json($ticket);
    }
}

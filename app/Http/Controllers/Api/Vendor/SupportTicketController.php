<?php

namespace App\Http\Controllers\Api\Vendor;

use App\Http\Controllers\Controller;
use App\Http\Responses\Api\ApiSuccessResponse;
use App\Models\SupportTicket;
use Illuminate\Http\Request;

class SupportTicketController extends Controller
{
    /** Tickets do vendor autenticado (mais recentes primeiro). */
    public function index(Request $request)
    {
        $tickets = $request->user()->vendor->supportTickets()
            ->orderByDesc('created_at')
            ->limit(50)
            ->get()
            ->map(fn (SupportTicket $t) => [
                'id' => $t->id,
                'subject' => $t->subject,
                'message' => $t->message,
                'status' => $t->status,
                'admin_reply' => $t->admin_reply,
                'replied_at' => $t->replied_at?->toIso8601String(),
                'created_at' => $t->created_at?->toIso8601String(),
            ]);

        return new ApiSuccessResponse(['tickets' => $tickets]);
    }

    /** Cria um ticket de suporte que a Piquet responde no backoffice. */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'subject' => 'required|string|max:150',
            'message' => 'required|string|max:5000',
        ]);

        $ticket = $request->user()->vendor->supportTickets()->create([
            'subject' => $validated['subject'],
            'message' => $validated['message'],
            'status' => 'open',
        ]);

        return new ApiSuccessResponse([
            'ticket' => [
                'id' => $ticket->id,
                'subject' => $ticket->subject,
                'status' => $ticket->status,
                'created_at' => $ticket->created_at?->toIso8601String(),
            ],
        ]);
    }
}

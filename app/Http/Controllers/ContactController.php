<?php

namespace App\Http\Controllers;

use App\Mail\ContactSupportMail;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

class ContactController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255',
            'subject' => 'required|string|max:255',
            'message' => 'required|string',
        ]);

        $data['reference'] = 'SUP-' . date('Ymd') . '-' . strtoupper(substr(uniqid('', true), -4));

        try {
            Mail::to('tr3slogprueba@gmail.com')
                ->send(new ContactSupportMail($data));
        } catch (\Throwable $e) {
            report($e);
            return response()->json([
                'message' => config('app.debug') ? $e->getMessage() : 'No se pudo enviar el mensaje. Intenta más tarde.',
            ], 500);
        }

        return response()->json([
            'message' => 'Mensaje enviado.',
            'reference' => $data['reference'],
        ], 201);
    }
}

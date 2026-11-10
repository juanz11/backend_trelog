<?php

namespace App\Http\Controllers;

use App\Models\LegalDocument;
use Illuminate\Http\JsonResponse;

class LegalDocumentController extends Controller
{
    public function active(string $type): JsonResponse
    {
        $document = LegalDocument::active()
            ->where('type', $type)
            ->latest('published_at')
            ->first();

        if (!$document) {
            return response()->json(['message' => 'Documento no encontrado.'], 404);
        }

        return response()->json($document->only([
            'id', 'type', 'version', 'title', 'content', 'content_hash',
        ]));
    }
}

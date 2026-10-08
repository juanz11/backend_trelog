<?php

namespace App\Http\Controllers;

use App\Models\AuditFile;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class AuditFileController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $files = AuditFile::with('user:id,name,email')->orderByDesc('created_at')->get();

        return response()->json($files);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'reference' => 'nullable|string|max:255',
            'notes' => 'nullable|string',
            'file' => 'required|file|max:20480',
        ]);

        $file = $request->file('file');

        $auditFile = AuditFile::create([
            'user_id' => $request->user()->id,
            'title' => $data['title'],
            'reference' => $data['reference'] ?? null,
            'notes' => $data['notes'] ?? null,
            'file_path' => $file->store('audit-files', 'public'),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getClientMimeType(),
            'size' => $file->getSize(),
        ]);

        $auditFile->load('user:id,name,email');

        return response()->json($auditFile, 201);
    }

    public function destroy(Request $request, AuditFile $auditFile): JsonResponse
    {
        if ($auditFile->file_path) {
            Storage::disk('public')->delete($auditFile->file_path);
        }

        $auditFile->delete();

        return response()->json(null, 204);
    }
}

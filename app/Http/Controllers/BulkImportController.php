<?php

namespace App\Http\Controllers;

use App\Services\CompanyContractService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Carga masiva de envíos: recibe filas de un Excel/CSV/XML (o texto crudo)
 * y las normaliza al formato de Shipment usando Gemini. Cada fila = 1 viaje;
 * el peso no aplica para clientes por contrato (se factura por cantidad).
 */
class BulkImportController extends Controller
{
    private const SCHEMA = [
        'origin' => 'ciudad/país de origen del envío',
        'destination' => 'ciudad/país de destino',
        'sender_name' => 'nombre del remitente',
        'sender_company' => 'empresa remitente',
        'sender_address' => 'dirección remitente',
        'sender_city' => 'ciudad remitente',
        'sender_country' => 'país remitente (código ISO, ej DO, CR, US)',
        'sender_zip' => 'código postal remitente',
        'sender_phone' => 'teléfono remitente',
        'sender_email' => 'email remitente',
        'recipient_name' => 'nombre del destinatario',
        'recipient_company' => 'empresa destinataria',
        'recipient_address' => 'dirección destinatario',
        'recipient_city' => 'ciudad destinatario',
        'recipient_country' => 'país destinatario (código ISO)',
        'recipient_zip' => 'código postal destinatario',
        'recipient_phone' => 'teléfono destinatario',
        'recipient_email' => 'email destinatario',
        'service_type' => 'siempre "Terrestre"',
        'delivery_mode' => '"standard" o "same_day"',
        'notes' => 'notas/referencia del envío',
    ];

    public function parse(Request $request): JsonResponse
    {
        $data = $request->validate([
            'filename' => 'nullable|string|max:255',
            'rows' => 'nullable|array|max:2000',
            'rows.*' => 'array',
            'raw_text' => 'nullable|string|max:400000',
            'sender_defaults' => 'nullable|array',
        ]);

        $rows = $data['rows'] ?? null;
        $rawText = $data['raw_text'] ?? null;
        if ((! is_array($rows) || ! count($rows)) && ! $rawText) {
            return response()->json(['success' => false, 'message' => 'No se recibieron datos para procesar.'], 422);
        }
        if (is_array($rows) && count($rows) > 500) {
            return response()->json(['success' => false, 'message' => 'Máximo 500 filas por carga. Divida el archivo.'], 422);
        }

        $companyName = app(CompanyContractService::class)->company($request->user())?->name;
        $defaults = $data['sender_defaults'] ?? [];

        $ai = $this->askGemini($rows, $rawText, $companyName, $defaults);
        if ($ai === null) {
            $ai = $this->heuristic($rows, $rawText, $defaults);
        }

        return response()->json([
            'success' => true,
            'shipments' => array_slice($ai['shipments'] ?? [], 0, 500),
            'warnings' => $ai['warnings'] ?? [],
            'missing_columns' => $ai['missing_columns'] ?? [],
            'total_rows' => is_array($rows) ? count($rows) : null,
            'ai_used' => ($ai['source'] ?? 'heuristic') === 'gemini',
        ]);
    }

    private function askGemini(?array $rows, ?string $rawText, ?string $companyName, array $defaults): ?array
    {
        $apiKey = env('GEMINI_API_KEY');
        $model = env('GEMINI_MODEL', 'gemini-2.0-flash');
        if (! $apiKey) {
            return null;
        }

        $schemaJson = json_encode(self::SCHEMA, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        $input = is_array($rows)
            ? "Columnas y filas (JSON):\n" . json_encode(array_slice($rows, 0, 200), JSON_UNESCAPED_UNICODE)
            : "Contenido crudo del archivo:\n" . mb_substr((string) $rawText, 0, 120000);

        $prompt = <<<PROMPT
Eres un normalizador de datos para una plataforma logística (TR3SLOG). Recibirás datos de envíos en formato arbitrario (Excel/CSV/XML convertido a texto).

{$input}

TAREA: convierte CADA fila/registro en un objeto JSON con exactamente estas claves (usa null cuando no haya dato):
{$schemaJson}

REGLAS:
- Cada fila = UN viaje/envío. No inventes datos: si un campo no existe en el origen, pon null.
- El cliente factura por CANTIDAD DE VIAJES: el peso NO aplica. No incluyas peso ni dimensiones; el sistema los tomará como 0.
- service_type debe ser "Terrestre"; delivery_mode "standard" salvo que indique lo contrario.
- Si hay columnas con datos del cliente final (nombre, teléfono, dirección de destino), mapea a recipient_*.
- Si hay datos de quién envía (empresa, dirección propia), mapea a sender_*.
- Nombres de columnas pueden estar en español, inglés o chino; interpreta sinónimos (ej: "destinatario", "receiver", "收件人" → recipient_name; "teléfono" → phone).
- Si el origen es ambiguo (varias ciudades), usa la más probable y añádelo a warnings.
- REMITENTE POR DEFECTO de la empresa: {$companyName}. Campos sender no encontrados en el archivo: {$this->jsonOrEmpty($defaults)} — úsalos como base para sender_* cuando el archivo no los traiga.
- Devuelve SOLO JSON válido con esta forma exacta, sin markdown:
{"shipments":[{...}],"warnings":["..."],"missing_columns":["nombres de columnas del archivo que no se pudieron mapear o datos críticos ausentes (teléfono/email destinatario, dirección)"]}
PROMPT;

        $parsed = null;
        foreach (array_unique([$model, 'gemini-3.5-flash-lite', 'gemini-flash-lite-latest', 'gemini-3.8-flash']) as $tryModel) {
            for ($attempt = 0; $attempt < 3 && $parsed === null; $attempt++) {
                if ($attempt > 0) {
                    sleep(2 * $attempt); // backoff ante 503 / alta demanda
                }
                $parsed = $this->callGemini($apiKey, $tryModel, $prompt);
            }
            if ($parsed !== null) {
                break;
            }
        }

        if ($parsed === null) {
            return null;
        }
        $parsed['source'] = 'gemini';
        return $parsed;
    }

    private function callGemini(string $apiKey, string $model, string $prompt): ?array
    {
        try {
            $res = Http::timeout(90)->post(
                "https://generativelanguage.googleapis.com/v1beta/models/{$model}:generateContent?key={$apiKey}",
                [
                    'contents' => [['parts' => [['text' => $prompt]]]],
                    'generationConfig' => ['temperature' => 0.1, 'responseMimeType' => 'application/json'],
                ]
            );

            if (! $res->ok()) {
                Log::warning("Gemini bulk parse failed ({$model}): " . $res->status() . ' ' . mb_substr($res->body(), 0, 300));
                return null;
            }

            $text = $res->json('candidates.0.content.parts.0.text');
            $parsed = json_decode($text, true);
            if (! is_array($parsed) || ! isset($parsed['shipments'])) {
                return null;
            }
            return $parsed;
        } catch (\Throwable $e) {
            Log::warning("Gemini bulk parse exception ({$model}): " . $e->getMessage());
            return null;
        }
    }

    /** Mapeo heurístico de respaldo si Gemini no está disponible. */
    private function heuristic(?array $rows, ?string $rawText, array $defaults): array
    {
        $shipments = [];
        $warnings = ['IA no disponible: se usó mapeo automático básico. Revise los datos antes de importar.'];
        $missing = [];

        if (is_array($rows)) {
            foreach ($rows as $row) {
                $get = function (array $needles) use ($row) {
                    foreach ($row as $k => $v) {
                        $lk = mb_strtolower((string) $k);
                        foreach ($needles as $n) {
                            if (str_contains($lk, $n)) {
                                return $v;
                            }
                        }
                    }
                    return null;
                };
                $shipments[] = [
                    'origin' => $get(['origen', 'origin', 'from', 'sale']) ?: ($defaults['city'] ?? null),
                    'destination' => $get(['destino', 'destination', 'destinatario ciudad', 'to', 'city', 'ciudad']),
                    'recipient_name' => $get(['nombre', 'name', 'destinatario', 'receiver', 'cliente']),
                    'recipient_phone' => $get(['tel', 'phone', 'celular', 'whatsapp', 'movil']),
                    'recipient_address' => $get(['direccion', 'address', 'dir ']),
                    'recipient_city' => $get(['ciudad', 'city']),
                    'recipient_country' => $get(['pais', 'country', 'nacionalidad']),
                    'recipient_email' => $get(['email', 'correo', 'e-mail']),
                    'notes' => $get(['nota', 'note', 'referencia', 'ref', 'orden', 'pedido']),
                    'service_type' => 'Terrestre',
                    'delivery_mode' => 'standard',
                ];
            }
        }

        return ['shipments' => $shipments, 'warnings' => $warnings, 'missing_columns' => $missing, 'source' => 'heuristic'];
    }

    private function jsonOrEmpty(array $data): string
    {
        $clean = array_filter($data, fn ($v) => $v !== null && $v !== '');
        return $clean ? json_encode($clean, JSON_UNESCAPED_UNICODE) : 'ninguno';
    }
}

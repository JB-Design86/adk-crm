<?php

namespace App\Http\Controllers;

use App\Services\WebsiteIntakeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;

/**
 * Website-Eingang (POST /api/eingang): Kontaktformular und Kursheft-Anforderung der Website.
 *
 * Die Website signiert den unveränderten Inhalt der Anfrage mit HMAC-SHA256 (gemeinsamer Schlüssel,
 * config services.website.intake_secret) und schickt die Signatur als Hex im Kopf X-ADK-Signatur.
 * Antwort 200 mit ok=true heißt für die Website „übergeben“. Inhalt und Schlüssel werden nie protokolliert.
 */
class WebsiteIntakeController extends Controller
{
    /** Größte angenommene Anfrage in Byte. */
    private const MAX_BYTES = 65536;

    public function __invoke(Request $request, WebsiteIntakeService $service): JsonResponse
    {
        $secret = (string) config('services.website.intake_secret');

        if (blank($secret)) {
            return $this->fail('Schnittstelle nicht eingerichtet.', 503);
        }

        $body = $request->getContent();

        if (strlen($body) > self::MAX_BYTES) {
            return $this->fail('Anfrage zu groß.', 413);
        }

        $signature = strtolower(trim((string) $request->header('X-ADK-Signatur')));

        if ($signature === '' || ! hash_equals(hash_hmac('sha256', $body, $secret), $signature)) {
            return $this->fail('Signatur ungültig.', 401);
        }

        try {
            $data = json_decode($body, true, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return $this->fail('Kein gültiges JSON.', 400);
        }

        if (! is_array($data)) {
            return $this->fail('Kein gültiges JSON.', 400);
        }

        $validator = Validator::make($data, $this->rules(is_string($data['art'] ?? null) ? $data['art'] : null));

        // Nur die Feldnamen zurückgeben, keine Werte.
        if ($validator->fails()) {
            return $this->json(['ok' => false, 'fehler' => 'Angaben unvollständig.', 'felder' => array_keys($validator->errors()->messages())], 422);
        }

        $result = $service->receive($validator->validated());

        if ($result['gesperrt']) {
            return $this->json(['ok' => true, 'crm_id' => null, 'gesperrt' => true]);
        }

        return $this->json(['ok' => true, 'crm_id' => $result['crm_id']]);
    }

    /** @return array<string, mixed> */
    private function rules(?string $art): array
    {
        $common = [
            'art' => ['required', 'string', Rule::in(['kontakt', 'kursheft'])],
            'vorgang_id' => ['required', 'integer', 'min:1'],
            'email' => ['required', 'string', 'email', 'max:190'],
            // Rückruf erlaubt nur mit Telefonnummer.
            'telefon' => ['nullable', 'string', 'max:40', 'required_if_accepted:tel_ok'],
            'tel_ok' => ['required', 'boolean'],
            'tel_ok_text' => ['nullable', 'string', 'max:500'],
            'quelle' => ['nullable', 'string', 'max:60'],
            'seite' => ['nullable', 'string', 'max:255'],
        ];

        return $common + match ($art) {
            'kontakt' => [
                'anliegen' => ['required', 'string', Rule::in(array_keys(config('adk.website_intake.anliegen')))],
                'name' => ['required', 'string', 'max:120'],
                'nachricht' => ['nullable', 'string', 'max:4000'],
                'erstellt' => ['required', 'date'],
                'ip_gekuerzt' => ['nullable', 'string', 'max:45'],
            ],
            'kursheft' => [
                // Ältere Anforderungen haben teils keinen Namen.
                'name' => ['nullable', 'string', 'max:120'],
                'finanzierung' => ['nullable', 'string', Rule::in(array_keys(config('adk.website_intake.finanzierung')))],
                'kontakt_text' => ['nullable', 'string', 'max:500'],
                'angefragt' => ['required', 'date'],
                'angefragt_ip' => ['nullable', 'string', 'max:45'],
                'bestaetigt' => ['required', 'date'],
                'bestaetigt_ip' => ['nullable', 'string', 'max:45'],
            ],
            default => [],
        };
    }

    private function fail(string $message, int $status): JsonResponse
    {
        return $this->json(['ok' => false, 'fehler' => $message], $status);
    }

    /** @param array<string, mixed> $data */
    private function json(array $data, int $status = 200): JsonResponse
    {
        return response()->json($data, $status, [], JSON_UNESCAPED_UNICODE);
    }
}

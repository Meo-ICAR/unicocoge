<?php

namespace App\Http\Controllers\Api;

use App\Console\Commands\StatusCheckCommand;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;

/**
 * Espone a UnicoBPM lo stato dei controlli (comandi che estendono StatusCheckCommand):
 * solo valore e severity, senza inviare nulla. La whitelist è implicita: sono interrogabili
 * esclusivamente i comandi di questo tipo.
 */
class CheckStatusApiController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'checks' => collect($this->checkCommands())->map(fn (StatusCheckCommand $command) => $command->getDescription())->all(),
        ]);
    }

    public function show(Request $request, string $command): JsonResponse
    {
        $check = $this->checkCommands()[$command] ?? null;

        if (! $check) {
            return response()->json(['message' => "Check '{$command}' non disponibile."], 404);
        }

        // Intestazione X-Check-Recipient: l'email del destinatario, così non finisce negli indirizzi registrati dal server.
        $recipient = trim((string) $request->header('X-Check-Recipient'));
        $status = $recipient !== '' ? $check->checkStatusForRecipient($recipient) : $check->checkStatus();

        return response()->json(['command' => $command, ...$status->toArray()]);
    }

    /**
     * @return array<string, StatusCheckCommand>
     */
    private function checkCommands(): array
    {
        return array_filter(Artisan::all(), fn ($command) => $command instanceof StatusCheckCommand);
    }
}

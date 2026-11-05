<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Services\ModelFieldIntrospector;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;

class LatestModelRecordApiController extends Controller
{
    /**
     * Restituisce l'ultimo record creato di un modello (ordinato per
     * created_at, o per chiave primaria se la tabella non ha created_at),
     * così un consumatore esterno come UnicoBPM può sapere fino a dove è
     * arrivata l'ultima sincronizzazione (es. l'ultima registration_date
     * di sales_invoice).
     */
    public function show(Request $request, string $model, ModelFieldIntrospector $introspector): JsonResponse
    {
        try {
            $modelClass = $introspector->resolveModelClass($model);
            $columnNames = collect($introspector->columns($model))->pluck('name');
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 404);
        }

        $instance = new $modelClass;
        $hasCreatedAt = Schema::connection($instance->getConnectionName())
            ->hasColumn($instance->getTable(), 'created_at');

        // `?order_by=registration_date`: ordina per un'altra colonna del modello (es. la data della fattura, non quella di caricamento).
        $orderBy = (string) $request->query('order_by', '');

        if ($orderBy !== '' && ! $columnNames->contains($orderBy)) {
            return response()->json(['message' => "Colonna '{$orderBy}' non disponibile per il modello."], 422);
        }

        $record = $modelClass::query()
            ->orderByDesc($orderBy !== '' ? $orderBy : ($hasCreatedAt ? 'created_at' : $instance->getKeyName()))
            ->first();

        if (! $record) {
            return response()->json(['message' => 'Nessun record trovato.'], 404);
        }

        return response()->json([
            'model' => $model,
            'id' => $record->getKey(),
            'fields' => $record->only($columnNames->all()),
        ]);
    }
}

<?php

return [
    /*
     * |--------------------------------------------------------------------------
     * | Comandi Artisan schedulabili via API
     * |--------------------------------------------------------------------------
     * |
     * | Whitelist dei comandi Artisan che un sistema esterno (es. uno scheduler
     * | BPM) può lanciare tramite il bridge in routes/api.php. Solo i comandi
     * | elencati qui possono essere eseguiti, e solo con le opzioni indicate:
     * | qualunque altro nome di comando o opzione viene rifiutato dal
     * | controller (vedi App\Http\Controllers\Api\CommandDispatchApiController).
     * |
     */
    'pratiche:import-api' => [
        'description' => 'Importa pratiche dall\'API MediaFacile.',
        'options' => ['start-date', 'end-date'],
    ],
    'provvigioni:import-api' => [
        'description' => 'Importa/aggiorna le provvigioni dall\'API MediaFacile.',
        'options' => ['start-date', 'end-date'],
    ],
    'import:daily' => [
        'description' => 'Import giornaliero combinato (pratiche + provvigioni).',
        'options' => ['start-date', 'end-date'],
    ],
    'primanota:generate' => [
        'description' => 'Genera le prime note dalle regole configurate in PrimaNotaConfig.',
        'options' => [],
    ],
    'coge:sync-monthly' => [
        'description' => 'Invia a Business Central l\'aggregato mensile della primanota provvigionale.',
        'options' => ['month'],
    ],
    'coge:sync-primenote' => [
        'description' => 'Sincronizza con Business Central le PrimaNotaEntry non ancora inviate.',
        'options' => ['limit', 'id'],
    ],
    'proformas:match-invoices' => [
        'description' => 'Riconcilia le fatture attive con i proforma emessi.',
        'options' => ['force', 'stats-only'],
    ],
    'proformas:match-purchase-invoices' => [
        'description' => 'Riconcilia le fatture passive con i proforma emessi.',
        'options' => ['force', 'stats-only'],
    ],
    'sales-invoices:match' => [
        'description' => 'Riconciliazione fatture di vendita (variante).',
        'options' => ['dry-run', 'clear'],
    ],
    'parse:product-description' => [
        'description' => 'Normalizza le descrizioni prodotto delle pratiche importate.',
        'options' => [],
    ],
    'vcoge:calculate' => [
        'description' => 'Ricalcola le provvigioni finanziarie (azione "Ricalcola" di admin/vcoges). Con l\'opzione invia manda anche in contabilità la primanota del mese precedente (o di month).',
        'options' => ['invia', 'month'],
    ],
    'venasarco-trimestre:calculate' => [
        'description' => 'Ricalcola i contributi trimestrali ENASARCO (azione "Ricalcola contributi" di admin/venasarco-trimestres), seguito dal ricalcolo di venasarcotot.',
        'options' => [],
    ],
    'venasarcotot:calculate' => [
        'description' => 'Ricalcola i totali ENASARCO e FIRR (azione "Ricalcola contributi" di admin/venasarcotots).',
        'options' => [],
    ],
    'proformas:send-pending' => [
        'description' => 'Invia ai produttori i proforma in stato Inserito non ancora spediti; cc = indirizzi in copia conoscenza (separati da virgola).',
        'options' => ['cc', 'dry-run', 'genera'],
    ],
    'proformas:generate-pending' => [
        'description' => 'Crea i proforma dalle provvigioni maturate dei produttori (tipo Agente, non di coordinamento, stato Inserito) e vi associa le provvigioni; dry-run = elenca senza scrivere; solo-attivi = 1 (predefinito) solo produttori attivi, 0 anche i non attivi.',
        'options' => ['dry-run', 'solo-attivi'],
    ],
    'manual:sync' => [
        'description' => 'Reindicizza il manuale operativo per l\'assistente AI.',
        'options' => [],
    ],
];

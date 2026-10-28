<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Mail;

class Proforma extends Model
{
    use SoftDeletes;

    /**
     * Tabella di Proforma: database dell'applicazione (le tabelle condivise di unico-core usano la connessione `core`).
     */
    protected $connection = 'mysql';

    /**
     * The table associated with the model.
     *
     * @var string
     */
    protected $table = 'proformas';

    /**
     * The attributes that are mass assignable.
     *
     * @var array
     */
    protected $fillable = [
        'stato',
        'fornitori_id',
        'client_id',
        'anticipo',
        'anticipo_descrizione',
        'compenso',
        'compenso_descrizione',
        'contributo',
        'contributo_descrizione',
        'welcome',
        'welcome_description',
        'spese',
        'spese_description',
        'annotation',
        'emailsubject',
        'emailto',
        'emailbody',
        'emailfrom',
        'sended_at',
        'paid_at',
        'delta',
        'anticipo_residuo',
        'delta_annotation',
        'proforma_id',
        'tipo',
        'vat_number',
        'invoiceable_id',
        'invoiceable_type',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'anticipo' => 'decimal:2',
        'anticipo_residuo' => 'decimal:2',
        'compenso' => 'decimal:2',
        'contributo' => 'decimal:2',
        'welcome' => 'decimal:2',
        'spese' => 'decimal:2',
        'delta' => 'decimal:2',
        'sended_at' => 'datetime',
        'paid_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    /**
     * Proforma abbinabili a una fattura di acquisto: sono quelle del fornitore con la stessa partita IVA della fattura (anche se il fornitore è stato
     * eliminato), non precedenti alla fattura, cioè inviate in una data non successiva a quella di registrazione.
     */
    public function scopeAbbinabiliAFatturaAcquisto(Builder $query, PurchaseInvoice $fattura): Builder
    {
        return $query
            ->where('proformas.tipo', 'Agente')
            ->whereIn('proformas.fornitori_id', Fornitore::withoutGlobalScopes()->where('piva', $this->partitaIvaOrNull($fattura->vat_number))->select('id'))
            ->whereNotNull('proformas.sended_at')
            ->whereDate('proformas.sended_at', '<=', $fattura->registration_date ?? '1900-01-01');
    }

    /**
     * Proforma abbinabili a una fattura di vendita: sono quelle dell'istituto (cliente) con la stessa partita IVA della fattura, purché non sia un
     * cliente fittizio (`is_dummy`), e inviate in una data non successiva a quella di registrazione.
     */
    public function scopeAbbinabiliAFatturaVendita(Builder $query, SalesInvoice $fattura): Builder
    {
        return $query
            ->where('proformas.tipo', 'Istituto')
            ->whereIn('proformas.fornitori_id', Clienti::withoutGlobalScopes()->where('piva', $this->partitaIvaOrNull($fattura->vat_number))->where('is_dummy', false)->select('id'))
            ->whereNotNull('proformas.sended_at')
            ->whereDate('proformas.sended_at', '<=', $fattura->registration_date ?? '1900-01-01');
    }

    /** Una partita IVA vuota non deve mai coincidere con i fornitori o i clienti che non ce l'hanno. */
    private function partitaIvaOrNull(?string $partitaIva): string
    {
        return filled($partitaIva) ? trim($partitaIva) : "\0";
    }

    public function getTotaleAttribute()
    {
        return $this->compenso + $this->anticipo + $this->contributo
            + $this->welcome + $this->spese + $this->delta;
    }

    /**
     * Get the fornitore that owns the proforma.
     */
    public function fornitore()
    {
        return $this->belongsTo(Fornitore::class, 'fornitori_id');
    }

    public function cliente()
    {
        return $this->belongsTo(Clienti::class, 'fornitori_id');
    }

    /**
     * Get the client/consulente (clients table) that owns the proforma, when applicable.
     */
    public function client()
    {
        return $this->belongsTo(Client::class, 'client_id');
    }

    /**
     * Get the invoice that this proforma belongs to (polymorphic).
     */
    public function salesInvoice()
    {
        return $this->morphTo(SalesInvoice::class, 'sales');
    }

    public function purchasesInvoice()
    {
        return $this->morphTo(PurchaseInvoice::class, 'purchases');
    }

    protected $with = ['fornitore'];

    /**
     * The model's default values for attributes.
     *
     * @var array
     */
    protected $attributes = [
        'stato' => 'Inserito',
    ];

    /**
     * Get the provvigioni for the proforma.
     */
    public function provvigioni()
    {
        return $this->hasMany(Provvigione::class, 'proforma_id');
    }

    protected static function booted()
    {
        static::deleting(function ($proforma) {
            // Update all related provvigioni
            $proforma->provvigioni()->update([
                'stato' => 'Inserito',
                'proforma_id' => null,
            ]);
        });

        // Garantisce che vat_number sia sempre valorizzato dalla P.IVA della
        // controparte effettiva, qualunque sia il punto di creazione: non ci
        // si affida al fatto che ogni singolo call site (createFromFornitore,
        // createFromIstitutoFinanziario, azioni Filament, ecc.) lo imposti
        // esplicitamente. Non sovrascrive un vat_number passato esplicitamente.
        static::creating(function (Proforma $proforma) {
            if (filled($proforma->vat_number)) {
                return;
            }

            $proforma->vat_number = $proforma->fornitore?->piva
                ?? $proforma->cliente?->piva
                ?? $proforma->client?->vat_number;
        });
    }

    /**
     * Scope a query to only include sent proformas.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopeSent($query)
    {
        return $query->whereNotNull('sended_at');
    }

    /**
     * Scope a query to only include paid proformas.
     *
     * @param  Builder  $query
     * @return Builder
     */
    public function scopePaid($query)
    {
        return $query->whereNotNull('paid_at');
    }

    /**
     * Scope a query to only include proformas with a specific status.
     *
     * @param  Builder  $query
     * @param  string  $status
     * @return Builder
     */
    public function scopeWithStatus($query, $status)
    {
        return $query->where('stato', $status);
    }

    /**
     * Create a new proforma from a fornitore
     */
    public static function createFromFornitore(string $fornitori_id, bool $coordinamento): Proforma
    {
        $fornitore = Fornitore::with('company')->findOrFail($fornitori_id);

        $proformaData = [
            'fornitori_id' => $fornitori_id,
            'tipo' => 'Uscita',
            'vat_number' => $fornitore->piva,
            'anticipo' => $fornitore->anticipo,
            'anticipo_descrizione' => $fornitore->anticipo_description,
            'anticipo_residuo' => $fornitore->anticipo_residuo,
            'compenso_descrizione' => $fornitore->company->compenso_descrizione ?? 'Compenso',
            'contributo' => $fornitore->contributo,
            'contributo_descrizione' => $fornitore->contributo_description,
            'emailsubject' => 'Proforma - '.$fornitore->name,
            'emailto' => $fornitore->email,
            'emailfrom' => $fornitore->company->emailfrom ?? 'proforma@hassisto.eu',
            'stato' => 'Inserito',
            'compenso' => 0,
            'tipo' => 'Agente',
            'vat_number' => $fornitore->piva,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        if ($coordinamento) {
            $proformaData['contributo'] = 0;
        }

        if ($fornitore->company) {
            $proformaData['company_id'] = $fornitore->company->id;
            // Use company's email subject if available
            if (! empty($fornitore->company->emailsubject)) {
                $proformaData['emailfrom'] = $fornitore->company->emailfrom;
            }
        }

        return self::create($proformaData);
    }

    public static function createFromIstitutoFinanziario(string $istituto_finanziario, string $del): Proforma
    {
        $fornitore = Clienti::with('company')->where('name', '=', $istituto_finanziario)->firstOrFail();
        $fornitoreId = $fornitore->id;

        $exists = Proforma::where('fornitori_id', '=', $fornitoreId)->where('sended_at', '=', $del)->first();
        if ($exists != null) {
            return $exists;
        }
        $proformaData = [
            'fornitori_id' => $fornitoreId,
            'sended_at' => $del,
            //  'tipo' =>'Entrata',
            'vat_number' => $fornitore->piva,
            'emailsubject' => 'Proforma - '.$istituto_finanziario.' - Del '.$del,
            //  'emailto' => $fornitore->email,
            'emailfrom' => $fornitore->company->emailfrom ?? 'proforma@hassisto.eu',
            'stato' => 'Inserito',
            'compenso' => 0,
            'compenso_descrizione' => 'Provvigioni attive',
            'tipo' => 'Istituto',
            'vat_number' => $fornitore->piva,
            'created_at' => now(),
            'updated_at' => now(),
        ];
        $proforma = self::create($proformaData);

        return $proforma;
    }

    /**
     * Abbina le provvigioni attive all'ultimo proforma dell'istituto emesso alla data (lo crea se manca) e ne somma l'importo al compenso.
     *
     * @param  iterable<Provvigione>  $provvigioni
     */
    public static function associaProvvigioni(iterable $provvigioni, string $dataEmissione): ?Proforma
    {
        $proforma = null;

        foreach ($provvigioni as $provvigione) {
            $proforma = self::createFromIstitutoFinanziario($provvigione->istituto_finanziario, $dataEmissione);
            $provvigione->update([
                'stato' => 'Proforma',
                'proforma_id' => $proforma->id,
                'piva' => $proforma->vat_number,
            ]);
            $proforma->update(['compenso' => $proforma->compenso + $provvigione->importo]);
        }

        return $proforma;
    }

    /**
     * Find or create a proforma for a fornitore by P.IVA and return its ID
     *
     * @param  string  $piva  The VAT number to search for
     * @return string The ID of the found or created proforma
     *
     * @throws ModelNotFoundException If no fornitore is found with the given P.IVA
     */
    public static function findOrCreateByPiva(string $piva, float $importo, bool $coordinamento): string
    {
        try {
            \Log::info('Finding or creating proforma for P.IVA: '.$piva);
            // Clean up the P.IVA
            $cleanedPiva = str_replace(' ', '', $piva);

            // Find fornitore by P.IVA (case insensitive and ignoring spaces)
            // Include soft-deleted records in case the fornitore was deleted
            $fornitore = Fornitore::withTrashed()
                ->where(function ($query) use ($cleanedPiva) {
                    $query
                        ->where('piva', $cleanedPiva)
                        ->orWhereRaw("REPLACE(piva, ' ', '') = ?", [$cleanedPiva]);
                })
                ->first();

            if (! $fornitore) {
                \Log::error("No fornitore found with P.IVA: {$piva} (cleaned: {$cleanedPiva})");
                throw new ModelNotFoundException(
                    "No query results for model [App\Models\Fornitore] with P.IVA: {$piva}"
                );
            }

            // If the fornitore was soft-deleted, restore it
            if ($fornitore->trashed()) {
                \Log::info("Restoring soft-deleted fornitore with P.IVA: {$piva}");
                $fornitore->restore();
            }

            // Check if there's already a proforma in 'Inserito' status for this fornitore
            $existingProforma = self::where('fornitori_id', $fornitore->id)
                ->where('stato', 'Inserito')
                ->first();

            if (! $existingProforma) {
                $existingProforma = self::createFromFornitore($fornitore->id, $coordinamento);
            }

            // Update the compenso
            $compenso = $existingProforma->compenso;
            $existingProforma->update([
                'compenso' => $importo + $compenso,
            ]);

            \Log::info('Returning proforma ID: '.$existingProforma->id);

            return $existingProforma->id;
        } catch (\Exception $e) {
            \Log::error('Error in findOrCreateByPiva: '.$e->getMessage());
            \Log::error('Stack trace: '.$e->getTraceAsString());
            throw $e;  // Re-throw to maintain the same behavior
        }
    }

    public function getProformanomeAttribute()
    {
        return $this->emailsubject.' #'.$this->id;
    }

    /**
     * Send the proforma via email
     *
     * @param  bool  $preview  Simulazione: scrive a chi è loggato, non aggiorna lo stato.
     * @param  list<string>  $extraCc  Indirizzi in copia conoscenza oltre a quello della company.
     * @return bool
     *
     * @throws \Exception
     */
    public function inviaEmail($preview = false, array $extraCc = [])
    {
        try {
            $proforma = $this->load(['provvigioni.pratica']);
            $ccEmail = null;
            $bccEmail = null;
            $cr = "\n";
            $somma = 0;
            $debug = $preview;
            $debug = true;
            $message = '';

            if (! $preview) {
                // Get the recipient email from the related fornitore
                $toEmail = $this->fornitore->email;

                if (empty($toEmail)) {
                    throw new \Exception('Indirizzo email assente per il fornitore '.$this->fornitore->name);
                }
                // Get the first company record for CC
                $company = Company::first();
                $ccEmail = $company ? $company->email_cc : null;
                $bccEmail = $company ? $company->email_bcc : null;
            }

            if (! $preview) {
                // Alla copia conoscenza della company si aggiungono quelle richieste da chi spedisce (es. il processo mensile).
                $ccEmail = array_values(array_unique(array_filter(array_merge((array) $ccEmail, $extraCc)))) ?: null;
            }

            if ($preview) {
                // Get the logged-in user's email
                $loggedInUserEmail = auth()->user()?->email;
                $toEmail = $loggedInUserEmail ?? 'hassistosrl@gmail.com';
                $message .= 'Simulazione email da inviarsi a email: '.$this->emailto.$cr;
            }

            // Prepare email details
            $subject = "Proforma #{$this->id} - {$this->fornitore->name}";
            $message .= "Proforma #{$this->id}";

            if ($this->compenso != 0) {
                $n = 0;
                $message .= $cr.$this->compenso_descrizione.': €'.number_format($this->compenso, 2);
                //   $somma += $this->compenso;

                foreach ($this->provvigioni as $provvigione) {
                    $n++;
                    $message .= "\n- ".$n
                        .'.  '.$provvigione->id_pratica
                        .' - '.$provvigione->id
                        .' - '.(optional($provvigione->pratica)->cognome_cliente ?? 'N/A')
                        .' - '.(optional($provvigione->pratica)->nome_cliente ?? 'N/A')
                        .': €'.number_format($provvigione->importo, 2);
                    $somma += $provvigione->importo;
                }
            }
            if ($this->anticipo != 0) {
                $anticipo2 = $this->anticipo;
                $somma -= $this->anticipo;
                if ($anticipo2 < 0) {
                    $anticipo2 = -$anticipo2;
                }
                $message .= $cr.$this->anticipo_descrizione.': €'.number_format($anticipo2, 2);
            }

            if ($this->contributo != 0) {
                $message .= $cr.$this->contributo_descrizione.': €'.number_format($this->contributo, 2);
                $somma += $this->contributo;
            }

            if ($this->welcome != 0) {
                $message .= $cr.$this->welcome_description.': €'.number_format($this->welcome, 2);
                $somma += $this->welcome;
            }

            if ($this->spese != 0) {
                $message .= $cr.$this->spese_description.': €'.number_format($this->spese, 2);
                $somma += $this->spese;
            }

            $message .= $cr.'TOTALE LORDO € '.number_format($somma, 2);

            if (! empty($this->annotation)) {
                $message .= $cr.'Note: '.$this->annotation;
            }
            if ($this->anticipo < 0) {
                $subject = "Anticipo #{$this->id} - {$this->fornitore->name} - Totale: € ".number_format($somma, 2);
            } else {
                $subject = "Proforma #{$this->id} - {$this->fornitore->name} - Totale: € ".number_format($somma, 2);
            }

            // Send the email
            $mail = Mail::to($toEmail);

            if (! $preview) {
                if ($ccEmail) {
                    $mail->cc($ccEmail);
                }
                if ($bccEmail) {
                    $mail->bcc($bccEmail);
                }
            }

            // Update the proforma status
            $this->update([
                'emailsubject' => $subject,
                'emailto' => $toEmail,
                'emailbody' => $message,
                //  'emailfrom' => $ccEmail,
            ]);

            Mail::send('emails.proforma', [
                'proforma' => $this,
                'content' => $message,
                'somma' => $somma,
                'preview' => $preview,
            ], function ($message) use ($toEmail, $subject, $ccEmail, $bccEmail, $preview) {
                $message
                    ->to($toEmail)
                    ->subject($subject);

                if (! $preview) {
                    if ($ccEmail) {
                        $message->cc($ccEmail);
                    }
                    if ($bccEmail) {
                        $message->bcc($bccEmail);
                    }
                }
            });

            if (! $preview) {
                // \Log::info('Updating proforma status after email send for ID: ' . $this->id);
                $this->update([
                    'sended_at' => now(),
                    'stato' => 'Inviato',
                    'data_invio' => now(),
                ]);
                // Update fornitore's anticipo_residuo
                if ($this->fornitore) {
                    $this->fornitore->increment('anticipo_residuo', -$this->anticipo);
                    \Log::info('Updated anticipo_residuo for fornitore ID: '.$this->fornitore->id
                        .' by '.$this->anticipo
                        .'. New value: '.$this->fornitore->anticipo_residuo);
                }
            }
            if ($preview) {
                \Log::info('NOT updated anticipo_residuo for fornitore ID: '.$this->fornitore->name
                    .' by '.$this->anticipo
                    .'. New value: '.$this->fornitore->anticipo_residuo - $this->anticipo);
            }

            return true;
        } catch (\Exception $e) {
            \Log::error("Errore durante l'invio del proforma #{$this->id}: ".$e->getMessage());

            return false;
        }
    }

    /**
     * Send the proforma via email
     *
     * @param  string  $email  Recipient email address
     * @param  string|null  $subject  Optional custom subject
     * @param  string|null  $message  Optional custom message
     * @return bool
     */
    public function testEmail($email = null, $subject = null, $message = null)
    {
        try {
            // Ensure relationships are loaded
            if (! $this->relationLoaded('fornitore')) {
                $this->load('fornitore');
            }
            $email = 'piergiuseppe.meo@gmail.com';
            $subject = $subject ?? "Test Proforma #{$this->id}";
            $message = $message ?? "This is a test email for Proforma #{$this->id}";
            // Log what we're about to do
            \Log::info("Sending test email for Proforma #{$this->id} to {$email}");
            // Simple email without using the view
            Mail::raw($message, function ($message) use ($email, $subject) {
                $message
                    ->to($email)
                    ->subject($subject);
            });
            \Log::info("Test email sent successfully to {$email}");

            return true;
        } catch (\Exception $e) {
            \Log::error('Failed to send test email: '.$e->getMessage());

            return false;
        }
    }

    public function primaNotaEntries(): MorphMany
    {
        return $this->morphMany(PrimaNotaEntry::class, 'record');
    }
}

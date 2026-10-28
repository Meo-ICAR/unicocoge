<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Unico\Core\Models\Cliente as CoreCliente;
use Unico\Core\Models\Document;

/**
 * @property string $id
 * @property string|null $cf
 * @property string|null $coge
 * @property string|null $codice
 * @property string|null $name
 * @property string|null $nome
 * @property string|null $piva
 * @property string|null $email
 * @property string|null $regione
 * @property string|null $citta
 * @property string $company_id
 * @property int|null $customertype_id
 * @property Carbon|null $deleted_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Clienti extends CoreCliente
{
    use HasFactory, SoftDeletes;

    protected $orderBy = 'name';

    protected $orderDirection = 'asc';

    /**
     * The attributes that should be cast.
     *
     * @var array
     */
    protected $casts = [
        'id' => 'string',
        'company_id' => 'string',
        'is_active' => 'boolean',
        'is_dummy' => 'boolean',
        'deleted_at' => 'datetime',
    ];

    protected static function booted()
    {
        parent::booted();
        static::addGlobalScope('order_by_name', function ($builder) {
            $builder->orderBy('name');
        });

        static::creating(function ($clienti) {
            if (empty($clienti->id)) {
                $clienti->id = (string) Str::uuid();
            }
        });
    }

    /**
     * Istituti attivi e non fittizi privi di partita IVA (nulla o vuota).
     */
    public function scopeActiveWithoutPiva(Builder $query): Builder
    {
        return $query->where('is_active', true)
            ->where('is_dummy', false)
            ->where(fn (Builder $query) => $query->whereNull('piva')->orWhere('piva', ''));
    }

    /**
     * Documenti della banca (linguetta Documenti della scheda, dettagli inviati dalla banca). Non è un morphMany: gli indirizzi di questo
     * modello sono salvati con la classe come tipo, i documenti con l'alias del core (`cliente`, o la grafia storica `clienti`).
     */
    public function documents(): HasMany
    {
        return $this->hasMany(Document::class, 'documentable_id')
            ->whereIn('documentable_type', ['cliente', 'clienti'])
            ->withAttributes(['documentable_type' => 'cliente'], asConditions: false);
    }

    /**
     * Get the company that owns the client.
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * Get the customertype that owns the client.
     */
    public function proforma()
    {
        return $this->hasMany(Proforma::class, 'fornitori_id');
    }

    /**
     * Get all addresses for the clienti.
     */
    public function addresses(): MorphMany
    {
        return $this->morphMany(Address::class, 'addressable');
    }
}

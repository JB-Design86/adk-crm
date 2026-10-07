<?php

namespace App\Models;

use App\Support\Adk;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

class Lead extends Model
{
    use HasFactory, LogsActivity;

    /** Status nach der Akquise: Der Vorgang lebt als Förderfall bzw. Teilnehmerakte weiter. */
    public const AFTER_ACQUISITION = ['handed_over', 'enrolled'];

    protected $guarded = ['id'];

    protected $attributes = [
        'status' => 'new',
        'call_attempts' => 0,
        'cross_selling' => false,
    ];

    /** Uhrzeit der Wiedervorlage seit dem letzten Speichern ausdrücklich gesetzt (siehe booted()). */
    private bool $nextActionTimeAssigned = false;

    protected function casts(): array
    {
        return [
            'call_attempts' => 'integer',
            'next_action_at' => 'date',
            'cross_selling' => 'boolean',
            'cross_selling_follow_up_at' => 'date',
            'last_contact_at' => 'datetime',
            'closed_at' => 'datetime',
            'contracted_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Lead $lead) {
            // Eingehende Anfragen: Wiedervorlage am selben Tag.
            if ($lead->status === 'new' && $lead->next_action_at === null && Adk::isInbound($lead->channel)) {
                $lead->next_action_at = today();
            }

            $lead->last_contact_at ??= now();
        });

        // Die Uhrzeit gehört zum Datum: neues Datum ohne neue Uhrzeit heißt „irgendwann am Tag“.
        static::saving(function (Lead $lead) {
            if ($lead->next_action_at === null || ($lead->isDirty('next_action_at') && ! $lead->nextActionTimeAssigned)) {
                $lead->next_action_time = null;
            }
        });

        static::saved(function (Lead $lead) {
            $lead->nextActionTimeAssigned = false;
        });
    }

    /** Uhrzeit für einen Rückruf zur Wiedervorlage, z. B. „07:00“. Gespeichert mit Sekunden wie in MariaDB. */
    protected function nextActionTime(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : substr($value, 0, 5),
            set: function (mixed $value) {
                $this->nextActionTimeAssigned = true;

                return blank($value) ? null : Carbon::parse($value)->format('H:i:00');
            },
        );
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('leads')
            ->logAll()
            ->logExcept(['created_at', 'updated_at'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function importLog(): BelongsTo
    {
        return $this->belongsTo(ImportLog::class);
    }

    public function activities(): HasMany
    {
        return $this->hasMany(Activity::class)->latest('occurred_at');
    }

    public function appointments(): HasMany
    {
        return $this->hasMany(Appointment::class)->orderBy('starts_at');
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    public function participants(): HasMany
    {
        return $this->hasMany(Participant::class);
    }

    public function fundingCase(): HasOne
    {
        return $this->hasOne(FundingCase::class);
    }

    /** Förderweg passend zur Zielgruppe, oder null (z. B. „Betrieb, Zuordnung offen“). */
    public function fundingPathway(): ?string
    {
        foreach (config('adk.funding_pathways') as $key => $pathway) {
            if (in_array($this->target_group, $pathway['target_groups'], true)) {
                return $key;
            }
        }

        return null;
    }

    public function scopeOpen(Builder $query): Builder
    {
        return $query->whereNull('closed_at');
    }

    /** Offen und noch in der Akquise, also weder Förderfall noch Teilnehmer. */
    public function scopeInAcquisition(Builder $query): Builder
    {
        return $query->open()->whereNotIn('status', self::AFTER_ACQUISITION);
    }

    public function isInAcquisition(): bool
    {
        return $this->isOpen() && ! in_array($this->status, self::AFTER_ACQUISITION, true);
    }

    public function scopeInbound(Builder $query): Builder
    {
        return $query->whereIn('channel', Adk::inboundChannels());
    }

    /** Offen und Wiedervorlage heute oder überfällig. */
    public function scopeDue(Builder $query): Builder
    {
        return $query->open()->whereDate('next_action_at', '<=', today());
    }

    /** Rückruf mit Uhrzeit, deren Zeitpunkt erreicht ist: heute ab der Uhrzeit oder von einem früheren Tag. */
    public function scopeCallbackDue(Builder $query): Builder
    {
        $date = $query->qualifyColumn('next_action_at');
        $time = $query->qualifyColumn('next_action_time');

        return $query->whereNotNull($time)->where(fn (Builder $q) => $q
            ->whereDate($date, '<', today())
            ->orWhere(fn (Builder $t) => $t->whereDate($date, today())->where($time, '<=', now()->format('H:i:s'))));
    }

    /** Sortierung: fällige Rückrufe mit Uhrzeit zuerst (gleiche Bedingung wie scopeCallbackDue). */
    public function scopeOrderByCallbackDue(Builder $query): Builder
    {
        $date = $query->qualifyColumn('next_action_at');
        $time = $query->qualifyColumn('next_action_time');

        return $query->orderByRaw(
            "CASE WHEN {$time} IS NOT NULL AND (DATE({$date}) < ? OR (DATE({$date}) = ? AND {$time} <= ?)) THEN 0 ELSE 1 END",
            [today()->toDateString(), today()->toDateString(), now()->format('H:i:s')],
        );
    }

    public function isOpen(): bool
    {
        return $this->closed_at === null;
    }

    public function isInbound(): bool
    {
        return Adk::isInbound($this->channel);
    }

    public function isNewInbound(): bool
    {
        return $this->status === 'new' && $this->isInbound();
    }

    public function isOverdue(): bool
    {
        return $this->isOpen() && $this->next_action_at !== null && $this->next_action_at->lt(today());
    }

    /** Zeitpunkt des Rückrufs (Datum und Uhrzeit), null ohne Uhrzeit. */
    public function nextActionDueAt(): ?CarbonImmutable
    {
        if ($this->next_action_at === null || $this->next_action_time === null) {
            return null;
        }

        return CarbonImmutable::parse($this->next_action_at->format('Y-m-d').' '.$this->next_action_time);
    }

    /** Offener Vorgang mit Rückruf zu einer Uhrzeit, die erreicht ist. */
    public function isCallbackDue(): bool
    {
        $dueAt = $this->nextActionDueAt();

        return $this->isOpen() && $dueAt !== null && $dueAt->lte(now());
    }

    /** Wiedervorlage zur Anzeige: „08.10.2026, 07:00 Uhr“ oder „08.10.2026“. */
    public function nextActionLabel(): ?string
    {
        if ($this->next_action_at === null) {
            return null;
        }

        return $this->next_action_at->format('d.m.Y').($this->next_action_time ? ", {$this->next_action_time} Uhr" : '');
    }

    public function isPrivatePerson(): bool
    {
        return $this->organization_id === null || (bool) $this->contact?->is_private;
    }

    public function displayName(): string
    {
        if ($this->organization) {
            return $this->organization->name;
        }

        return $this->contact?->fullName() ?? "Vorgang {$this->id}";
    }

    /** Anzurufende Nummer: Kontakt vor Organisation. */
    public function phoneE164(): ?string
    {
        return $this->contact?->phone_e164 ?? $this->organization?->phone_e164;
    }

    public function phoneDisplay(): ?string
    {
        return $this->contact?->phone_display ?? $this->organization?->phone_display;
    }

    public function isBlocked(): bool
    {
        return (bool) $this->organization?->isBlocked() || (bool) $this->contact?->isBlocked();
    }

    /**
     * Grund, warum nicht angerufen werden darf, oder null.
     * Privatpersonen nur mit Einwilligung oder bei eingehender Anfrage.
     */
    public function callBlockReason(): ?string
    {
        if ($this->isBlocked()) {
            return 'Die Nummer oder die Firma steht auf der Sperrliste. Ein Anruf ist nicht möglich.';
        }

        if ($this->contact?->phone_refused) {
            return 'Die Person möchte nicht angerufen werden (Website-Formular). Bitte per E-Mail antworten.';
        }

        if ($this->isPrivatePerson() && ! $this->isInbound() && ! $this->contact?->hasPhoneConsent()) {
            return 'Privatperson ohne eingetragene Einwilligung in die Telefonansprache. Ein Anruf ist nicht möglich.';
        }

        if ($this->phoneE164() === null) {
            return 'Es ist keine Telefonnummer hinterlegt.';
        }

        return null;
    }

    public function isCallable(): bool
    {
        return $this->callBlockReason() === null;
    }
}

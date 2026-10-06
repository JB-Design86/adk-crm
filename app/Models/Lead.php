<?php

namespace App\Models;

use App\Support\Adk;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
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

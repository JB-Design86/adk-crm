<?php

namespace App\Models;

use App\Support\Brand;
use Database\Factories\UserFactory;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthentication;
use Filament\Auth\MultiFactor\App\Contracts\HasAppAuthenticationRecovery;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;
use Spatie\Activitylog\Models\Concerns\LogsActivity;
use Spatie\Activitylog\Support\LogOptions;

#[Fillable(['name', 'email', 'password', 'role', 'brands', 'is_blocked', 'blocked_at'])]
#[Hidden(['password', 'remember_token', 'app_authentication_secret', 'app_authentication_recovery_codes'])]
class User extends Authenticatable implements FilamentUser, HasAppAuthentication, HasAppAuthenticationRecovery
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, LogsActivity, Notifiable;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'brands' => 'array',
            'is_blocked' => 'boolean',
            'blocked_at' => 'datetime',
            'last_login_at' => 'datetime',
            'app_authentication_secret' => 'encrypted',
            'app_authentication_recovery_codes' => 'encrypted:array',
        ];
    }

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()
            ->useLogName('users')
            ->logOnly(['name', 'email', 'role', 'brands', 'is_blocked'])
            ->logOnlyDirty()
            ->dontLogEmptyChanges();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        return ! $this->is_blocked;
    }

    public function hasPermission(string $permission): bool
    {
        $permissions = config("adk.roles.{$this->role}.permissions", []);

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function roleLabel(): string
    {
        return config("adk.roles.{$this->role}.label", $this->role);
    }

    /**
     * Marken, die das Konto sieht (Reihenfolge wie in config adk.brands). Leer = alle.
     *
     * @return list<string>
     */
    public function allowedBrands(): array
    {
        $all = Brand::keys();
        $chosen = array_values(array_intersect($all, (array) $this->brands));

        return $chosen === [] ? $all : $chosen;
    }

    /** Sieht das Konto nur einen Teil der Marken? Dann blendet das CRM Vorgänge der anderen aus. */
    public function isBrandRestricted(): bool
    {
        return count($this->allowedBrands()) < count(Brand::keys());
    }

    public function canUseBrand(?string $brand): bool
    {
        return in_array($brand, $this->allowedBrands(), true);
    }

    public function hasTwoFactor(): bool
    {
        return filled($this->app_authentication_secret);
    }

    public function block(): void
    {
        $this->forceFill(['is_blocked' => true, 'blocked_at' => now()])->save();

        // Gesperrte Konten dürfen nicht weiter über sipgate telefonieren oder aus ihren Postfächern senden.
        $this->sipgateConnection()->delete();
        $this->mailConnections()->delete();
    }

    public function unblock(): void
    {
        $this->forceFill(['is_blocked' => false, 'blocked_at' => null])->save();
    }

    public function sipgateConnection(): HasOne
    {
        return $this->hasOne(SipgateConnection::class);
    }

    /** Postfach der Standardmarke (ADK). Für eine bestimmte Marke: mailConnectionFor(). */
    public function mailConnection(): HasOne
    {
        return $this->hasOne(MailConnection::class)->where('brand', Brand::default());
    }

    public function mailConnections(): HasMany
    {
        return $this->hasMany(MailConnection::class);
    }

    /** Verbundenes Postfach einer Marke, z. B. contact@jb-design.net für JB IT-Design. */
    public function mailConnectionFor(?string $brand): ?MailConnection
    {
        return $this->mailConnections()->where('brand', $brand ?? Brand::default())->first();
    }

    public function assignedLeads(): HasMany
    {
        return $this->hasMany(Lead::class, 'assigned_to');
    }

    public function getAppAuthenticationSecret(): ?string
    {
        return $this->app_authentication_secret;
    }

    public function saveAppAuthenticationSecret(#[SensitiveParameter] ?string $secret): void
    {
        $this->app_authentication_secret = $secret;
        $this->save();
    }

    public function getAppAuthenticationHolderName(): string
    {
        return $this->email;
    }

    /** @return ?array<string> */
    public function getAppAuthenticationRecoveryCodes(): ?array
    {
        return $this->app_authentication_recovery_codes;
    }

    /** @param ?array<string> $codes */
    public function saveAppAuthenticationRecoveryCodes(#[SensitiveParameter] ?array $codes): void
    {
        $this->app_authentication_recovery_codes = $codes;
        $this->save();
    }
}

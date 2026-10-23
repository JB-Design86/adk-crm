<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\WebsiteCheck;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Throwable;

/**
 * Website-Check für Betriebe mit offenen Vorgängen der Marken aus config adk.website_check.brands
 * (JB IT-Design): noch nie oder vor mehr als recheck_days Tagen geprüft, je Lauf per_run Stück.
 * Läuft alle 5 Minuten über den Zeitplan (routes/console.php).
 */
class WebsiteCheckCommand extends Command
{
    protected $signature = 'adk:website-check
        {--anzahl= : Höchstens so viele Websites prüfen (Standard aus config adk.website_check.per_run)}';

    protected $description = 'Liest sichtbare PHP-Version und System der Websites offener Vorgänge (JB IT-Design)';

    public function handle(WebsiteCheck $check): int
    {
        $config = config('adk.website_check');
        $limit = (int) ($this->option('anzahl') ?: $config['per_run']);

        $organizations = Organization::query()
            ->whereNotNull('website')
            ->where('website', '!=', '')
            ->where(fn (Builder $q) => $q->whereNull('website_checked_at')->orWhere('website_checked_at', '<', now()->subDays($config['recheck_days'])))
            ->whereHas('leads', fn (Builder $q) => $q->whereNull('closed_at')->whereIn('brand', $config['brands']))
            ->orderByRaw('website_checked_at IS NOT NULL')
            ->orderBy('website_checked_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();

        foreach ($organizations as $organization) {
            try {
                $check->check($organization);
                $this->line("{$organization->id}: ".WebsiteCheck::summary($organization->refresh()));
            } catch (Throwable $exception) {
                report($exception);
                $this->error("{$organization->id}: {$exception->getMessage()}");
            }
        }

        $this->info("{$organizations->count()} Websites geprüft.");

        return self::SUCCESS;
    }
}

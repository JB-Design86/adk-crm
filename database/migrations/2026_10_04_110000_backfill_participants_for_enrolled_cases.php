<?php

use App\Models\FundingCase;
use App\Services\ParticipantService;
use Illuminate\Database\Migrations\Migration;

/**
 * Förderfälle, die vor Stufe 4 eingeschrieben wurden, bekommen nachträglich ihre Teilnehmerakte
 * (wie heute bei „Einschreibung bestätigt“). Betriebe (§ 82) legen ihre Beschäftigten selbst an.
 */
return new class extends Migration
{
    public function up(): void
    {
        FundingCase::query()
            ->where('state', 'enrolled')
            ->where('pathway', '!=', 'employer')
            ->whereDoesntHave('participants')
            ->with('lead.contact')
            ->each(fn (FundingCase $case) => app(ParticipantService::class)->createForCase($case));
    }

    public function down(): void
    {
        // Akten bleiben erhalten.
    }
};

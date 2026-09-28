<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\BlocklistEntry;
use App\Models\FundingCase;

class StatusResult
{
    public ?Activity $activity = null;

    public ?Appointment $appointment = null;

    /** @var list<BlocklistEntry> */
    public array $blocklistEntries = [];

    /** Gestarteter Förderfall bei „Übergeben an Förderweg“ */
    public ?FundingCase $fundingCase = null;

    /** Nach dem dritten Versuch: Hinweis „Vorgang ruhen lassen?“ */
    public bool $suggestRest = false;
}

<?php

namespace App\Services;

use App\Models\Activity;
use App\Models\Appointment;
use App\Models\BlocklistEntry;

class StatusResult
{
    public ?Activity $activity = null;

    public ?Appointment $appointment = null;

    /** @var list<BlocklistEntry> */
    public array $blocklistEntries = [];

    /** Nach dem dritten Versuch: Hinweis „Vorgang ruhen lassen?“ */
    public bool $suggestRest = false;
}

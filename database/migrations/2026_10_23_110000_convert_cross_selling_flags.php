<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Das bisherige Merkmal „Cross-Selling JB Design“ (Taste 0) wird zu einem eigenen Vorgang der Marke
 * JB IT-Design: Status „Später Interesse“ mit der bisherigen Wiedervorlage, verknüpft mit dem
 * ADK-Vorgang (Paket). Danach ist das Merkmal aus. Die Spalten bleiben vorerst stehen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $now = now();
        $today = $now->toDateString();

        $flagged = DB::table('leads')
            ->where('cross_selling', true)
            ->where('brand', 'adk')
            ->whereNull('package_lead_id')
            ->get();

        foreach ($flagged as $lead) {
            DB::transaction(function () use ($lead, $now, $today) {
                $followUp = $lead->cross_selling_follow_up_at && $lead->cross_selling_follow_up_at > $today
                    ? substr((string) $lead->cross_selling_follow_up_at, 0, 10)
                    : $today;

                $jbId = DB::table('leads')->insertGetId([
                    'brand' => 'jb',
                    'organization_id' => $lead->organization_id,
                    'contact_id' => $lead->contact_id,
                    'package_lead_id' => $lead->id,
                    'target_group' => $lead->organization_id ? 'company_open' : 'open',
                    'channel' => 'cross_selling',
                    'status' => 'later',
                    'call_attempts' => 0,
                    'next_action_at' => $followUp,
                    'assigned_to' => $lead->assigned_to,
                    'cross_selling' => false,
                    'last_contact_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                DB::table('leads')->where('id', $lead->id)->update([
                    'package_lead_id' => $jbId,
                    'cross_selling' => false,
                    'cross_selling_follow_up_at' => null,
                    'updated_at' => $now,
                ]);

                DB::table('activities')->insert([
                    [
                        'lead_id' => $jbId,
                        'type' => 'status_change',
                        'outcome' => 'later',
                        'status_from' => 'new',
                        'status_to' => 'later',
                        'body' => "Aus dem Merkmal „Cross-Selling JB Design“ am ADK-Vorgang {$lead->id} übernommen.",
                        'occurred_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                    [
                        'lead_id' => $lead->id,
                        'type' => 'note',
                        'outcome' => null,
                        'status_from' => null,
                        'status_to' => null,
                        'body' => "Merkmal „Cross-Selling JB Design“ ist jetzt der JB-IT-Design-Vorgang {$jbId} (Wiedervorlage ".date('d.m.Y', strtotime($followUp)).').',
                        'occurred_at' => $now,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ],
                ]);
            });
        }
    }

    public function down(): void
    {
        // Die neuen Vorgänge bleiben bestehen; sie sind normale Vorgänge der Marke JB IT-Design.
    }
};

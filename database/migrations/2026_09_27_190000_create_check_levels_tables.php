<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Prüfstufen der Leadliste frei verwaltbar statt fünf fester Spalten.
 * Die bisherigen Stufen 1–5 und ihre Ergebnisse werden übernommen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('check_levels', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0)->index();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('organization_checks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('check_level_id')->constrained()->cascadeOnDelete();
            $table->boolean('passed')->nullable();
            $table->timestamps();
            $table->unique(['organization_id', 'check_level_id']);
        });

        $now = now();
        $levelIds = [];

        foreach (range(1, 5) as $level) {
            $levelIds[$level] = DB::table('check_levels')->insertGetId([
                'name' => "Prüfstufe {$level}",
                'description' => 'Bezeichnung laut Branchenmatrix ergänzen',
                'sort_order' => $level,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        DB::table('organizations')->orderBy('id')->chunk(500, function ($organizations) use ($levelIds, $now) {
            $rows = [];

            foreach ($organizations as $organization) {
                foreach ($levelIds as $level => $levelId) {
                    $value = $organization->{"check_{$level}_passed"};

                    if ($value !== null) {
                        $rows[] = [
                            'organization_id' => $organization->id,
                            'check_level_id' => $levelId,
                            'passed' => (bool) $value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }
            }

            if ($rows) {
                DB::table('organization_checks')->insert($rows);
            }
        });

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropColumn(['check_1_passed', 'check_2_passed', 'check_3_passed', 'check_4_passed', 'check_5_passed']);
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            foreach (range(1, 5) as $level) {
                $table->boolean("check_{$level}_passed")->nullable();
            }
        });

        Schema::dropIfExists('organization_checks');
        Schema::dropIfExists('check_levels');
    }
};

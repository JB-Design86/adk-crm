<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Dublettenverdacht: ein neuer bzw. geprüfter Datensatz (subject) ähnelt einem vorhandenen (match).
 * Offen, bis jemand entscheidet: derselbe (zusammengeführt) oder verschieden (freigegeben).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('duplicate_candidates', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20); // organization | contact
            $table->unsignedBigInteger('subject_id');
            $table->unsignedBigInteger('match_id');
            $table->unsignedTinyInteger('score');
            $table->json('reasons');
            $table->string('state', 20)->default('open')->index(); // open | merged | rejected
            $table->foreignId('import_log_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
            $table->unique(['type', 'subject_id', 'match_id']);
            $table->index(['type', 'subject_id', 'state']);
        });

        Schema::table('import_logs', function (Blueprint $table) {
            $table->unsignedInteger('suspected_duplicates')->default(0)->after('duplicates');
        });
    }

    public function down(): void
    {
        Schema::table('import_logs', fn (Blueprint $table) => $table->dropColumn('suspected_duplicates'));
        Schema::dropIfExists('duplicate_candidates');
    }
};

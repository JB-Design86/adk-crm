<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Website-Check (App\Services\WebsiteCheck): sichtbare PHP-Version und System der Website eines Betriebs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->string('website_php', 30)->nullable()->after('website_domain');
            $table->string('website_generator', 100)->nullable()->after('website_php');
            $table->string('website_check_note')->nullable()->after('website_generator');
            $table->timestamp('website_checked_at')->nullable()->after('website_check_note')->index();
        });
    }

    public function down(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            $table->dropIndex(['website_checked_at']);
            $table->dropColumn(['website_php', 'website_generator', 'website_check_note', 'website_checked_at']);
        });
    }
};

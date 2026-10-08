<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('producer_outbox', function (Blueprint $table) {
            $table->timestamp('next_attempt_at')->nullable()->index()->after('last_error');
        });
    }

    public function down(): void
    {
        Schema::table('producer_outbox', function (Blueprint $table) {
            $table->dropColumn('next_attempt_at');
        });
    }
};

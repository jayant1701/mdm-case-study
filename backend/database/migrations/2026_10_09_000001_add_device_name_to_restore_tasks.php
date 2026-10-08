<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('restore_tasks', function (Blueprint $table) {
            $table->string('device_name')->nullable()->after('serial_code');
        });
    }

    public function down(): void
    {
        Schema::table('restore_tasks', function (Blueprint $table) {
            $table->dropColumn('device_name');
        });
    }
};

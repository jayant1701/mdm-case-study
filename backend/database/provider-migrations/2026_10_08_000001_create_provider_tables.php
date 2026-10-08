<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('provider_generation', function (Blueprint $t) {
            $t->string('provider')->primary();
            $t->unsignedBigInteger('generation')->default(0);
        });
        DB::table('provider_generation')->insert(['provider' => 'jamf', 'generation' => 0]);
        Schema::create('provider_devices', function (Blueprint $t) {
            $t->id();
            $t->string('provider_device_id')->unique();
            $t->string('serial_code')->unique();
            $t->unsignedBigInteger('version');
            $t->unsignedBigInteger('producer_generation');
            $t->string('state');
            $t->string('fingerprint', 64);
            $t->json('device');
            $t->timestamps();
        });
        Schema::create('producer_outbox', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();
            $t->string('provider_device_id')->index();
            $t->json('payload');
            $t->string('status')->default('pending')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('last_error')->nullable();
            $t->timestamp('published_at')->nullable();
            $t->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('producer_outbox');
        Schema::dropIfExists('provider_devices');
        Schema::dropIfExists('provider_generation');
    }
};

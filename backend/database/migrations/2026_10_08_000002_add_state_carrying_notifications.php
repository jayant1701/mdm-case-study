<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_inboxes', function (Blueprint $table) {
            $table->unsignedBigInteger('version')->nullable();
            $table->unsignedBigInteger('producer_generation')->nullable();
            $table->string('content_hash', 64)->nullable();
            $table->string('outcome')->nullable();
            $table->unique(['provider', 'provider_device_id', 'version'], 'inbox_device_version_unique');
        });
        foreach (['simulated_provider_devices', 'device_event_states'] as $name) {
            Schema::create($name, function (Blueprint $table) {
                $table->id();
                $table->string('provider');
                $table->string('provider_device_id');
                $table->unique(['provider', 'provider_device_id']);
                $table->unsignedBigInteger('version');
                $table->unsignedBigInteger('producer_generation');
                $table->string('state');
                $table->string('serial_code');
                $table->string('fingerprint', 64);
                $table->json('device');
                $table->timestamps();
            });
        }
        Schema::create('simulator_generations', function (Blueprint $table) {
            $table->string('provider')->primary();
            $table->unsignedBigInteger('generation')->default(0);
        });
        DB::table('simulator_generations')->insert(['provider' => 'jamf', 'generation' => 0]);
        Schema::create('shared_event_states', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('kind');
            $table->string('identity');
            $table->unique(['provider', 'kind', 'identity'], 'shared_identity_unique');
            $table->unsignedBigInteger('generation');
            $table->string('fingerprint', 64);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        foreach (['shared_event_states', 'simulator_generations', 'device_event_states', 'simulated_provider_devices'] as $name) {
            Schema::dropIfExists($name);
        }Schema::table('event_inboxes', function (Blueprint $table) {
            $table->dropUnique('inbox_device_version_unique');
            $table->dropColumn(['version', 'producer_generation', 'content_hash', 'outcome']);
        });
    }
};

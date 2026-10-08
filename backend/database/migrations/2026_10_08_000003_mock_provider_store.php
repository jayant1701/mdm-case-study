<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('mock_provider_devices', function (Blueprint $table) {
            $table->id();
            $table->string('provider');
            $table->string('provider_device_id');
            $table->unique(['provider', 'provider_device_id']);
            $table->string('serial_code');
            $table->unique(['provider', 'serial_code']);
            $table->string('state');
            $table->json('device');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mock_provider_devices');
    }
};

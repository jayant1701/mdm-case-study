<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employees', function (Blueprint $t) {
            $t->id();
            $t->string('email')->unique();
            $t->string('name')->nullable();
            $t->string('phone')->nullable();
            $t->timestamps();
        });
        Schema::create('device_models', function (Blueprint $t) {
            $t->id();
            $t->string('manufacturer');
            $t->string('model_identifier');
            $t->string('model_name')->nullable();
            $t->unique(['manufacturer', 'model_identifier']);
            $t->timestamps();
        });
        Schema::create('assets', function (Blueprint $t) {
            $t->id();
            $t->string('serial_code')->unique();
            $t->foreignId('employee_id')->nullable()->constrained()->nullOnDelete();
            $t->foreignId('device_model_id')->nullable()->constrained()->nullOnDelete();
            $t->string('device_name');
            $t->string('provider');
            $t->string('provider_device_id')->nullable();
            $t->unique(['provider', 'provider_device_id']);
            $t->string('udid')->nullable();
            $t->boolean('purchased')->nullable();
            $t->date('purchase_date')->nullable();
            $t->timestamp('report_date')->nullable();
            $t->timestamps();
        });
        Schema::create('asset_attributes', function (Blueprint $t) {
            $t->id();
            $t->foreignId('asset_id')->unique()->constrained()->cascadeOnDelete();
            $t->decimal('ram_gb', 12, 2)->nullable();
            $t->decimal('storage_gb', 12, 2)->nullable();
            $t->string('processor_type')->nullable();
            $t->unsignedInteger('core_count')->nullable();
            $t->decimal('battery_capacity_percent', 5, 2)->nullable();
            $t->timestamps();
        });
        Schema::create('sync_runs', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('mode');
            $t->string('status')->index();
            $t->timestamp('started_at');
            $t->timestamp('completed_at')->nullable();
            $t->json('counters')->nullable();
            $t->text('error_details')->nullable();
            $t->timestamps();
        });
        Schema::create('restore_tasks', function (Blueprint $t) {
            $t->id();
            $t->string('provider');
            $t->string('provider_device_id')->nullable();
            $t->string('serial_code');
            $t->unique(['provider', 'serial_code']);
            $t->timestamp('deleted_at');
            $t->string('status')->default('pending')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->text('error')->nullable();
            $t->timestamps();
        });
        Schema::create('event_inboxes', function (Blueprint $t) {
            $t->id();
            $t->string('event_id')->unique();
            $t->string('provider');
            $t->string('provider_device_id');
            $t->string('type');
            $t->json('payload');
            $t->string('status')->default('pending')->index();
            $t->unsignedInteger('attempts')->default(0);
            $t->timestamp('processed_at')->nullable();
            $t->text('last_error')->nullable();
            $t->timestamps();
        });
        // A permanent row lock is transaction-scoped: no TTL expiry during reconciliation.
        Schema::create('sync_locks', function (Blueprint $t) {
            $t->string('provider')->primary();
        });
        DB::table('sync_locks')->insert(['provider' => 'jamf']);
    }

    public function down(): void
    {
        foreach (['event_inboxes', 'restore_tasks', 'sync_runs', 'asset_attributes', 'assets', 'device_models', 'employees', 'sync_locks'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};

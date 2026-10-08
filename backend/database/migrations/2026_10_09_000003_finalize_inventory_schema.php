<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deleted_employees', function (Blueprint $table) {
            $table->unsignedBigInteger('employee_id')->primary();
            $table->string('email')->unique();
            $table->timestamp('deleted_at');
        });

        Schema::table('employees', function (Blueprint $table) {
            $table->string('username')->nullable()->after('email');
            $table->string('position')->nullable()->after('name');
        });
        Schema::table('device_models', function (Blueprint $table) {
            $table->string('platform')->nullable()->after('model_name');
            $table->string('processor_type')->nullable()->after('platform');
            $table->unsignedInteger('core_count')->nullable()->after('processor_type');
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->unsignedBigInteger('deleted_employee_id')->nullable()->after('employee_id');
            $table->timestamp('last_contact_at')->nullable()->after('report_date');
            $table->string('site_provider_id')->nullable()->after('last_contact_at');
            $table->string('site_name')->nullable()->after('site_provider_id');
            $table->foreign('deleted_employee_id')->references('employee_id')->on('deleted_employees')->restrictOnDelete();
        });

        foreach (DB::table('employees')->whereNotNull('deleted_at')->orderBy('id')->get() as $employee) {
            DB::table('deleted_employees')->insert([
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'deleted_at' => $employee->deleted_at,
            ]);
            DB::table('assets')->where('employee_id', $employee->id)->update([
                'employee_id' => null,
                'deleted_employee_id' => $employee->id,
            ]);
            DB::table('employees')->where('id', $employee->id)->delete();
        }

        foreach (DB::table('asset_attributes')->join('assets', 'assets.id', '=', 'asset_attributes.asset_id')->whereNotNull('assets.device_model_id')->select('assets.device_model_id', 'asset_attributes.processor_type', 'asset_attributes.core_count')->get() as $row) {
            $model = DB::table('device_models')->where('id', $row->device_model_id)->first();
            foreach (['processor_type', 'core_count'] as $field) {
                if ($model->{$field} !== null && $row->{$field} !== null && (string) $model->{$field} !== (string) $row->{$field}) {
                    throw new RuntimeException('Existing assets conflict on immutable device-model field '.$field.'.');
                }
            }
            DB::table('device_models')->where('id', $row->device_model_id)->update([
                'processor_type' => $model->processor_type ?? $row->processor_type,
                'core_count' => $model->core_count ?? $row->core_count,
            ]);
        }

        Schema::table('employees', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
        Schema::table('device_models', function (Blueprint $table) {
            $table->dropTimestamps();
        });
        Schema::table('asset_attributes', function (Blueprint $table) {
            $table->dropColumn(['processor_type', 'core_count']);
        });

        Schema::rename('restore_tasks', 'deleted_assets');
        Schema::table('deleted_assets', function (Blueprint $table) {
            $table->dropIndex('restore_tasks_status_index');
            $table->dropColumn(['status', 'attempts', 'error', 'created_at', 'updated_at']);
        });
    }

    public function down(): void
    {
        Schema::table('deleted_assets', function (Blueprint $table) {
            $table->string('status')->default('pending')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->text('error')->nullable();
            $table->timestamps();
        });
        Schema::rename('deleted_assets', 'restore_tasks');
        Schema::table('asset_attributes', function (Blueprint $table) {
            $table->string('processor_type')->nullable();
            $table->unsignedInteger('core_count')->nullable();
        });
        Schema::table('device_models', function (Blueprint $table) {
            $table->timestamps();
            $table->dropColumn(['platform', 'processor_type', 'core_count']);
        });
        Schema::table('employees', function (Blueprint $table) {
            $table->softDeletes();
            $table->dropColumn(['username', 'position']);
        });
        Schema::table('assets', function (Blueprint $table) {
            $table->dropForeign(['deleted_employee_id']);
            $table->dropColumn(['deleted_employee_id', 'last_contact_at', 'site_provider_id', 'site_name']);
        });
        Schema::dropIfExists('deleted_employees');
    }
};

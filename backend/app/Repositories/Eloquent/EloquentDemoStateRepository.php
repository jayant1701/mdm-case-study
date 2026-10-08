<?php

namespace App\Repositories\Eloquent;

use App\Contracts\Persistence\DemoStateRepository;
use Illuminate\Support\Facades\DB;

final class EloquentDemoStateRepository implements DemoStateRepository
{
    public function resetInventory(): void
    {
        DB::transaction(function () {
            DB::table('sync_locks')->where('provider', 'jamf')->lockForUpdate()->first();

            foreach ([
                'jobs',
                'failed_jobs',
                'rejected_notifications',
                'event_inboxes',
                'deleted_assets',
                'sync_runs',
                'shared_event_states',
                'device_event_states',
                'simulated_provider_devices',
                'asset_attributes',
                'assets',
                'device_models',
                'employees',
                'deleted_employees',
            ] as $table) {
                DB::table($table)->delete();
            }

            DB::table('simulator_generations')->where('provider', 'jamf')->update(['generation' => 0]);
        }, 3);
    }
}

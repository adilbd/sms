<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Records whether an admin wants the staff member to have a working login, separately from
 * the user's is_active (which also follows the staff status). Without it, returning a
 * member to active could not tell "login switched off" from "login off because they left".
 * Existing logins are backfilled: on when the linked user is active and holds teacher/office.
 * Only logins that are currently active are backfilled; a login that was already deactivated
 * stays off, as the earlier is_active state is not recoverable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->boolean('login_enabled')->default(false)->after('user_id');
        });

        $ids = DB::table('staff')
            ->join('users', 'users.id', '=', 'staff.user_id')
            ->where('users.is_active', true)
            ->whereExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('model_has_roles')
                    ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
                    ->whereColumn('model_has_roles.model_id', 'users.id')
                    ->where('model_has_roles.model_type', 'App\\Models\\User')
                    ->whereIn('roles.name', ['teacher', 'office']);
            })
            ->pluck('staff.id');

        foreach ($ids->chunk(500) as $chunk) {
            DB::table('staff')->whereIn('id', $chunk->all())->update(['login_enabled' => true]);
        }
    }

    public function down(): void
    {
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('login_enabled');
        });
    }
};

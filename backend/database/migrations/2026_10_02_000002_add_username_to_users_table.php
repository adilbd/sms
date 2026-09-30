<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Students log in with their student ID and guardians with their mobile number, both
 * stored as `username`; neither needs an email address, so `email` becomes nullable
 * (it stays unique: several NULLs are allowed in both SQLite and MySQL).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('username')->nullable()->unique()->after('email');
            $table->string('email')->nullable()->change();
        });
    }

    public function down(): void
    {
        // email goes back to NOT NULL, so give any user without one a placeholder first.
        foreach (DB::table('users')->whereNull('email')->get(['id', 'username']) as $user) {
            DB::table('users')->where('id', $user->id)->update([
                'email' => ($user->username ?: 'user'.$user->id).'@no-email.invalid',
            ]);
        }

        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->dropUnique(['username']);
            $table->dropColumn('username');
        });
    }
};

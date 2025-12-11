<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $existing = DB::table('users')->where('id', 1)->first();

        $payload = [
            'name'       => 'Admin',
            'email'      => 'admin@master.dev',
            'password'   => '',
            'updated_at' => now(),
        ];

        // insert admin user, which will be collecting commissions
        if ($existing) {
            DB::table('users')->where('id', 1)->update($payload);
        } else {
            DB::table('users')->insert(array_merge($payload, [
                'id'         => 1,
                'balance'    => 0,
                'created_at' => now(),
            ]));
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        DB::table('users')
            ->where('id', 1)
            ->where('email', 'admin@master.dev')
            ->delete();
    }
};

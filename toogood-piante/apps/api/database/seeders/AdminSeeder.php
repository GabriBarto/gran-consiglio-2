<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Crea il super admin con le credenziali iniziali di .env (ADMIN_EMAIL, ADMIN_INITIAL_PASSWORD).
 * Al primo accesso deve cambiare la password. Non modifica un admin già esistente.
 */
class AdminSeeder extends Seeder
{
    public function run(): void
    {
        $email = config('toogood.admin.email');
        $password = config('toogood.admin.initial_password');

        if (! $email || ! $password) {
            $this->command?->warn('ADMIN_EMAIL o ADMIN_INITIAL_PASSWORD non impostati: admin non creato.');

            return;
        }

        if (User::where('email', strtolower($email))->exists()) {
            return;
        }

        $admin = new User(['username' => 'admin', 'email' => strtolower($email), 'password' => $password]);
        $admin->role = User::ROLE_ADMIN;
        $admin->status = User::STATUS_ACTIVE;
        $admin->email_verified_at = now();
        $admin->must_change_password = true;
        $admin->save();
    }
}

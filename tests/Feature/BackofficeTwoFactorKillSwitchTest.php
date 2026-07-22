<?php

namespace Tests\Feature;

use App\Filament\Pages\Auth\Login;
use App\Models\User;
use App\Notifications\Auth\BackofficeTwoFactorCode;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * Regression coverage for the BACKOFFICE_2FA_ENABLED kill-switch (config/backoffice-2fa.php).
 *
 * Guards against two real bugs found in the initial implementation:
 * - `Log::warning()` called without importing the Log facade in Login::authenticate()
 *   fatals the very first time the switch is exercised (flag = false).
 * - the `config('backoffice-2fa.enabled', ...)` fallback in authenticate() must match
 *   mount()'s default. That default is FALSE (fail-open) by explicit decision of the
 *   owner (2026-07-18): absent variable => 2FA off. Do not flip it back without asking.
 */
class BackofficeTwoFactorKillSwitchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('backoffice'));
    }

    private function makeAdmin(string $password): User
    {
        Role::firstOrCreate(['name' => 'admin', 'guard_name' => 'web']);

        $admin = User::factory()->create(['password' => bcrypt($password)]);
        $admin->assignRole('admin');

        return $admin;
    }

    public function test_kill_switch_disabled_logs_in_with_password_only_and_sends_no_2fa_notification(): void
    {
        config(['backoffice-2fa.enabled' => false]);
        Notification::fake();

        $admin = $this->makeAdmin('correct-password');

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'correct-password')
            ->call('authenticate');

        $this->assertAuthenticatedAs($admin, 'web');
        Notification::assertNothingSent();
    }

    public function test_kill_switch_enabled_leaves_the_2fa_challenge_flow_unchanged(): void
    {
        config(['backoffice-2fa.enabled' => true]);
        Notification::fake();

        $admin = $this->makeAdmin('correct-password');

        Livewire::test(Login::class)
            ->set('data.email', $admin->email)
            ->set('data.password', 'correct-password')
            ->call('authenticate')
            ->assertSet('step', 'code');

        $this->assertGuest('web');
        Notification::assertSentTo($admin, BackofficeTwoFactorCode::class);
    }
}

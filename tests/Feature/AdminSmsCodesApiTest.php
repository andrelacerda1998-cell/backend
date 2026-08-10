<?php

namespace Tests\Feature;

use App\Enums\SmsType;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Models\GeneralSettings\Gender;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Códigos SMS — equivalente ao Filament SmsCodeResource. Só leitura.
 *
 * 'users' está na lista de truncate porque o teste cria Users; incluímos
 * 'phone_number_validation_codes' pela mesma razão (FK para 'users' --
 * mesma classe de problema já visto em AdminSentNotificationsApiTest/
 * AdminCustomerPaymentMethodsApiTest, uma linha órfã bloqueava operações
 * num id reciclado).
 *
 * Nota: ao contrário de 'notifications' (relação polimórfica, sem FK real),
 * 'phone_number_validation_codes.user_id' tem FK RESTRICT para 'users' --
 * não é possível forceDelete() um User com códigos associados (a query
 * falha com erro de integridade referencial). Por isso o teste de
 * resiliência aqui não simula um "utilizador apagado" (impossível dado o
 * schema), mas sim um valor de 'type' que já não bate com a enum SmsType
 * (dados legados/corrompidos) -- ver nota em SmsCodeController::presentSafely().
 */
class AdminSmsCodesApiTest extends TestCase
{
    use DatabaseTruncation;

    protected array $tablesToTruncate = ['phone_number_validation_codes', 'users', 'wallets'];

    protected function setUp(): void
    {
        parent::setUp();
        config(['scout.driver' => 'null']);
        Gender::firstOrCreate(['name' => 'Masculino']);
    }

    private function withAuth(): static
    {
        config(['services.admin_api.token' => 'a-valid-token']);

        return $this->withHeaders(['Authorization' => 'Bearer a-valid-token']);
    }

    public function test_it_lists_sms_codes_most_recent_first(): void
    {
        $user = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Silva']);

        $older = PhoneNumberValidationCode::create([
            'user_id' => $user->id,
            'phone_number' => '+351910000000',
            'code' => '111111',
            'type' => SmsType::VERIFICATION,
            'created_at' => now()->subMinutes(10),
        ]);
        $newer = PhoneNumberValidationCode::create([
            'user_id' => null,
            'phone_number' => '+351920000000',
            'code' => '222222',
            'type' => SmsType::Login,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/sms-codes')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.0.code', '222222')
            ->assertJsonPath('data.items.0.type', 'login')
            ->assertJsonPath('data.items.0.user', null)
            ->assertJsonPath('data.items.1.id', $older->id)
            ->assertJsonPath('data.items.1.code', '111111')
            ->assertJsonPath('data.items.1.type', 'verification')
            ->assertJsonPath('data.items.1.user.name', 'Ana Silva');
    }

    public function test_it_filters_by_search_on_phone_number(): void
    {
        PhoneNumberValidationCode::create([
            'phone_number' => '+351910000000',
            'code' => '111111',
            'type' => SmsType::VERIFICATION,
        ]);
        PhoneNumberValidationCode::create([
            'phone_number' => '+351920000000',
            'code' => '222222',
            'type' => SmsType::VERIFICATION,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/sms-codes?search=910000000')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', '111111');
    }

    public function test_it_filters_by_search_on_user_name(): void
    {
        $user = User::factory()->create(['first_name' => 'Bruno', 'last_name' => 'Costa']);
        PhoneNumberValidationCode::create([
            'user_id' => $user->id,
            'phone_number' => '+351910000000',
            'code' => '333333',
            'type' => SmsType::VERIFICATION,
        ]);
        PhoneNumberValidationCode::create([
            'phone_number' => '+351920000000',
            'code' => '444444',
            'type' => SmsType::VERIFICATION,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/sms-codes?search=Bruno')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', '333333');
    }

    public function test_it_filters_by_type(): void
    {
        PhoneNumberValidationCode::create([
            'phone_number' => '+351910000000',
            'code' => '555555',
            'type' => SmsType::Login,
        ]);
        PhoneNumberValidationCode::create([
            'phone_number' => '+351920000000',
            'code' => '666666',
            'type' => SmsType::VERIFICATION,
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/sms-codes?type=login')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.code', '555555');
    }

    public function test_it_is_resilient_to_a_row_with_an_unexpected_type_value(): void
    {
        // Insert via query builder (não via Eloquent) para contornar o cast
        // de 'type' para a enum SmsType -- simula dados legados/corrompidos
        // que já não batem com nenhum case da enum atual.
        $id = DB::table('phone_number_validation_codes')->insertGetId([
            'phone_number' => '+351910000000',
            'code' => '888888',
            'type' => 'ja-nao-existe',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->withAuth()
            ->getJson('/api/v1/admin/sms-codes')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $id)
            ->assertJsonPath('data.items.0.code', '888888')
            ->assertJsonPath('data.items.0.type', 'ja-nao-existe')
            ->assertJsonPath('data.items.0.user', null);
    }

    public function test_it_paginates_results(): void
    {
        for ($i = 0; $i < 25; $i++) {
            PhoneNumberValidationCode::create([
                'phone_number' => '+35191000'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'code' => str_pad((string) $i, 6, '0', STR_PAD_LEFT),
                'type' => SmsType::VERIFICATION,
            ]);
        }

        $this->withAuth()
            ->getJson('/api/v1/admin/sms-codes?per_page=20')
            ->assertOk()
            ->assertJsonCount(20, 'data.items')
            ->assertJsonPath('data.meta.total', 25)
            ->assertJsonPath('data.meta.last_page', 2);
    }
}

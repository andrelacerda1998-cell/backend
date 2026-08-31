<?php

namespace Tests\Feature;

use App\Enums\SmsType;
use App\Models\Auth\PhoneNumberValidationCode;
use App\Models\User;
use App\Services\Common\PhoneLoginSmsService;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

/**
 * Registo por telemóvel: um número desconhecido recebe código e, ao verificá-lo,
 * ganha conta. Antes ouvia "credenciais erradas" e não havia forma de entrar.
 */
class PhoneRegistrationTest extends TestCase
{
    use DatabaseTruncation;

    protected function setUp(): void
    {
        parent::setUp();
        // Não enviar SMS reais nos testes: o Twilio nem está configurado aqui.
        Notification::fake();
    }

    private function service(): PhoneLoginSmsService
    {
        return app(PhoneLoginSmsService::class);
    }

    private function codeFor(string $phone): string
    {
        return PhoneNumberValidationCode::where('phone_number', $phone)
            ->where('type', SmsType::Login)
            ->latest('id')->first()->code;
    }

    public function test_unknown_number_gets_a_code_instead_of_being_rejected(): void
    {
        config(['app.MOCK_SMS' => false]);
        $phone = '+351911111111';

        $this->assertNull(User::where('phone_number', $phone)->first());

        $result = $this->service()->sendCode($phone);

        $this->assertTrue($result['success']);
        $this->assertDatabaseHas('phone_number_validation_codes', [
            'phone_number' => $phone,
            'type' => SmsType::Login->value,
        ]);
    }

    public function test_verifying_a_new_number_creates_the_account(): void
    {
        config(['app.MOCK_SMS' => false]);
        $phone = '+351922222222';

        $this->service()->sendCode($phone);
        $user = $this->service()->verifyCode($phone, $this->codeFor($phone));

        $this->assertNotNull($user, 'Número novo devia ter criado conta');
        $this->assertSame($phone, $user->phone_number);
        $this->assertNotNull($user->phone_number_verified_at, 'O número entrou por SMS: fica verificado');
        $this->assertNull($user->email, 'Nasce sem email — é pedido na primeira fatura');
        $this->assertNull($user->password, 'Nasce sem password — entra-se por SMS');
        $this->assertTrue($user->isCustomer());
    }

    public function test_existing_account_is_reused_not_duplicated(): void
    {
        config(['app.MOCK_SMS' => false]);
        $phone = '+351933333333';

        $existing = User::factory()->create(['phone_number' => $phone]);

        $this->service()->sendCode($phone);
        $user = $this->service()->verifyCode($phone, $this->codeFor($phone));

        $this->assertSame($existing->id, $user->id, 'Devia entrar na conta que já existia');
        $this->assertSame(1, User::where('phone_number', $phone)->count(), 'Não pode criar segunda conta');
    }

    public function test_wrong_code_creates_nothing(): void
    {
        config(['app.MOCK_SMS' => false]);
        $phone = '+351944444444';

        $this->service()->sendCode($phone);
        $user = $this->service()->verifyCode($phone, '000000');

        $this->assertNull($user);
        $this->assertSame(0, User::where('phone_number', $phone)->count(), 'Código errado não pode criar conta');
    }

    public function test_local_format_matches_the_same_account(): void
    {
        config(['app.MOCK_SMS' => false]);
        $canonical = '+351955555555';

        $existing = User::factory()->create(['phone_number' => $canonical]);

        // O cliente escreve sem indicativo — tem de cair na mesma conta.
        $this->service()->sendCode('955555555');
        $user = $this->service()->verifyCode('955555555', $this->codeFor($canonical));

        $this->assertSame($existing->id, $user->id);
        $this->assertSame(1, User::where('phone_number', $canonical)->count());
    }
}

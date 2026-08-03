<?php

namespace Tests\Feature;

use App\Models\GeneralSettings\Gender;
use App\Models\User;
use App\Models\Vendor;
use Illuminate\Foundation\Testing\DatabaseTruncation;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Str;
use Tests\TestCase;

class AdminSentNotificationsApiTest extends TestCase
{
    // DatabaseTruncation por defeito (ver nota extensa em AdminVendorsApiTest).
    use DatabaseTruncation;

    // 'wallets'/'schedule_available' entram porque um dos testes cria um
    // Vendor (para testar o filtro recipient_type=vendor), o que dispara a
    // cadeia de observers habitual. 'payshop_payment_methods'/
    // 'payshop_payments_orders' têm FK para 'users' -- sem as truncar, o
    // TRUNCATE de 'users' reinicia o auto_increment e o próximo User#1
    // herda uma linha órfã dessas tabelas (de ChargeServiceExtraTest/
    // ServiceExtrasFlowTest, que também usam User#1); o forceDelete() do
    // teste de resiliência rebentava com uma violação de FK por causa disto.
    protected array $tablesToTruncate = [
        'notifications', 'users', 'vendors', 'wallets', 'schedule_available',
        'payshop_payment_methods', 'payshop_payments_orders',
    ];

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

    private function makeNotification(
        User $user,
        string $type = 'App\\Notifications\\VendorDocumentApproved',
        ?string $title = 'Documento aprovado',
        ?string $body = 'O teu documento foi aprovado.',
        bool $read = false,
    ): DatabaseNotification {
        return DatabaseNotification::create([
            'id' => (string) Str::uuid(),
            'type' => $type,
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => ['title' => $title, 'body' => $body],
            'read_at' => $read ? now() : null,
        ]);
    }

    public function test_it_lists_sent_notifications_newest_first(): void
    {
        $user = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'Sousa']);
        $older = $this->makeNotification($user, title: 'Mais antiga');
        $older->created_at = now()->subDay();
        $older->save();
        $newer = $this->makeNotification($user, title: 'Mais recente');

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonPath('data.items.0.id', $newer->id)
            ->assertJsonPath('data.items.0.title', 'Mais recente')
            ->assertJsonPath('data.items.0.recipient.name', 'Ana Sousa')
            ->assertJsonPath('data.items.0.recipient_type', 'customer')
            ->assertJsonPath('data.items.1.id', $older->id);
    }

    public function test_it_searches_by_recipient_name(): void
    {
        $target = User::factory()->create(['first_name' => 'Bruno', 'last_name' => 'Ferreira']);
        $other = User::factory()->create(['first_name' => 'Carla', 'last_name' => 'Nunes']);
        $notification = $this->makeNotification($target);
        $this->makeNotification($other);

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications?search=Ferreira')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $notification->id);
    }

    public function test_it_filters_by_type(): void
    {
        $user = User::factory()->create();
        $target = $this->makeNotification($user, type: 'App\\Notifications\\PaymentReceived');
        $this->makeNotification($user, type: 'App\\Notifications\\VendorDocumentApproved');

        $query = http_build_query(['type' => 'App\\Notifications\\PaymentReceived']);
        $this->withAuth()
            ->getJson("/api/v1/admin/sent-notifications?{$query}")
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $target->id)
            ->assertJsonPath('data.items.0.type', 'PaymentReceived');
    }

    public function test_it_filters_by_read_status(): void
    {
        $user = User::factory()->create();
        $unread = $this->makeNotification($user, read: false);
        $read = $this->makeNotification($user, read: true);

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications?read=unread')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $unread->id)
            ->assertJsonPath('data.items.0.read', false);

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications?read=read')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $read->id)
            ->assertJsonPath('data.items.0.read', true);
    }

    public function test_it_filters_by_recipient_type(): void
    {
        $customer = User::factory()->create();
        $vendorUser = Vendor::factory()->create()->user;
        $customerNotification = $this->makeNotification($customer);
        $vendorNotification = $this->makeNotification($vendorUser);

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications?recipient_type=vendor')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $vendorNotification->id)
            ->assertJsonPath('data.items.0.recipient_type', 'vendor');

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications?recipient_type=customer')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $customerNotification->id);
    }

    public function test_it_stays_resilient_to_a_notification_with_a_deleted_recipient(): void
    {
        $user = User::factory()->create();
        $notification = $this->makeNotification($user, title: 'Vai desaparecer');
        $user->forceDelete();

        // O destinatário já não existe (forceDelete), mas o endpoint não pode
        // rebentar por causa disso -- só perde a informação do destinatário,
        // o resto da notificação (título/corpo, que vêm do payload próprio,
        // não do notifiable) continua a aparecer normalmente.
        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications')
            ->assertOk()
            ->assertJsonCount(1, 'data.items')
            ->assertJsonPath('data.items.0.id', $notification->id)
            ->assertJsonPath('data.items.0.recipient', null)
            ->assertJsonPath('data.items.0.recipient_type', null)
            ->assertJsonPath('data.items.0.title', 'Vai desaparecer');
    }

    public function test_it_lists_distinct_notification_types(): void
    {
        $user = User::factory()->create();
        $this->makeNotification($user, type: 'App\\Notifications\\PaymentReceived');
        $this->makeNotification($user, type: 'App\\Notifications\\PaymentReceived');
        $this->makeNotification($user, type: 'App\\Notifications\\VendorDocumentApproved');

        $this->withAuth()
            ->getJson('/api/v1/admin/sent-notifications/types')
            ->assertOk()
            ->assertJsonCount(2, 'data.items')
            ->assertJsonFragment(['value' => 'App\\Notifications\\PaymentReceived', 'label' => 'PaymentReceived'])
            ->assertJsonFragment(['value' => 'App\\Notifications\\VendorDocumentApproved', 'label' => 'VendorDocumentApproved']);
    }
}

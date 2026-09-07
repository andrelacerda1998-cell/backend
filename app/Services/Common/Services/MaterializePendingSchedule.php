<?php

namespace App\Services\Common\Services;

use App\Enums\Services\CandidateStatus;
use App\Events\Customer\Schedule\AcceptScheduleEvent;
use App\Events\Vendor\Schedule\CreateScheduleEvent;
use App\Events\Vendor\Schedule\ServiceScheduledEvent;
use App\Http\Controllers\Api\Customer\Services\traits\NotifyVendor;
use App\Jobs\Services\CancelJobWithoutReactionJob;
use App\Models\GeneralSettings\ServicesType;
use App\Models\Schedule\Schedule;
use App\Models\Service;
use App\Notifications\Vendor\NewScheduledServiceNotification;
use App\Notifications\Vendor\NewServiceAvailableNotification;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Single source of truth for turning a paid service into a live schedule (or, for immediate
 * services, notifying the vendor and arming the accept-timeout job).
 *
 * Extracted from the ProcessPendingScheduleAfterPayment trait so that both the customer-app
 * poll (OpenServiceController::checkPaymentStatus) and the async MbwayPaymentCheckJob can
 * run the exact same logic once payment is confirmed. The MBWay flow no longer creates the
 * schedule eagerly at request time — it only stores pending_schedule_data — so this must run
 * on payment confirmation to make the service visible to the vendor.
 */
class MaterializePendingSchedule
{
    use NotifyVendor;

    public function __construct(private readonly AcceptService $acceptService) {}

    /**
     * @param  bool  $alreadyAccepted  O profissional já aceitou ANTES do pagamento
     *                                 (fluxo de seleção — ver docs/matching.md).
     *                                 Nesse caso não se lhe pergunta outra vez
     *                                 nem se arma o cancelamento por falta de
     *                                 resposta: a resposta já existe, e o job
     *                                 cancelaria um serviço pago e aceite.
     */
    public function handle(Service $service, bool $alreadyAccepted = false): void
    {
        // Deduzido e não só recebido: os caminhos assíncronos (confirmação 3DS
        // pelo SuccessController, MbwayPaymentCheckJob, polling da app) chamam
        // isto sem saberem de onde veio o serviço, e um caller que se esqueça do
        // parâmetro voltava a perguntar ao profissional e armava o cancelamento
        // sobre um serviço já pago e aceite. Perguntar aqui fecha essa porta a
        // todos de uma vez.
        $alreadyAccepted = $alreadyAccepted || self::vendorAlreadyAccepted($service);

        if (! $service->pending_schedule_data) {
            // Fluxo de seleção imediato: ele aceitou antes de o cliente escolher.
            // Notificá-lo aqui seria mandar-lhe "novo serviço, tens 60 segundos"
            // para um serviço que já é dele, e armar um job que o cancelaria.
            if ($alreadyAccepted) {
                return;
            }

            $this->notifyVendor($service, $service->vendor);
            $this->dispatchCancelJob($service);

            return;
        }

        $pendingData = $service->pending_schedule_data;
        if (! ($pendingData['scheduled'] ?? false)) {
            $service->pending_schedule_data = null;
            $service->save();

            return;
        }

        $vendor = $service->vendor;
        $scheduleData = $pendingData['schedule'] ?? [];
        $scheduledDay = $scheduleData['scheduled_day'] ?? null;
        $serviceType = ServicesType::find($service->services_type_id);
        if (! $serviceType) {
            $service->pending_schedule_data = null;
            $service->save();

            return;
        }

        $scheduledTimeStart = Carbon::parse($scheduleData['scheduled_time_start']);
        $scheduledTimeEnd = $scheduledTimeStart->copy()->addMinutes((int) $serviceType->time);

        // Confirmar uma ocorrência que JÁ existe (série recorrente): o cliente
        // acabou de pagar uma marcação criada pelo CreateNextRecurrence, que
        // nasce sem serviço. Ligar o serviço a essa em vez de criar outra —
        // senão ficavam duas marcações no mesmo horário, uma paga e outra por
        // confirmar, e o técnico via o trabalho a dobrar.
        $existing = $this->existingScheduleToConfirm($service, $scheduleData);
        if ($existing) {
            $existing->update([
                'service_id' => $service->id,
                'scheduled_time_start' => $scheduledTimeStart,
                'scheduled_time_end' => $scheduledTimeEnd,
                // Já foi paga: deixa de estar à espera do cliente. A confirmação
                // do técnico continua a ser dele — is_pending trata disso abaixo.
                'payment_reminder_sent_at' => null,
            ]);

            $service->pending_schedule_data = null;
            $service->save();

            $this->announce($service, $existing, $vendor, $scheduledDay, $alreadyAccepted);

            return;
        }

        $scheduleExists = $vendor->schedules()
            ->where('customer_id', $service->customer_id)
            ->where('scheduled_day', $scheduledDay)
            ->where('service_type_id', $service->services_type_id)
            ->where('scheduled_time_start', $scheduledTimeStart)
            ->where('scheduled_time_end', $scheduledTimeEnd)
            ->exists();

        if ($scheduleExists) {
            $service->pending_schedule_data = null;
            $service->save();

            return;
        }

        $schedule = $vendor->schedules()->create([
            'customer_id' => $service->customer_id,
            'scheduled_day' => Carbon::parse($scheduledDay),
            'service_type_id' => $service->services_type_id,
            'service_id' => $service->id,
            'scheduled_time_start' => $scheduledTimeStart,
            'scheduled_time_end' => $scheduledTimeEnd,
            // A repetição escolhida no ecrã da data viaja no payload e é aqui
            // que passa a existir: sem ela, o comando diário não teria série
            // nenhuma para continuar.
            'recurrence' => $scheduleData['recurrence'] ?? null,
            'is_pending' => true,
        ]);

        $service->pending_schedule_data = null;
        $service->save();

        $this->announce($service, $schedule, $vendor, $scheduledDay, $alreadyAccepted);
    }

    /**
     * A marcação que este pagamento vem confirmar, se existir.
     *
     * Exige que seja do próprio cliente e que ainda não tenha serviço: sem isso,
     * um `schedule_id` de outra pessoa (ou de uma marcação já paga) roubava-lhe
     * o agendamento.
     */
    private function existingScheduleToConfirm(Service $service, array $scheduleData): ?Schedule
    {
        $scheduleId = $scheduleData['schedule_id'] ?? null;
        if (! $scheduleId) {
            return null;
        }

        return Schedule::query()
            ->where('id', $scheduleId)
            ->where('customer_id', $service->customer_id)
            ->whereNull('service_id')
            ->first();
    }

    /**
     * Avisa quem tem de saber que há um agendamento: o técnico, e o cliente
     * quando a aceitação é automática.
     */
    private function announce(Service $service, Schedule $schedule, $vendor, $scheduledDay, bool $alreadyAccepted): void
    {
        // Quem já aceitou entra como confirmado, sem passar pelo caminho de
        // "novo serviço disponível" — que lhe perguntaria o que ele já
        // respondeu.
        // Auto-aceitação só se o técnico a tiver ligada NESTE dia da semana
        // (autoAcceptsOn), não em qualquer dia — ver incidente 13/08.
        if ($alreadyAccepted || $vendor->autoAcceptsOn(Carbon::parse($scheduledDay))) {
            $schedule->update(['is_pending' => false]);
            AcceptScheduleEvent::dispatch($service->customer_id, ['schedule_id' => $schedule->id, 'service_id' => $service->id]);
            $this->acceptService->acceptSchedule($service);
            ServiceScheduledEvent::dispatch($schedule->vendor->user->id, ['id' => $schedule->id]);

            // Push ao vendor (espelha ScheduleController::createPendingSchedule / NotifyVendor):
            // o evento acima é só websocket, não chega com a app fechada. Uma falha do Expo
            // não pode interromper a confirmação do pagamento.
            try {
                $vendor->user->notifyNow(new NewScheduledServiceNotification($schedule));
            } catch (Throwable $e) {
                report($e);
            }
        } else {
            CreateScheduleEvent::dispatch($vendor->user->id, ['id' => $schedule->id]);

            try {
                $vendor->user->notifyNow(new NewServiceAvailableNotification($service));
            } catch (Throwable $e) {
                report($e);
            }
        }

        if (! $alreadyAccepted) {
            $this->dispatchCancelJob($service);
        }
    }

    /**
     * O serviço veio do fluxo de seleção e o profissional escolhido já tinha
     * aceitado — ver docs/matching.md. É a marca que distingue os dois fluxos
     * sem depender de quem chama se lembrar de a passar.
     */
    public static function vendorAlreadyAccepted(Service $service): bool
    {
        return $service->candidates()
            ->where('status', CandidateStatus::SELECTED)
            ->exists();
    }

    public function dispatchCancelJob(Service $service): void
    {
        $seconds = config('services.request.time_to_accept');
        $service->refresh();
        $service->load('schedule');
        if ($service->schedule) {
            $seconds = config('services.request.time_accept_scheduled');
        }

        CancelJobWithoutReactionJob::dispatch($service)->delay(
            now()->addSeconds($seconds)
        );
    }
}

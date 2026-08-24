<?php

use App\Console\Commands\CreateInvoiceSequencesCommand;
use Illuminate\Support\Facades\Schedule;
use Spatie\ScheduleMonitor\Models\MonitoredScheduledTaskLogItem;

Schedule::command('telescope:prune --hours=48')->daily();

Schedule::command('auth:clear-resets')->hourly();

Schedule::command('model:prune', ['--model' => MonitoredScheduledTaskLogItem::class])->daily();

Schedule::command('clear:deleted-users')->daily();

Schedule::command('notifications:process-campaigns')->everyMinute()->withoutOverlapping();

// Liberta serviços de cartão presos em PENDING_3DS há >10 min (resgata os pagos tardiamente,
// expira os não confirmados). Read-only no Payshop (details()); não chama cancel() — ver item 15.
Schedule::command('services:expire-pending-3ds')->everyFiveMinutes()->withoutOverlapping();

// Seleção de profissional: fecha convites expirados, alarga as ondas e desiste
// dos pedidos sem resposta (docs/matching.md). Ao minuto porque as janelas do
// fluxo imediato são de 60 segundos — de cinco em cinco minutos, um cliente
// à espera ficava cinco minutos a olhar para um profissional que já não podia
// responder. `withoutOverlapping` porque duas execuções em paralelo poderiam
// alargar a mesma onda duas vezes.
Schedule::command('matching:advance')->everyMinute()->withoutOverlapping();

// Avisa os técnicos a 30/15/7/3/1 dias da expiração de um documento aprovado — quando expira,
// deixam de poder aceitar serviços. Uma vez por dia (de manhã); a idempotência é garantida pela
// tabela vendor_document_expiry_notifications, por isso é seguro correr manualmente também.
Schedule::command('documents:notify-expiring')->dailyAt('09:00')->withoutOverlapping();

// Re-engagement da 2.a fase do registo (D+1 e D+3). As 10h: cedo o suficiente
// para o tecnico tratar disto durante o dia, tarde o suficiente para nao o
// acordar. O comando e idempotente, por isso um re-run manual e seguro.
Schedule::command('vendors:remind-incomplete-profile')->dailyAt('10:00')->withoutOverlapping();

// Servicos esquecidos em execucao: de hora a hora, porque cada hora parado e
// dinheiro que o tecnico ainda nao recebeu (e uma fatura que o cliente nao tem).
Schedule::command('services:notify-stuck-in-progress')->hourly()->withoutOverlapping();

Schedule::command(CreateInvoiceSequencesCommand::class)->yearlyOn(1, 1);

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

Schedule::command(CreateInvoiceSequencesCommand::class)->yearlyOn(1, 1);

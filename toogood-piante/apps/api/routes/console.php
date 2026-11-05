<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Le box tenute da parte per un ordine non pagato tornano disponibili alla scadenza (10 minuti)
Schedule::command('reservations:release')->everyMinute()->withoutOverlapping();

// A fine fascia di ritiro gli ordini pagati e mai scansionati diventano "non ritirati"
Schedule::command('orders:close-pickup')->everyMinute()->withoutOverlapping();

// Pagamento al venditore 24 ore dopo la fine della fascia di ritiro (RB-11)
Schedule::command('payouts:run')->everyTenMinutes()->withoutOverlapping();

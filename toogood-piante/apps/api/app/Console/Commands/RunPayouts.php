<?php

namespace App\Console\Commands;

use App\Services\Payouts\PayoutService;
use Illuminate\Console\Command;

/**
 * Esegue i pagamenti ai venditori arrivati a scadenza (fine fascia + 24 ore). Pianificato ogni 10 minuti.
 * In sviluppo: `php artisan clock advance 26h`, poi `payouts:run`.
 */
class RunPayouts extends Command
{
    protected $signature = 'payouts:run';

    protected $description = 'Trasferisce ai venditori il guadagno degli ordini la cui attesa è finita';

    public function handle(PayoutService $payouts): int
    {
        $created = $payouts->scheduleMissing();
        $r = $payouts->runDue();

        $this->info("Programmati: {$created}. Inviati: {$r['sent']}, saltati: {$r['skipped']}, sospesi: {$r['held']}, falliti: {$r['failed']}");

        return $r['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}

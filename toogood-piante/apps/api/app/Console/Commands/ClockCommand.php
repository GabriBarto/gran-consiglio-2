<?php

namespace App\Console\Commands;

use App\Support\Clock;
use Illuminate\Console\Command;

class ClockCommand extends Command
{
    protected $signature = 'clock {action : now, advance o reset} {duration? : es. 90s, 30m, 24h, 2d (solo per advance)}';

    protected $description = 'Mostra o sposta l\'orologio simulato (solo con CLOCK_MODE=fake)';

    public function handle(Clock $clock): int
    {
        $action = $this->argument('action');

        if ($action === 'now') {
            $this->line('Modalità: '.($clock->isFake() ? 'fake' : 'real'));
            $this->line('UTC:    '.$clock->now()->toDateTimeString());
            $this->line('Roma:   '.$clock->nowLocal()->toDateTimeString());
            $this->line('Scarto: '.$clock->offset().' secondi');

            return self::SUCCESS;
        }

        if (! $clock->isFake()) {
            $this->error('Imposta CLOCK_MODE=fake per spostare l\'orologio.');

            return self::FAILURE;
        }

        if ($action === 'reset') {
            $clock->reset();
            $this->info('Orologio riportato all\'ora reale.');

            return self::SUCCESS;
        }

        if ($action === 'advance') {
            $seconds = $this->parseDuration((string) $this->argument('duration'));
            if ($seconds === null) {
                $this->error('Durata non valida. Esempi: 90s, 30m, 24h, 2d.');

                return self::FAILURE;
            }
            $clock->advance($seconds);
            $this->info('Orologio spostato di '.$seconds.' secondi. Ora a Roma: '.$clock->nowLocal()->toDateTimeString());

            return self::SUCCESS;
        }

        $this->error('Azione sconosciuta: usa now, advance o reset.');

        return self::FAILURE;
    }

    private function parseDuration(string $value): ?int
    {
        if (! preg_match('/^(\d+)([smhd])$/', $value, $m)) {
            return null;
        }

        return (int) $m[1] * ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400][$m[2]];
    }
}

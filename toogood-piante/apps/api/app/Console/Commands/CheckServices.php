<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Stripe\StripeClient;
use Throwable;

class CheckServices extends Command
{
    protected $signature = 'toogood:check-services';

    protected $description = 'Verifica che database, email, storage, Stripe e servizi simulati rispondano';

    public function handle(): int
    {
        $checks = [
            'MySQL + funzioni spaziali' => fn () => $this->checkDatabase(),
            'Email (Mailpit)' => fn () => $this->checkMail(),
            'Storage file (S3Mock)' => fn () => $this->checkStorage(),
            'Pagamenti (stripe-mock)' => fn () => $this->checkStripe(),
            'Geocoding (WireMock)' => fn () => $this->checkGeocoding(),
            'Verifica P.IVA (WireMock)' => fn () => $this->checkVies(),
        ];

        $failed = 0;
        foreach ($checks as $label => $check) {
            try {
                $detail = $check();
                $this->line("<info>OK</info>      $label — $detail");
            } catch (Throwable $e) {
                $failed++;
                $this->line("<error>ERRORE</error> $label — ".$e->getMessage());
            }
        }

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function checkDatabase(): string
    {
        $version = DB::selectOne('select version() as v')->v;
        // Roma → Milano: circa 477 km in linea d'aria
        $meters = DB::selectOne('select ST_Distance_Sphere(POINT(12.4964, 41.9028), POINT(9.19, 45.4642)) as d')->d;
        $km = round($meters / 1000);
        if ($km < 470 || $km > 485) {
            throw new \RuntimeException("distanza Roma-Milano inattesa: $km km");
        }

        return "MySQL $version, Roma-Milano $km km";
    }

    private function checkMail(): string
    {
        $subject = 'check-services '.uniqid();
        Mail::raw('Messaggio di prova', fn ($m) => $m->to('prova@example.com')->subject($subject));

        $response = Http::get(config('toogood.services.mailpit_api_url').'/search', ['query' => 'subject:"'.$subject.'"'])->throw();
        if ((int) $response->json('messages_count') < 1) {
            throw new \RuntimeException('email inviata ma non trovata in Mailpit');
        }

        return 'email inviata e letta da Mailpit';
    }

    private function checkStorage(): string
    {
        $disk = Storage::disk('s3');
        $key = 'healthcheck/'.uniqid().'.txt';
        $disk->put($key, 'ok');
        $content = $disk->get($key);
        $disk->delete($key);
        if ($content !== 'ok') {
            throw new \RuntimeException('contenuto letto diverso da quello scritto');
        }

        return 'scrittura, lettura e cancellazione riuscite';
    }

    private function checkStripe(): string
    {
        $config = ['api_key' => config('toogood.stripe.secret')];
        if ($base = config('toogood.stripe.api_base')) {
            $config['api_base'] = $base;
        }
        $intent = (new StripeClient($config))->paymentIntents->create(['amount' => 700, 'currency' => 'eur']);

        return 'PaymentIntent creato ('.$intent->id.')';
    }

    private function checkGeocoding(): string
    {
        $data = Http::get(config('toogood.services.geocoding_url'), ['address' => 'Via Roma 1, Milano'])->throw()->json();
        if (! ($data['found'] ?? false)) {
            throw new \RuntimeException('indirizzo di prova non trovato');
        }

        return "Milano → {$data['lat']}, {$data['lng']}";
    }

    private function checkVies(): string
    {
        $data = Http::get(config('toogood.services.vies_url').'/IT12345678901')->throw()->json();
        if (! ($data['valid'] ?? false)) {
            throw new \RuntimeException('P.IVA di prova non valida');
        }

        return 'P.IVA di prova valida';
    }
}

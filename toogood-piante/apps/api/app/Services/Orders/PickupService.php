<?php

namespace App\Services\Orders;

use App\Models\Order;
use App\Models\PickupToken;
use App\Models\User;
use App\Support\Clock;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Ritiro con QR (US-C09, US-V15, RB-12).
 *
 * Ogni ordine pagato ha un biglietto: un **QR firmato** e un **codice alfanumerico alternativo** (per quando la
 * fotocamera non basta). Il biglietto è monouso e vale solo nella fascia di ritiro del giorno dell'ordine.
 * Il QR contiene solo l'ordine e un identificativo casuale, firmati con HMAC: chi lo falsifica non passa la firma.
 * Il server però decide sempre guardando il database (negozio, giorno, fascia, già usato): la firma serve a
 * scartare subito i QR inventati, non a fidarsi di quello che c'è scritto.
 */
class PickupService
{
    private const PREFIX = 'TGQR1';

    /** Senza lettere e cifre che si confondono (0/O, 1/I/L): il codice si può dettare a voce o digitare a mano. */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    private const CODE_LENGTH = 8;

    public function __construct(private readonly Clock $clock) {}

    /** Crea il biglietto dell'ordine (una sola volta: richiamarlo restituisce quello che c'è). */
    public function issue(Order $order): PickupToken
    {
        $existing = PickupToken::where('order_id', $order->id)->first();
        if ($existing) {
            return $existing;
        }

        $seller = $order->seller()->first();
        // La fascia è quella del giorno dell'ordine, nel fuso di Roma; si salva in UTC
        $validFrom = CarbonImmutable::parse($order->order_date->toDateString().' '.$seller->pickup_from, config('toogood.timezone'))->utc();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $token = new PickupToken;
            $token->order_id = $order->id;
            $token->jti = Str::random(40);
            $token->short_code = $this->randomCode();
            $token->valid_from = $validFrom;
            $token->valid_until = $order->pickup_window_end_at;

            try {
                $token->save();

                return $token;
            } catch (UniqueConstraintViolationException) {
                // Due richieste insieme per lo stesso ordine, o un codice già preso: si riparte
                $existing = PickupToken::where('order_id', $order->id)->first();
                if ($existing) {
                    return $existing;
                }
            }
        }

        throw new \RuntimeException("Impossibile creare il biglietto di ritiro dell'ordine {$order->id}");
    }

    /** Il testo da mettere nel QR: TGQR1.<dati>.<firma>. */
    public function qrPayload(PickupToken $token): string
    {
        $data = $this->b64(json_encode(['o' => $token->order_id, 'j' => $token->jti]));

        return self::PREFIX.'.'.$data.'.'.$this->sign($data);
    }

    /** Il codice alternativo come lo vede il cliente: ABCD-EFGH. */
    public function displayCode(PickupToken $token): string
    {
        return substr($token->short_code, 0, 4).'-'.substr($token->short_code, 4);
    }

    /** Stato del biglietto adesso: NOT_YET, ACTIVE, EXPIRED oppure USED. */
    public function state(PickupToken $token): string
    {
        $now = $this->clock->now();

        return match (true) {
            $token->used_at !== null => 'USED',
            $now->lt($token->valid_from) => 'NOT_YET',
            $now->gt($token->valid_until) => 'EXPIRED',
            default => 'ACTIVE',
        };
    }

    /**
     * Il venditore scansiona il QR (o digita il codice): se tutto è in regola l'ordine passa a PICKED_UP.
     * Si indica uno solo tra `$qr` e `$code`. Gli esiti negativi sono errori con un codice che l'app sa spiegare:
     * INVALID_QR, INVALID_CODE, WRONG_SHOP, ALREADY_USED, ORDER_NOT_ACTIVE, NOT_YET_VALID, EXPIRED.
     */
    public function scan(User $seller, ?string $qr, ?string $code): Order
    {
        $token = $this->findToken($qr, $code);

        return DB::transaction(function () use ($seller, $token) {
            // Si bloccano ordine e biglietto: due scansioni insieme (o la chiusura di fine fascia) non possono passare entrambe
            $order = Order::lockForUpdate()->find($token->order_id);
            $token = PickupToken::lockForUpdate()->find($token->id);
            $now = $this->clock->now();

            if (! $seller->seller || $seller->seller->id !== $order->seller_id) {
                $this->fail('WRONG_SHOP', 'Questo ordine è di un altro negozio.', 403);
            }
            if ($token->used_at !== null) {
                $this->fail('ALREADY_USED', 'Questo codice è già stato usato: l\'ordine è già stato ritirato.', 409, ['used_at' => $token->used_at->toIso8601String()]);
            }
            if ($order->status !== Order::PAID) {
                // Dopo la fine della fascia un ordine non ritirato risulta "scaduto"; gli altri stati (rimborsato, in reclamo…) non si ritirano
                $expired = $order->status === Order::NOT_PICKED_UP;
                $this->fail($expired ? 'EXPIRED' : 'ORDER_NOT_ACTIVE', $expired ? 'La fascia di ritiro di questo ordine è finita.' : 'Questo ordine non è più ritirabile.', 409);
            }
            if ($now->lt($token->valid_from)) {
                $this->fail('NOT_YET_VALID', 'Il ritiro non è ancora iniziato.', 409, ['valid_from' => $this->rome($token->valid_from)]);
            }
            if ($now->gt($token->valid_until)) {
                $this->fail('EXPIRED', 'La fascia di ritiro di questo ordine è finita.', 409);
            }

            // Aggiornamento atomico: se un'altra richiesta ha già usato il biglietto, qui non si modifica nessuna riga
            $used = PickupToken::where('id', $token->id)->whereNull('used_at')
                ->update(['used_at' => $now, 'scanned_by' => $seller->id, 'updated_at' => $now]);
            if ($used === 0) {
                $this->fail('ALREADY_USED', 'Questo codice è già stato usato: l\'ordine è già stato ritirato.', 409);
            }

            $order->status = Order::PICKED_UP;
            $order->picked_up_at = $now;
            $order->save();

            return $order;
        });
    }

    private function findToken(?string $qr, ?string $code): PickupToken
    {
        if ($qr !== null && trim($qr) !== '') {
            $jti = $this->verifiedJti(trim($qr));

            return ($jti !== null ? PickupToken::where('jti', $jti)->first() : null)
                ?? $this->fail('INVALID_QR', 'Codice QR non valido.', 422);
        }

        return PickupToken::where('short_code', $this->normalizeCode((string) $code))->first()
            ?? $this->fail('INVALID_CODE', 'Codice non riconosciuto. Controlla di averlo scritto giusto.', 422);
    }

    /** L'identificativo contenuto in un QR, se la firma è valida; altrimenti null. */
    private function verifiedJti(string $qr): ?string
    {
        $parts = explode('.', $qr);
        if (count($parts) !== 3 || $parts[0] !== self::PREFIX) {
            return null;
        }
        if (! hash_equals($this->sign($parts[1]), $parts[2])) {
            return null;
        }

        $data = json_decode((string) base64_decode(strtr($parts[1], '-_', '+/')), true);

        return is_array($data) && is_string($data['j'] ?? null) ? $data['j'] : null;
    }

    /** "abcd-efgh", " ABCD EFGH " → "ABCDEFGH". */
    private function normalizeCode(string $code): string
    {
        return Str::upper((string) preg_replace('/[^A-Za-z0-9]/', '', $code));
    }

    private function randomCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::CODE_LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }

    private function sign(string $data): string
    {
        // Chiave derivata da APP_KEY e usata solo per i QR: non coincide con altre chiavi dell'applicazione
        $key = hash_hmac('sha256', 'toogood-pickup-qr', (string) config('app.key'), true);

        return $this->b64(hash_hmac('sha256', self::PREFIX.'.'.$data, $key, true));
    }

    private function b64(string $raw): string
    {
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    private function rome(\DateTimeInterface $at): string
    {
        return CarbonImmutable::instance($at)->setTimezone(config('toogood.timezone'))->toIso8601String();
    }

    /** @param array<string, mixed> $extra */
    private function fail(string $code, string $message, int $status, array $extra = []): never
    {
        throw new HttpResponseException(response()->json(['code' => $code, 'message' => $message] + $extra, $status));
    }
}

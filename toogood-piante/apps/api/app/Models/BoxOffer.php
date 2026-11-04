<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Una box in vendita per un giorno. Importi in centesimi. */
class BoxOffer extends Model
{
    use SoftDeletes;

    public const STATUS_ACTIVE = 'ACTIVE';

    public const STATUS_CLOSED = 'CLOSED';

    // Nessun campo è assegnabile in massa: li imposta il servizio dopo aver validato e ricalcolato il prezzo.
    protected $guarded = ['*'];

    protected function casts(): array
    {
        return [
            'offer_date' => 'date:Y-m-d',
            'attributes' => 'array',
        ];
    }

    public function seller(): BelongsTo
    {
        return $this->belongsTo(Seller::class);
    }

    // La colonna si chiama "attributes", come la proprietà interna di Eloquent: si legge e scrive solo da qui.
    /** @return list<string> codici degli attributi della pianta */
    public function plantAttributes(): array
    {
        return $this->getAttribute('attributes') ?? [];
    }

    /** @param list<string> $codes */
    public function setPlantAttributes(array $codes): void
    {
        $this->setAttribute('attributes', array_values(array_unique($codes)));
    }

    /** Box impegnate: vendute (o in corso di pagamento), cioè totale meno disponibili. */
    public function sold(): int
    {
        return $this->stock_total - $this->stock_available;
    }
}

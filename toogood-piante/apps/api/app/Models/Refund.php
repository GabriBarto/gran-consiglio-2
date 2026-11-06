<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Rimborso al cliente eseguito su Stripe, in genere a seguito di un reclamo accolto. */
class Refund extends Model
{
    protected $guarded = ['*'];
}

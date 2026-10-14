<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Code expéditeur / bénéficiaire créé chez un partenaire de remittance (réutilisé d'un envoi à l'autre). */
class RemitParty extends Model
{
    protected $guarded = ['id'];
}

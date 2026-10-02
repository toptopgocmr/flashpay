<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** senderCode / beneficiaryCode WacePay déjà créés (évite 2 requêtes par transaction). */
class DigitwaceParty extends Model
{
    protected $guarded = ['id'];
}

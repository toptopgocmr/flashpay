<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Point de vente / sous-compte marchand (cf. §2 "Gérer plusieurs points
 * de vente / sous-comptes").
 */
class MerchantOutlet extends Model
{
    protected $fillable = ['merchant_id', 'name', 'address', 'qr_code_token', 'status'];

    public function merchant()
    {
        return $this->belongsTo(Merchant::class);
    }
}

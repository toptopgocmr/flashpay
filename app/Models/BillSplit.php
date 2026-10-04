<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Cagnotte : facture partagée ou cadeau commun (table historique bill_splits). */
class BillSplit extends Model
{
    public const PURPOSES = ['bill' => 'Facture partagée', 'gift' => 'Cadeau commun'];
    public const MODES = ['equal' => 'Parts égales', 'custom' => 'Parts fixées', 'free' => 'Montant libre'];
    public const STATUSES = ['open' => 'En cours', 'settled' => 'Complète', 'closed' => 'Clôturée', 'cancelled' => 'Annulée'];

    protected $table = 'bill_splits';
    protected $guarded = ['id'];

    public function creator() { return $this->belongsTo(User::class, 'creator_id'); }
    public function beneficiary() { return $this->belongsTo(User::class, 'beneficiary_user_id'); }
    public function shares() { return $this->hasMany(BillSplitShare::class); }

    protected function casts(): array
    {
        return ['total_amount' => 'integer', 'deadline' => 'date', 'closed_at' => 'datetime'];
    }

    /** Destinataire de l'argent (ancien partage de note : le créateur). */
    public function payee(): ?User
    {
        return $this->beneficiary ?? $this->creator;
    }
}

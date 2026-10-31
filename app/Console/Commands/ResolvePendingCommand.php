<?php

namespace App\Console\Commands;

use App\Services\Payments\PendingResolver;
use Illuminate\Console\Command;

/**
 * Opérations « En attente » : décision selon les réponses des partenaires.
 *
 *   php artisan transactions:resolve-pending                 simulation (rien n'est modifié)
 *   php artisan transactions:resolve-pending --force         règle les opérations figées (planifié toutes les 5 min)
 *   php artisan transactions:resolve-pending --all --force   annule TOUTES les opérations en attente
 *   … --reference=FP-XXXX                                    une ou plusieurs opérations précises
 *   … --include-open                                         (avec --all) même si un versement est encore ouvert
 *                                                            chez un partenaire depuis plus de 24 h — risque de double versement
 */
class ResolvePendingCommand extends Command
{
    protected $signature = 'transactions:resolve-pending
        {--all : Annuler toutes les opérations en attente (collectes et paiements carte compris)}
        {--force : Exécuter (sans cette option : simulation)}
        {--include-open : Avec --all : annuler même un versement encore ouvert chez un partenaire depuis plus de 24 h}
        {--minutes=10 : Ancienneté minimale (dernière mise à jour), en minutes}
        {--reference=* : Référence(s) FP-… à traiter}';

    protected $description = 'Opérations en attente : réussite, rejet + remboursement ou annulation selon les réponses des partenaires';

    public function handle(PendingResolver $resolver): int
    {
        $dry = ! $this->option('force');
        $all = (bool) $this->option('all');
        $rows = $resolver->candidates((int) $this->option('minutes'), (array) $this->option('reference'))
            ->map(fn ($tx) => $resolver->resolve($tx, $all, $dry, (bool) $this->option('include-open')));

        if ($rows->isEmpty()) {
            $this->info('Aucune opération en attente.');
            return self::SUCCESS;
        }
        $this->table(['Référence', 'Étape', 'Action', 'Statut', 'Détail'], $rows->map(fn ($r) => array_values($r))->all());
        $this->line(($dry ? 'SIMULATION — relancer avec --force pour appliquer. ' : '') . $rows->countBy('action')->map(fn ($n, $a) => "{$a} : {$n}")->implode(' · '));

        return $rows->contains('action', 'erreur') ? self::FAILURE : self::SUCCESS;
    }
}

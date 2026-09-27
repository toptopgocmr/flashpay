<?php

namespace App\Services\Connectors\Contracts;

/**
 * Contrat commun à tous les connecteurs de rails de paiement.
 * Seule implémentation actuelle : PeexConnector (MTN, Airtel, Orange, Moov… via PEEX).
 * Un futur gateway devra implémenter cette interface pour être branché sur le Switch.
 */
interface PaymentRailConnector
{
    /**
     * Débite un compte externe (ex: wallet MTN MoMo d'un client) au profit de FlashPay.
     *
     * @param string $msisdnOrAccount Numéro / identifiant du compte à débiter
     * @param int $amountMinor Montant en unité mineure (XAF entier ici, pas de centimes)
     * @param string $currency
     * @param string $reference Référence interne FlashPay (idempotency key)
     * @return array{status:string, external_ref:string, raw:array}
     */
    public function collect(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array;

    /**
     * Crédite un compte externe (ex: wallet Airtel Money d'un marchand) depuis FlashPay.
     *
     * @return array{status:string, external_ref:string, raw:array}
     */
    public function disburse(string $msisdnOrAccount, int $amountMinor, string $currency, string $reference): array;

    /**
     * Vérifie le statut d'une opération précédemment initiée chez le partenaire.
     */
    public function checkStatus(string $externalRef): array;

    /**
     * Nom technique du rail (utilisé pour le logging / ledger).
     */
    public function railName(): string;
}

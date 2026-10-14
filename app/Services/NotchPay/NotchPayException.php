<?php

namespace App\Services\NotchPay;

/** Erreur Notch Pay : statut HTTP et réponse de l'API. */
class NotchPayException extends \RuntimeException
{
    public function __construct(string $message, public ?int $httpStatus = null, public array $response = [], public bool $definitive = false)
    {
        parent::__construct($message);
    }

    /**
     * Refus explicite : Notch Pay n'a rien exécuté (échec certain, remboursement possible).
     * 4xx = requête refusée (clé, IP, validation, solde…), sauf 408 / 409 / 429 (issue incertaine).
     * 5xx / réseau = incertain → on revérifie le statut avant toute décision.
     */
    public function isDefinitive(): bool
    {
        return $this->definitive
            || ($this->httpStatus !== null && $this->httpStatus >= 400 && $this->httpStatus < 500 && ! in_array($this->httpStatus, [408, 409, 429], true));
    }
}

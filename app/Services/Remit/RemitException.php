<?php

namespace App\Services\Remit;

/** Erreur d'un partenaire de remittance (WacePay API v2 / TerraPay). */
class RemitException extends \RuntimeException
{
    /** Codes qui ne prouvent PAS que rien n'a été exécuté (erreur système, délai). */
    protected const UNCERTAIN = ['1005', '5000', '5001', '5002', '5003', '5004', '6000', '9999'];

    public function __construct(string $message, public ?string $partnerCode = null, public ?int $httpStatus = null, public array $response = [], public bool $definitive = false)
    {
        parent::__construct($message);
    }

    /**
     * Refus explicite : le partenaire n'a rien exécuté → échec certain (remboursement possible).
     * Réseau, 5xx, erreur système → issue incertaine : on revérifie le statut avant de décider.
     */
    public function isDefinitive(): bool
    {
        if ($this->definitive) {
            return true;
        }
        if ($this->httpStatus !== null && ($this->httpStatus >= 500 || in_array($this->httpStatus, [408, 409, 429], true))) {
            return false;
        }
        if ($this->partnerCode !== null && in_array($this->partnerCode, self::UNCERTAIN, true)) {
            return false;
        }
        if ($this->partnerCode !== null && preg_match('/^\d{4}$/', $this->partnerCode)) {
            return true; // code métier = refus de validation
        }
        return $this->httpStatus !== null && $this->httpStatus >= 400 && $this->httpStatus < 500;
    }
}

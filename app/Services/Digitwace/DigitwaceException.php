<?php

namespace App\Services\Digitwace;

/** Erreur WacePay : code métier (2000 = OK, 1001-1017, 2001, 3001, 3002, 3015-3016, 4001), statut HTTP et réponse. */
class DigitwaceException extends \RuntimeException
{
    public function __construct(string $message, public ?string $waceCode = null, public ?int $httpStatus = null, public array $response = [])
    {
        parent::__construct($message);
    }

    /** Refus explicite : WacePay n'a rien exécuté (échec certain, remboursement possible). */
    public function isDefinitive(): bool
    {
        return $this->waceCode !== null && ! in_array($this->waceCode, ['2001', '3001'], true)
            || ($this->httpStatus !== null && $this->httpStatus >= 400 && $this->httpStatus < 500 && ! in_array($this->httpStatus, [401, 408, 409, 429], true));
    }
}

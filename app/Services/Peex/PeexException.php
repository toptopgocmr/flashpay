<?php

namespace App\Services\Peex;

class PeexException extends \RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 0, public array $body = [])
    {
        parent::__construct($message, $httpStatus);
    }

    /**
     * Refus explicite de PEEX (4xx) : la demande n'a PAS été exécutée.
     * Coupure réseau (0), 408, 429 et 5xx : issue INCERTAINE — PEEX a pu
     * traiter la demande, il faut vérifier son statut avant toute décision.
     */
    public function isDefinitive(): bool
    {
        return $this->httpStatus >= 400 && $this->httpStatus < 500
            && ! in_array($this->httpStatus, [408, 425, 429], true);
    }
}

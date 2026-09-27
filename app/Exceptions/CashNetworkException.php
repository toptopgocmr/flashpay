<?php

namespace App\Exceptions;

use RuntimeException;

/** Erreur métier du réseau cash (code invalide, bon expiré, moyen indisponible…). */
class CashNetworkException extends RuntimeException
{
    public function __construct(string $message, public int $status = 422)
    {
        parent::__construct($message);
    }
}

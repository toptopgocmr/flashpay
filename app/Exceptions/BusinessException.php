<?php

namespace App\Exceptions;

/**
 * Refus métier explicite (plafond, PIN, fraude, canal indisponible…),
 * rendu en JSON { message, code } avec le statut HTTP fourni.
 */
class BusinessException extends \RuntimeException
{
    public function __construct(string $message, public string $errorCode = 'refused', public int $status = 422, public array $extra = [])
    {
        parent::__construct($message);
    }
}

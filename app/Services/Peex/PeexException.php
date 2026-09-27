<?php

namespace App\Services\Peex;

class PeexException extends \RuntimeException
{
    public function __construct(string $message, public int $httpStatus = 0, public array $body = [])
    {
        parent::__construct($message, $httpStatus);
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

class ConversionFailure extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly bool $transient = false)
    {
        parent::__construct($reason);
    }
}

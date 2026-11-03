<?php

namespace App\Exceptions;

use Exception;

class PlaybackDeviceFenced extends Exception
{
    public function __construct(string $message = 'دستگاه دیگری کنترل پخش را در اختیار دارد.')
    {
        parent::__construct($message);
    }
}

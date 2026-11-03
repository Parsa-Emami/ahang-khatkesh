<?php

namespace App\Exceptions;

use Exception;

class OfflineDisabled extends Exception
{
    public function __construct(string $message = 'دانلود آفلاین صوت خصوصی بدون تأیید محدودیت ابطال فعال نیست.')
    {
        parent::__construct($message);
    }
}

<?php

namespace App\Exceptions;

use RuntimeException;

class MediaNotReady extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('نسخهٔ MP3 هنوز آماده نیست؛ وضعیت تبدیل صوت را بررسی کنید.');
    }
}

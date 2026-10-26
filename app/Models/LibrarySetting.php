<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class LibrarySetting extends Model
{
    public const MAX_USERS = 10;

    protected $fillable = ['id', 'lock_version'];
}

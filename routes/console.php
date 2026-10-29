<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('library:maintain')->everyMinute()->withoutOverlapping(5);

Schedule::command('listening:prune')->everyMinute()->withoutOverlapping(5);

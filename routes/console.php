<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Question (d): where does the scheduler run, and can it see the instance's
// database? A row labelled "scheduler" in the probes table is the answer.
Schedule::command('probe:write --label=scheduler')->everyMinute()->withoutOverlapping();

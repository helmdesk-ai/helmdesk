<?php

use Illuminate\Support\Facades\Schedule;

Schedule::command('attachments:abort-expired-multipart')->everyTenMinutes();
Schedule::command('attachments:cleanup')->hourly();
Schedule::command('telegram:reconcile-deliveries')->everyFiveMinutes()->withoutOverlapping();

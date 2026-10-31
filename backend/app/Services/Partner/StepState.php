<?php

namespace App\Services\Partner;

/**
 * ADR-133 decision 13. `WaitingOnUs` is a step only PekanGame can finish (CHIP-gated payments
 * approval): the partner sees "waiting on us", never a promise of a date.
 */
enum StepState: string
{
    case Done = 'done';
    case Todo = 'todo';
    case WaitingOnUs = 'waiting_on_us';
    case Optional = 'optional';
}

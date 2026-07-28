<?php

namespace App\Services\Fraud;

/**
 * FRAUD-1: an entry blocks on exactly one of these, never a
 * free-typed field - matches ADR-007's "player IDs and/or customer
 * contacts" wording.
 */
enum BlacklistEntryType: string
{
    case PlayerId = 'player_id';
    case Email = 'email';
    case Phone = 'phone';
}

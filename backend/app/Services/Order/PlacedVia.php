<?php

namespace App\Services\Order;

/**
 * ADR-104 2026-10-08 addendum R16 — the door an order came in through.
 * Not who owns it (that is `affiliate_id` / `wallet_reseller_id`, the
 * Orders list "Source"), and not `channel_code` (CHIP's payment
 * channel). A reseller-wallet order is `ResellerApi` or `ResellerBot`;
 * the reseller portal places no orders today — if it ever does, it needs
 * its own case here.
 */
enum PlacedVia: string
{
    case Storefront = 'storefront';
    case ResellerApi = 'reseller_api';
    case ResellerBot = 'reseller_bot';
    case Sandbox = 'sandbox';
}

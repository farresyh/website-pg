<?php

namespace App\Services\Membership;

use RuntimeException;

/**
 * ADR-068 S3 — the payment gateway rejected `createPayment()` for a
 * membership subscription. The attempt row is already marked `failed`;
 * the controller turns this into a 502 so the storefront can offer a
 * retry.
 */
class MembershipSubscriptionException extends RuntimeException {}

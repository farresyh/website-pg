<?php

namespace App\Services\Reseller;

use RuntimeException;

/** The CHIP `createPayment()` call itself failed — no attempt is left pending with no purchase behind it. */
final class WalletTopupCheckoutFailedException extends RuntimeException {}

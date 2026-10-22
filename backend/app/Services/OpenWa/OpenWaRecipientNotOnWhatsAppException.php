<?php

namespace App\Services\OpenWa;

use RuntimeException;

/**
 * PRD §16 item 96: OpenWA (v0.25.0+) refused a send because WhatsApp says the
 * number is not on WhatsApp. Retrying cannot help, so both send jobs fail at
 * once on it (`FailOnException`). Any other failure, including the 400 OpenWA
 * answers while a session is still starting, keeps retrying.
 */
final class OpenWaRecipientNotOnWhatsAppException extends RuntimeException {}

<?php

namespace App\Services\Llm;

use RuntimeException;

/** The provider was unreachable, refused, or answered in an unexpected shape. */
final class ChatModelException extends RuntimeException {}

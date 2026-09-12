<?php

namespace App\Services\ReportAssistant;

use RuntimeException;

/**
 * Thrown by SqlGuard when a Gemini-generated query fails a guardrail
 * check (ADR-087 decision 2). Always caught by ReportAssistantService —
 * never allowed to bubble to the HTTP layer as a 500, since a rejected
 * query is an expected outcome, not a server error.
 */
class UnsafeSqlException extends RuntimeException {}

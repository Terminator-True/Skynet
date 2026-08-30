<?php

namespace App\Services\Opencode\Exceptions;

/**
 * Transport-level failure talking to the local OpenCode headless server
 * (connection refused, timeout, non-2xx). Tools catch this and translate it
 * to a structured error so the conversation never breaks (R-5).
 */
final class OpencodeConnectionException extends \RuntimeException
{
}

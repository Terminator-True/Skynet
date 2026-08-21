<?php

namespace App\Services;

use RuntimeException;

/** Thrown when the model keeps calling tools past the configured iteration cap. */
class ChatLoopExhaustedException extends RuntimeException
{
    //
}

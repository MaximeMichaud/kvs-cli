<?php

declare(strict_types=1);

namespace KVS\CLI\Util;

/**
 * Output buffering helpers
 */
final class OutputBuffer
{
    /**
     * Get the active buffer contents and delete the buffer, always as a string.
     *
     * The false case (no active buffer) must be handled in a scope without a
     * visible ob_start(): PHPStan >= 2.2.2 narrows ob_get_clean() to string
     * right after ob_start(), so an inline false check is reported as
     * always-true while older versions require it.
     */
    public static function getClean(): string
    {
        $output = ob_get_clean();

        return $output === false ? '' : $output;
    }
}

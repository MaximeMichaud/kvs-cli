<?php

namespace KVS\CLI\Command\Traits;

/**
 * Provides environment-based security checks for eval commands.
 *
 * Used by EvalCommand and EvalFileCommand to prevent accidental
 * code execution in production environments.
 */
trait EvalSecurityTrait
{
    /**
     * Check if eval is allowed in the current environment.
     *
     * Returns true if:
     * - KVS_ENV is 'dev', 'development', 'test', or not set (defaults to dev)
     * - OR KVS_ALLOW_EVAL is explicitly set to 'true' or '1'
     *
     * This prevents accidental execution in production environments.
     */
    private function isEvalAllowed(): bool
    {
        // Explicit override takes precedence
        $allowEval = getenv('KVS_ALLOW_EVAL');
        if ($allowEval === 'true' || $allowEval === '1') {
            return true;
        }

        // Check environment - default to allowing (dev mode)
        $env = getenv('KVS_ENV');
        if ($env === false || $env === '') {
            // No environment set - assume dev, allow eval
            return true;
        }

        // Allow in dev/test environments
        $allowedEnvs = ['dev', 'development', 'test', 'testing', 'local'];
        return in_array(strtolower($env), $allowedEnvs, true);
    }
}

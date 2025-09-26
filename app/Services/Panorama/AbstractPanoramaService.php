<?php

namespace App\Services\Panorama;

use Illuminate\Support\Facades\Log;

abstract class AbstractPanoramaService
{
    /**
     * Log a message with context for debugging and audit purposes
     *
     * @param string $level Log level (info, warning, error, debug)
     * @param string $message Log message
     * @param array $context Additional context data
     */
    protected function log(string $level, string $message, array $context = []): void
    {
        Log::channel('single')->{$level}("[Panorama] {$message}", $context);
    }

    /**
     * Handle exceptions with proper logging and context
     *
     * @param \Exception $e Exception to handle
     * @param string $operation Operation that failed
     * @param array $context Additional context
     * @throws \Exception Re-throws the exception after logging
     */
    protected function handleException(\Exception $e, string $operation, array $context = []): void
    {
        $this->log('error', "Failed to {$operation}: {$e->getMessage()}", array_merge($context, [
            'exception' => get_class($e),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
        ]));

        throw $e;
    }
}
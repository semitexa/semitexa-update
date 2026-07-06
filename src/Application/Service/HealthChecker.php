<?php

declare(strict_types=1);

namespace Semitexa\Update\Application\Service;

use Semitexa\Update\Domain\Model\PreflightCheck;

/**
 * Post-update HTTP smoke: one GET against the configured health URL, 2xx
 * means the application actually serves traffic after the update. Shared by
 * the manual sweep's health-check stage and the auto-deploy pipeline.
 */
final class HealthChecker
{
    public function check(string $url, int $timeoutSeconds = 10): PreflightCheck
    {
        $context = stream_context_create([
            'http' => [
                'timeout' => $timeoutSeconds,
                'ignore_errors' => true,
                'header' => "User-Agent: Semitexa-Update-Healthcheck\r\n",
            ],
        ]);

        $response = @file_get_contents($url, false, $context);
        $statusCode = 0;
        if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m) === 1) {
            $statusCode = (int) $m[1];
        }

        if ($response === false) {
            return new PreflightCheck('http', false, sprintf('Health check failed: no response from %s.', $url));
        }
        if ($statusCode < 200 || $statusCode >= 300) {
            return new PreflightCheck('http', false, sprintf('Health check failed: %s answered HTTP %d.', $url, $statusCode));
        }

        return new PreflightCheck('http', true, sprintf('%s answered HTTP %d.', $url, $statusCode));
    }
}

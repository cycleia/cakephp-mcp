<?php
declare(strict_types=1);

namespace CakeMcp\Http;

use RuntimeException;

/**
 * An OAuth error response: `error` is the RFC 6749 / RFC 7591 error code, the message is
 * `error_description`.
 */
class OAuthException extends RuntimeException
{
    public string $error;

    public int $status;

    /**
     * @var array<string, string>
     */
    public array $headers;

    /**
     * @param string $error OAuth error code, e.g. invalid_grant.
     * @param string $description Human-readable description.
     * @param int $status HTTP status.
     * @param array<string, string> $headers Extra response headers.
     */
    public function __construct(string $error, string $description = '', int $status = 400, array $headers = [])
    {
        parent::__construct($description);
        $this->error = $error;
        $this->status = $status;
        $this->headers = $headers;
    }
}

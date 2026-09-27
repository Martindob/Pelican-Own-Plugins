<?php

namespace Boy132\MinecraftModrinth\GitHub;

use RuntimeException;

/**
 * Every failure of the GitHub repository source, with a machine readable reason.
 *
 * Deliberately created without a previous exception: HTTP client exceptions carry the request
 * (including its Authorization header) around, and this is what ends up in logs.
 */
class GitHubSourceException extends RuntimeException
{
    public const NOT_CONFIGURED = 'not_configured';

    public const UNAUTHORIZED = 'unauthorized';

    public const FORBIDDEN = 'forbidden';

    public const NOT_FOUND = 'not_found';

    public const RATE_LIMITED = 'rate_limited';

    public const NETWORK = 'network';

    public const SERVER_ERROR = 'server_error';

    public const INVALID_RESPONSE = 'invalid_response';

    public const INVALID_INDEX = 'invalid_index';

    public const UNSUPPORTED_SCHEMA = 'unsupported_schema';

    public const CHECKSUM_MISMATCH = 'checksum_mismatch';

    public const TOO_LARGE = 'too_large';

    public const CI_NOT_GREEN = 'ci_not_green';

    public const FILE_CONFLICT = 'file_conflict';

    public const METADATA = 'metadata';

    public const DAEMON = 'daemon';

    public const OFFER_STORE = 'offer_store';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct(GitHubSourceRules::redact($message));
    }

    /** Translated, user-facing explanation (no internals, no URLs). */
    public function getUserMessage(): string
    {
        $key = 'minecraft-modrinth::strings.github.errors.'.$this->reason;
        $message = trans($key, ['seconds' => $this->retryAfter ?? 60]);

        return $message === $key ? trans('minecraft-modrinth::strings.github.errors.unknown') : $message;
    }
}

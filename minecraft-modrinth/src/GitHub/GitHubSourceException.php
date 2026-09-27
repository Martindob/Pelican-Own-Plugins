<?php

namespace Boy132\MinecraftModrinth\GitHub;

use Boy132\MinecraftModrinth\Support\DaemonFileException;
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

    public const DISK_FULL = 'disk_full';

    /** The server's node doesn't know the server (any more): nothing to do on it. */
    public const UNKNOWN_SERVER = 'unknown_server';

    /** "Install" of a plugin that got installed in the meantime (or "Update" of one that is gone). */
    public const STATE_CHANGED = 'state_changed';

    /** Updated, but the old jar couldn't be deleted yet: recorded and deleted later. */
    public const OLD_JAR_PENDING = 'old_jar_pending';

    public function __construct(public readonly string $reason, string $message, public readonly ?int $retryAfter = null)
    {
        parent::__construct(GitHubSourceRules::redact($message));
    }

    /** The same failure of a Wings file operation, as one of ours ($fallbackReason for plain failures). */
    public static function fromDaemon(DaemonFileException $exception, string $fallbackReason = self::DAEMON): self
    {
        $reason = match ($exception->reason) {
            DaemonFileException::DISK_FULL => self::DISK_FULL,
            DaemonFileException::UNKNOWN_SERVER => self::UNKNOWN_SERVER,
            DaemonFileException::CONFLICT => self::FILE_CONFLICT,
            DaemonFileException::TOO_LARGE => self::METADATA,
            default => $fallbackReason,
        };

        return new self($reason, $exception->getMessage());
    }

    /** Translated, user-facing explanation (no internals, no URLs). */
    public function getUserMessage(): string
    {
        $key = 'minecraft-modrinth::strings.github.errors.'.$this->reason;
        $message = trans($key, ['seconds' => $this->retryAfter ?? 60]);

        return $message === $key ? trans('minecraft-modrinth::strings.github.errors.unknown') : $message;
    }
}

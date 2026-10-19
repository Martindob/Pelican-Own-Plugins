<?php

namespace Boy132\MinecraftModrinth\LuckPerms;

use Boy132\MinecraftModrinth\Support\DaemonFileException;
use RuntimeException;

/**
 * Every failure of the LuckPerms source, with a machine readable reason. Created without a
 * previous exception (HTTP client exceptions carry the whole request around), so the message
 * is all that ends up in the log - one line.
 */
class LuckPermsSourceException extends RuntimeException
{
    public const DISABLED = 'disabled';

    public const UNSUPPORTED = 'unsupported';

    public const NETWORK = 'network';

    public const INVALID_METADATA = 'invalid_metadata';

    /** The metadata has no download for this server's platform. */
    public const NO_DOWNLOAD = 'no_download';

    public const DOWNLOAD_FAILED = 'download_failed';

    public const TOO_LARGE = 'too_large';

    /** The downloaded file is not the LuckPerms build that was asked for. */
    public const INVALID_JAR = 'invalid_jar';

    /** Several LuckPerms jars, one of the wrong platform, or one that couldn't be checked. */
    public const CONFLICT = 'conflict';

    /** Installed, updated or removed in the meantime, or another installation is running. */
    public const STATE_CHANGED = 'state_changed';

    public const NOT_NEWER = 'not_newer';

    /** The Modrinth metadata of the server can't be read or changed. */
    public const MODRINTH_METADATA = 'modrinth_metadata';

    public const DAEMON = 'daemon';

    public const DISK_FULL = 'disk_full';

    public const UNKNOWN_SERVER = 'unknown_server';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }

    public static function fromDaemon(DaemonFileException $exception): self
    {
        $reason = match ($exception->reason) {
            DaemonFileException::DISK_FULL => self::DISK_FULL,
            DaemonFileException::UNKNOWN_SERVER => self::UNKNOWN_SERVER,
            DaemonFileException::CONFLICT => self::CONFLICT,
            DaemonFileException::TOO_LARGE => self::TOO_LARGE,
            default => self::DAEMON,
        };

        return new self($reason, $exception->getMessage());
    }

    /** Translated, user-facing explanation (no internals, no URLs). */
    public function getUserMessage(): string
    {
        $key = 'minecraft-modrinth::strings.luckperms.errors.'.$this->reason;
        $message = trans($key);

        return $message === $key ? trans('minecraft-modrinth::strings.luckperms.errors.unknown') : $message;
    }
}

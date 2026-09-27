<?php

namespace Boy132\MinecraftModrinth\Support;

use RuntimeException;

/**
 * A failed Wings file operation, with a reason the callers can act on. Created without the
 * HTTP client exception as previous one (that carries the request around).
 */
class DaemonFileException extends RuntimeException
{
    /** The node doesn't know this server at all (deleted, transferred, ...): skip it. */
    public const UNKNOWN_SERVER = 'unknown_server';

    public const TOO_LARGE = 'too_large';

    public const DISK_FULL = 'disk_full';

    /** The target name exists already (e.g. a rename onto an existing file). */
    public const CONFLICT = 'conflict';

    public const FAILED = 'failed';

    public function __construct(public readonly string $reason, string $message)
    {
        parent::__construct($message);
    }
}

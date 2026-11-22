<?php

namespace Boy132\PlayerCounter\Support;

/**
 * Console commands built from a player name. The name comes from the game server's query
 * answer - i.e. from whoever joined or whatever a plugin reports - so it is never put into a
 * command unless it is a single plain word: no spaces (an extra argument), no ";" (a second
 * command on Source engines), no quotes, newlines or other control characters.
 */
final class PlayerCommand
{
    public static function isSafeName(string $name): bool
    {
        return (bool) preg_match('/^[\p{L}\p{N}_.][\p{L}\p{N}_.\-]{0,63}$/u', $name);
    }

    /** "$command $name", or null when the name can't be used safely. */
    public static function build(string $command, string $name): ?string
    {
        return self::isSafeName($name) ? "$command $name" : null;
    }
}

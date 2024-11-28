<?php

namespace ByErikas\ClassicTaggableCache\Cache\Traits;

trait CleansKeys
{
    protected const RESERVED_CHARACTERS = "{}()/\@:";
    protected const RESERVED_CHARACTERS_MAP = [
        ":"     => ".",
        "@"     => "\1",
        "("     => "\2",
        ")"     => "\3",
        "{"     => "\4",
        "}"     => "\5",
        "/"     => "\6",
        "\\"    => "\7"
    ];

    /**
     * PSR-6 doesn't allow '{}()/\@:' as cache keys, replace with unique map.
     */
    public static function cleanKey(?string $key): string
    {
        return str_replace(str_split(self::RESERVED_CHARACTERS), self::RESERVED_CHARACTERS_MAP, $key);
    }
}

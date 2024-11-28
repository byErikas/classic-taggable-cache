<?php

namespace ByErikas\ClassicTaggableCache\Cache;

use ByErikas\ClassicTaggableCache\Cache\Traits\MethodOverrides;
use Illuminate\Cache\RedisTaggedCache as BaseTaggedCache;

class Cache extends BaseTaggedCache
{
    use MethodOverrides;

    public const DEFAULT_CACHE_TTL = 8640000;

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
     * {@inheritDoc}
     */
    public function get($key, $default = null): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

        $key = self::cleanKey($key);
        $tagNames = $this->tags->getNames();

        $this->event(new RetrievingKey($this->getName(), $key, $tagNames));

        /**@disregard P1013 */
        foreach ($this->tags->entries() as $itemKey) {
            if (str($itemKey)->after(TagSet::KEY_PREFIX) == $key) {
                $value = $this->store->get($itemKey);

                $this->event(new CacheHit($this->getName(), $key, $value, $tagNames));
                return $value;
            }
        }

        $this->event(new CacheMissed($this->getName(), $key, $tagNames));
        return value($default);
    }

    /**
     * {@inheritDoc}
     */
    public function add($key, $value, $ttl = null)
    {
        $key = self::cleanKey($key);
        /**
         * @disregard P1013 - @var \TagSet
         */
        foreach ($this->tags->entries() as $itemKey) {
            if (str($itemKey)->after(TagSet::KEY_PREFIX) == $key) {
                return false;
            }
        }

        return $this->put($key, $value, $ttl);
    }

    /**
     * {@inheritDoc}
     */
    public function put($key, $value, $ttl = null)
    {
        $key = self::cleanKey($key);

        $foundKey = false;
        /**@disregard P1013 */
        foreach ($this->tags->entries() as $itemKey) {
            if (str($itemKey)->after(TagSet::KEY_PREFIX) == $key) {
                $foundKey = true;
                $key = $itemKey;
            }
        }

        if (!$foundKey) {
            $key = $this->itemKey($key);
        }

        if (is_null($ttl)) {
            return $this->forever($key, $value, true);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds > 0) {
            /**
             * @disregard P1013 - @var \TagSet
             */
            $this->tags->addEntry($key, $seconds);
        }

        return $this->putCache($key, $value, $ttl);
    }

    #region Helpers

    /**
     * {@inheritdoc}
     */
    protected function itemKey($key)
    {
        /** @disregard P1013 */
        return "tagged\0" . $this->tags->tagNamePrefix() . TagSet::KEY_PREFIX . "{$key}";
    }
}

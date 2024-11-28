<?php

namespace ByErikas\ClassicTaggableCache\Cache;

use ByErikas\ClassicTaggableCache\Cache\Traits\MethodOverrides;
use Illuminate\Cache\RedisTaggedCache as BaseTaggedCache;

class Cache extends BaseTaggedCache
{
    use MethodOverrides;

    public const DEFAULT_CACHE_TTL = 8640000;

    /**
     * {@inheritdoc}
     */
    protected function itemKey($key)
    {
        /** @disregard P1013 */
        return "tagged\0" . $this->tags->tagNamePrefix() . TagSet::KEY_PREFIX . "{$key}";
    }
}

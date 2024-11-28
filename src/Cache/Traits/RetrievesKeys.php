<?php

namespace ByErikas\ClassicTaggableCache\Cache\Traits;

use ByErikas\ClassicTaggableCache\Cache\TagSet;

trait RetrievesKeys
{
    /**
     * Check if key exists in TagSet, if so return it, else make a tagged key.
     * 
     * Returns array, [true/false if exists, key]
     * 
     * @return array index 0 (bool) - if key exists, index 1 - key
     */
    protected function getKey(string $key): array
    {
        foreach ($this->tags->entries() as $itemKey) {
            if (str($itemKey)->after(TagSet::KEY_PREFIX) == $key) {
                return [true, $itemKey];
            }
        }

        return [false, $this->itemKey($key)];
    }
}

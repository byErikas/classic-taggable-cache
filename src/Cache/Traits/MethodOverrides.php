<?php

namespace ByErikas\ClassicTaggableCache\Cache\Traits;

use Carbon\Carbon;
use Closure;
use Illuminate\Cache\Events\CacheHit;
use Illuminate\Cache\Events\CacheMissed;
use Illuminate\Cache\Events\ForgettingKey;
use Illuminate\Cache\Events\KeyForgetFailed;
use Illuminate\Cache\Events\KeyForgotten;
use Illuminate\Cache\Events\KeyWriteFailed;
use Illuminate\Cache\Events\KeyWritten;
use Illuminate\Cache\Events\RetrievingKey;
use Illuminate\Cache\Events\WritingKey;

/**
 * Cache method overrides
 *
 * Moved here to not clutter the main Cache file.
 *
 */
trait MethodOverrides
{
    use CleansKeys, RetrievesKeys;

    /**
     * Retrieve an item from the cache by key.
     *
     * @param  array|string  $key
     * @param  mixed  $default
     * @return mixed
     */
    public function get($key, $default = null, bool $useOriginalKey = false): mixed
    {
        if (is_array($key)) {
            return $this->many($key);
        }

        $originalKey = self::cleanKey($key);
        $tagNames = $this->tags->getNames();

        $this->event(new RetrievingKey($this->getName(), $originalKey, $tagNames));

        if ($useOriginalKey) {
            $key = $originalKey;
        } else {
            [$exists, $key] = $this->getKey($originalKey);
        }

        $value = $this->store->get($key);

        if (!is_null($value)) {
            $this->event(new CacheHit($this->getName(), $originalKey, $value, $tagNames));
            return $value;
        }

        $this->event(new CacheMissed($this->getName(), $originalKey, $tagNames));
        return value($default);
    }

    /**
     * Store an item in the cache if the key does not exist.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     * @return bool
     */
    public function add($key, $value, $ttl = null)
    {
        $originalKey = self::cleanKey($key);

        [$exists, $key] = $this->getKey($originalKey);
        if ($exists) {
            return false;
        }

        return $this->put($key, $value, $ttl, true);
    }

    /**
     * Store an item in the cache.
     *
     * @param  array|string  $key
     * @param  mixed  $value
     * @param  \DateTimeInterface|\DateInterval|int|null  $ttl
     * @return bool
     */
    public function put($key, $value, $ttl = null, bool $useOriginalKey = false)
    {
        $originalKey = self::cleanKey($key);

        if ($useOriginalKey) {
            $key = $originalKey;
        } else {
            [$exists, $key] = $this->getKey($originalKey);
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

        if (is_array($key)) {
            return $this->putMany($key, $value);
        }

        if ($ttl === null) {
            return $this->forever($key, $value, true);
        }

        $seconds = $this->getSeconds($ttl);

        if ($seconds <= 0) {
            return $this->forget($key);
        }

        $this->event(new WritingKey($this->getName(), $originalKey, $value, $seconds, $this->tags->getNames()));

        $result = $this->store->put($key, $value, $seconds);

        if ($result) {
            $this->event(new KeyWritten($this->getName(), $originalKey, $value, $seconds), $this->tags->getNames());
        } else {
            $this->event(new KeyWriteFailed($this->getName(), $originalKey, $value, $seconds, $this->tags->getNames()));
        }

        return $result;
    }

    /**
     * Store an item in the cache indefinitely.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return bool
     */
    public function forever($key, $value, bool $useOriginalKey = false)
    {
        $originalKey = self::cleanKey($key);

        if (!$useOriginalKey) {
            [$exists, $key] = $this->getKey($originalKey);
        }

        /**@disregard P1013 */
        $this->tags->addEntry($key);

        $this->event(new WritingKey($this->getName(), $originalKey, $value, null, $this->tags->getNames()));

        $result = $this->store->forever($key, $value);

        if ($result) {
            $this->event(new KeyWritten($this->getName(), $originalKey, $value, null, $this->tags->getNames()));
        } else {
            $this->event(new KeyWriteFailed($this->getName(), $originalKey, $value, null, $this->tags->getNames()));
        }

        return $result;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result.
     *
     * @template TCacheValue
     *
     * @param  string  $key
     * @param  \Closure|\DateTimeInterface|\DateInterval|int|null  $ttl
     * @param  \Closure(): TCacheValue  $callback
     * @return TCacheValue
     */
    public function remember($key, $ttl, Closure $callback)
    {
        $originalKey = self::cleanKey($key);
        [$exists, $key] = $this->getKey($originalKey);

        $value = $this->get($key, useOriginalKey: true);

        if (! is_null($value)) {
            return $value;
        }

        $value = $callback();

        $this->put($key, $value, value($ttl, $value), true);

        return $value;
    }

    /**
     * Get an item from the cache, or execute the given Closure and store the result forever.
     *
     * @template TCacheValue
     *
     * @param  string  $key
     * @param  \Closure(): TCacheValue  $callback
     * @return TCacheValue
     */
    public function rememberForever($key, Closure $callback)
    {
        $originalKey = self::cleanKey($key);
        [$exists, $key] = $this->getKey($originalKey);

        $value = $this->get($key, useOriginalKey: true);

        if (! is_null($value)) {
            return $value;
        }

        $this->forever($key, $value = $callback(), true);

        return $value;
    }


    /**
     * Increment the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return int|bool
     */
    public function increment($key, $value = 1)
    {
        $originalKey = self::cleanKey($key);
        [$exists, $key] = $this->getKey($originalKey);

        $this->tags->addEntry($key, updateWhen: 'NX');
        return $this->store->increment($key, $value);
    }

    /**
     * Decrement the value of an item in the cache.
     *
     * @param  string  $key
     * @param  mixed  $value
     * @return int|bool
     */
    public function decrement($key, $value = 1)
    {
        $originalKey = self::cleanKey($key);
        [$exists, $key] = $this->getKey($originalKey);

        $this->tags->addEntry($key, updateWhen: 'NX');
        return $this->store->decrement($key, $value);
    }

    /**
     * Remove an item from the cache.
     *
     * @param  string  $key
     * @return bool
     */
    public function forget($key)
    {
        $originalKey = self::cleanKey($key);
        [$exists, $key] = $this->getKey($originalKey);

        $tags = $this->tags->getNames();

        $this->event(new ForgettingKey($this->getName(), $originalKey));

        return tap($this->store->forget($key), function ($result) use ($originalKey, $tags) {
            if ($result) {
                $this->event(new KeyForgotten($this->getName(), $originalKey, $tags));
            } else {
                $this->event(new KeyForgetFailed($this->getName(), $originalKey, $tags));
            }
        });
    }
}

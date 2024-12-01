<?php

namespace ByErikas\ClassicTaggableCache\Cache;

use Carbon\Carbon;
use Generator;
use Illuminate\Cache\RedisTagSet as BaseTagSet;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Illuminate\Support\LazyCollection;
use Illuminate\Contracts\Cache\Store;

class TagSet extends BaseTagSet
{
    public const TAG_PREFIX = "tags\0";
    public const KEY_PREFIX = "\0key\0";

    protected ?LazyCollection $entries;

    /**
     * Create a new TagSet instance.
     *
     * @param  \Illuminate\Contracts\Cache\Store  $store
     * @param  array  $names
     * @return void
     */
    public function __construct(Store $store, array $names = [])
    {
        $this->store = $store;
        $this->names = $names;

        $this->setEntries();
    }

    /**
     * Get the unique tag identifier for a given tag.
     *
     * @param  string  $name
     * @return string
     */
    public function tagId($name)
    {
        return self::TAG_PREFIX . $name;
    }

    /**
     * {@inheritDoc}
     */
    public function tagIds()
    {
        return array_map([$this, 'tagId'], $this->names);
    }

    public function tagNamePrefix()
    {
        return self::TAG_PREFIX . implode("\0", $this->names);
    }

    /**
     * Get the tag identifier key for a given tag.
     *
     * @param  string  $name
     * @return string
     */
    public function tagKey($name)
    {
        return self::TAG_PREFIX . $name;
    }

    /**
     * {@inheritDoc}
     */
    public function entries()
    {
        if (!isset($this->entries)) {
            $this->setEntries();
        }

        return $this->entries;
    }

    /**
     * Add a reference entry to the tag set's underlying sorted set.
     *
     * @param  string  $key
     * @param  int|null  $ttl
     * @param  string  $updateWhen
     * @return void
     */
    public function addEntry(string $key, ?int $ttl = null, $updateWhen = null)
    {
        if (is_null($ttl)) {
            $ttl = Cache::DEFAULT_CACHE_TTL;
        }

        $ttl = Carbon::now()->addSeconds($ttl)->getTimestamp();

        foreach ($this->tagIds() as $tagKey) {
            if ($updateWhen) {
                $this->store->connection()->zadd($this->store->getPrefix() . $tagKey, $updateWhen, $ttl, $key);
            } else {
                $this->store->connection()->zadd($this->store->getPrefix() . $tagKey, $ttl, $key);
            }
        }

        $this->setEntries();
    }

    #region Helpers
    /**
     * Get all possible namespaces for the tagset being used
     */
    protected function getNamespaces(): array
    {
        $result = $this->tagIds();

        //Build all possible permutations to scan.
        foreach ($this->getPermutations($result) as $permutation) {
            $result[] = implode("", $permutation);
        }

        return array_unique($result);
    }

    /**
     * Builds a tag permutation generator.
     */
    private function getPermutations(array $elements): Generator
    {
        if (count($elements) <= 1) {
            yield $elements;
        } else {
            foreach ($this->getPermutations(array_slice($elements, 1)) as $permutation) {
                foreach (range(0, count($elements) - 1) as $i) {
                    yield array_merge(
                        array_slice($permutation, 0, $i),
                        [$elements[0]],
                        array_slice($permutation, $i)
                    );
                }
            }
        }
    }

    /**
     * Set the current tagset entries collection
     */
    private function setEntries()
    {
        /** @disregard P1013 */
        $connection = $this->store->connection();

        $defaultCursorValue = match (true) {
            $connection instanceof PhpRedisConnection && version_compare(phpversion('redis'), '6.1.0', '>=') => null,
            default => '0',
        };

        $this->entries = LazyCollection::make(function () use ($connection, $defaultCursorValue) {
            foreach ($this->getNamespaces() as $tagKey) {
                $cursor = $defaultCursorValue;

                do {
                    [$cursor, $entries] = $connection->zscan(
                        $this->store->getPrefix() . $tagKey,
                        $cursor,
                        ['match' =>  "*" . self::KEY_PREFIX . "*", 'count' => 1000]
                    );

                    if (! is_array($entries)) {
                        break;
                    }

                    $entries = array_unique(array_keys($entries));

                    if (count($entries) === 0) {
                        continue;
                    }

                    foreach ($entries as $entry) {
                        yield $entry;
                    }
                } while (((string) $cursor) !== $defaultCursorValue);
            }
        });
    }
    #endregion
}

<?php

namespace App\Repositories;

use Cache;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Redis;

class CacheRepository
{

    /**
     * The default Cache ttl in minutes. This defaults to the config setting,
     * but can be set by the extending classes.
     *
     * @var int
     */
    private $_ttl;

    /**
     * The max we will let someone cache a key. This is read from the config.
     *
     * @var int
     */
    private $_ttlMax;

    /**
     * All the the cache keys to be busted for an object. If you are storing
     * more than these defaults, you need to overwrite this property in the
     * individual repository.
     *
     * @var arr
     */
    protected $cacheKeys = [
        '{{id}}',
        '{{id}}.data',
    ];

    /**
     * Construct the class.
     *
     * @return void
     */
    public function __construct()
    {
        $this->_ttlMax = config('cache.ttl-max');
        $this->setTtl(config('cache.ttl'));
    }

    /**
     * Set the TTL of the cache we are setting.
     *
     * @param   int $ttl
     * @return void
     */
    public function setTtl($ttl = 60)
    {
        $ttl = (int) $ttl;
        $this->_ttl = min($ttl, $this->_ttlMax);
    }

    /**
     * Get the prefix to a key based on the calling class.
     *
     * @param   mixed   $resource
     * @return  str
     */
    private function getKeyPrefix($resource = null)
    {
        if ($resource && $resource instanceof Model) {
            $prefix = $resource->getTable();
        } else {
            $prefix = class_basename($this);
            $prefix = str_replace('Repository', '', $prefix);
            $prefix = strtolower(snake_case($prefix));

            switch ($prefix) {
                // TODO: add special cases where str_plural would botch the prefix
                default:
                    $prefix = str_plural($prefix);
                    break;
            }
        }

        return $prefix;
    }

    /**
     * Get a cache key with the appropriate key prefix.
     *
     * @param   str $key
     * @return  str
     */
    private function prepCacheKey($key)
    {
        // all we do now is add a prefix
        $prefix = $this->getKeyPrefix($key);

        return $this->prefixKey($prefix, $key);
    }

    /**
     * Appropriately prefix the key.
     *
     * @param   str $prefix
     * @param   str $key
     * @return  str
     */
    private function prefixKey($prefix, $key)
    {
        // default to whatever we get
        $out = $key;

        // only add the prefix if it is not already there
        if ($prefix && strpos($key, $prefix) !== 0) {
            $out = $prefix . '.' . $key;
        }

        return $out;
    }

    /**
     * Store an object in the cache.
     *
     * @param   str     $key
     * @param   mixed   $val
     * @param   int     $ttl
     * @return  bool
     */
    public function put($key = null, $val = null, $ttl = null)
    {
        if (!$key || !$val) {
            return false;
        }

        // allow this TTL to be used as a one-off that does not set the property
        // on the class, but still does not exceed the max ttl
        if (!$ttl) {
            $ttl = $this->_ttl;
        } else {
            $ttl = min((int) $ttl, $this->_ttlMax);
        }

        return Cache::put($key, $val, $ttl);
    }

    /**
     * Alias for put().
     */
    public function set($key = null, $val = null, $ttl = null)
    {
        return $this->put($key, $val, $ttl);
    }


    /**
     * Try to find an object in the cache and remember it if we don't have it.
     *
     * We automatically prefix the key with the assumed model based on the
     * repository making the call.
     *
     * EXAMPLE: UserRepository makes $this->get(1234, func...); request, we
     * would drop "Repository" from the class name then lowercase and singularize
     * the "User" assuming that is the model.
     * The key then becomes: user.1234
     *
     * @param   mixed   $key
     * @return  mixed
     */
    public function remember($key, Closure $callback)
    {
        // create the model prefix to the ID
        $key = $this->prepCacheKey($key);

        return Cache::remember($key, $this->_ttl, $callback);
    }

    /**
     * Forget the cache for specific key.
     *
     * @param   str     $key
     * @return  void
     */
    public function forget($key)
    {
        $key = $this->prepCacheKey($key);

        Cache::forget($key);
    }

    /**
     * Bust the cache for a resource.
     *
     * @param   mixed   $resource
     * @return  void
     */
    public function bustCache($resource)
    {
        // not a model, try to get that shit
        if ( (!$resource instanceof Model) ) {
            $row = null;

            // try to find the resource by id and then slug if we can
            if (is_numeric($resource) && method_exists($this, 'find')) {
                $row = $this->find($resource);
            } else if (is_string($resource) && method_exists($this, 'findBySlug')) {
                $row = $this->findBySlug($resource);
            }

            $resource = $row;
        }

        // what the hell are we supposed to bust??
        if (!$resource) {
            if (config('app.debug')) {
                \Log::error('Error busting cache. Resource not found');
            }
        }

        // get all the cache keys for this bad boy
        $keys = $this->getAllCacheKeys($resource);
        foreach ($keys as $k => $key) {
            $this->forget($key);
        }
    }

    /**
     * Get the all the cache keys for a resource.
     *
     * @param   mixed   $resource
     * @return  arr
     */
    protected function getAllCacheKeys($resource)
    {
        $prefix = $this->getKeyPrefix($resource);

        $cacheKeys = [];

        // store all the values to be replaced in the key
        $replacements = [];

        // loop through all the default cache keys and get all the placeholders
        // and the values to be inserted
        foreach ($this->cacheKeys as $k => $cacheKey) {
            $cacheKeys[$k] = $cacheKey;

            // check for replacement strings to have the object properties
            // inserted into the key
            if (preg_match_all('/\{\{(?:rel:\w+\.\w+|\w+)\}\}/', $cacheKey, $matches)) {

                // go through all the matches and get all the replacement values
                foreach ($matches[0] as $km => $match) {

                    // pull the holder
                    $holder = str_replace(['{{', '}}'], '', $match);

                    // check if we are looking for a relationship
                    // NOTE: this better fucking match a relationship method on
                    // the original resource
                    //
                    // Example: {{id}}.organizations.{{rel:organizations.id}}.data
                    // $user->organizations() exists

                    if (preg_match('/^rel:/', $holder)) {

                        if (!isset($replacements[$match])) {
                            $replacements[$match] = [];
                        }

                        // break up the place holder into the relationship and
                        // it's respective field to be filled
                        $relParts = explode('.', str_replace('rel:', '', $holder));
                        $rel = $relParts[0];
                        $field = $relParts[1];

                        if (method_exists($resource, $rel)) {

                            // loop through all the relationships and generate the cache key
                            $resource->$rel()
                                ->chunk(10, function($relations) use ($field, $match, &$replacements) {
                                    foreach ($relations as $ri => $relation) {
                                        if (isset($relation->$field)) {
                                            $replacements[$match][] = $relation->$field;
                                        }
                                    }
                                });
                        }

                        // keep the replacements unique
                        $replacements[$match] = array_unique($replacements[$match]);

                    } else {
                        $field = $holder;

                        // only set the key if the property exists
                        if (isset($resource->$field)) {
                            $cacheKeys[$k] = $this->setCachePlaceholder($cacheKeys[$k], $match, $resource->$field);
                        }
                    }
                }
            }
        }

        // TODO: figure out multi-dimensional / multi-relationships in single key

        // loop through all our cache keys
        foreach ($cacheKeys as $k => $cacheKey) {

            // loop through all of our relational matches to create new keys
            // for each
            foreach ($replacements as $match => $values) {
                if (strpos($cacheKey, $match) !== false) {
                    foreach ($values as $vk => $value) {
                        $cacheKeys[] = $this->setCachePlaceholder($cacheKey, $match, $value);
                    }

                    unset($cacheKeys[$k]);
                }
            }

        }

        return $cacheKeys;
    }

    private function setCachePlaceholder($key, $holder, $value)
    {
        $key = str_replace($holder, $this->slug($value), $key);
        return $key;
    }

    /**
     * Set a standard slug for caching purposes.
     *
     * @param   str $value
     * @return  str
     */
    public function slug($value)
    {
        return str_slug($value, '-');
    }
}

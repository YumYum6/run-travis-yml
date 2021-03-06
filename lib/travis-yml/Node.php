<?php

/*
 * run-travis-yml
 */

namespace Ktomk\TravisYml;

/**
 * Anything data-handling for nodes in the .travis.yaml file
 *
 * generics in <https://config.travis-ci.com/>
 */
class Node
{
    /**
     * normalize a sequence with at least one element
     * or null and empty array as empty sequence
     *
     * @param $sequence
     * @return array sequence
     */
    public static function normalizeSequence($sequence)
    {
        if (is_array($sequence) && self::arrayIsSequence($sequence)) {
            return $sequence;
        }

        return in_array($sequence, array(null, array()), true) ? array() : array($sequence);
    }

    /**
     * normalize a map node
     *
     * @param mixed $map
     * @param null|string $defaultPrefix
     * @return array
     */
    public static function normalizeMap($map, $defaultPrefix = null)
    {
        if (self::isMap($map)) {
            return $map;
        }
        if (null === $defaultPrefix) {
            return array();
        }
        return array($defaultPrefix => self::normalizeSequence($map));
    }

    /**
     * normalize a map (extended for parsing)
     *
     * undefined behaviour: without a default prefix key, normalizing a map
     * that ain't one, especially not even an array (e.g. returning
     * an empty array which is not a map)
     *
     * @param $map
     * @param null|string $prefixKey a map in travis-yml can (must not) have one default prefix key
     * @param array|null $defaultKeys to check to propagate or merge if no default is set
     * @return array|array[]
     */
    public static function normalizeMapEx($map, $prefixKey = null, array $defaultKeys = array())
    {
        // input is a map
        if (is_array($map) && self::arrayIsMap($map)) {
            if (null === $prefixKey) {
                return $map;
            }
            $result = $map + array($prefixKey => array());
            $merge = array_key_exists($prefixKey, $map);
            if (!$merge && $defaultKeys) {
                // mode with default keys: if the prefix key is not set it must not mean
                // that the whole map is to be on the prefix key. therefore comparing
                // against default keys to re-enable the merge if any of them
                // are set (but keep the prefix key out as it normally *must* merge)
                $keyMap = array_flip($defaultKeys);
                unset($keyMap[$prefixKey]);
                $merge = (array() !== array_intersect_key($keyMap, $map));
            }
            if (false === $merge) {
                $result = array();
                $result[$prefixKey] = $map;
            }
            $result[$prefixKey] = self::normalizeSequence($result[$prefixKey]);
            return $result;
        }

        // input is not a map
        if (null !== $prefixKey) {
            return array($prefixKey => self::normalizeSequence($map));
        }
        return $map; // fall-through
    }

    /**
     * NOTE: weak with secure: values
     *
     * @param mixed $value
     * @param null $defaultPrefixKey
     * @return string|null
     */
    public static function normalizeString($value, $defaultPrefixKey = null)
    {
        $buffer = $value;
        // normalize map to default prefix, if no default prefix, this is NULL then
        if (is_array($buffer) && self::arrayIsMap($buffer)) {
            $buffer = self::item($buffer, $defaultPrefixKey);
        }
        // any sequence will have it's first
        if (is_array($buffer) && self::arrayIsSequence($value)) {
            $buffer = self::item($buffer, 0);
        }

        return null === $buffer ? $buffer : (string) $buffer;
    }

    public static function isMap($node)
    {
        return is_array($node) && self::arrayIsMap($node);
    }

    public static function arrayIsMap(array $array)
    {
        foreach ($array as $key => $value) {
            if (!is_string($key)) {
                return false;
            }
        }
        return (bool)$array;
    }

    public static function arrayIsSequence(array $array)
    {
        $count = 0;
        foreach ($array as $index => $value) {
            if ($index !== $count) {
                return false;
            }
            $count++;
        }
        return (bool)$count;
    }

    /**
     * if a map filter out aliases
     *
     * if an alias clashes with the original, the alias wins only if it
     * comes later than the original.
     *
     * fall-through for anything else than a map.
     *
     * @param mixed $map
     * @param array $aliases
     *
     * @return mixed
     */
    public static function filterAliasMap($map, $aliases = array())
    {
        if (!is_array($map) || !$aliases) {
            return $map;
        }
        $keys = array_flip(array_keys($map));
        foreach ($aliases as $alias => $original) {
            if (!isset($keys[$alias])) {
                continue;
            }
            // alias overrides original (or creates it)
            if (!isset($keys[$original]) || $keys[$original] <= $keys[$alias]) {
                $map[$original] = &$map[$alias];
            }
            unset($map[$alias]);
        }
        return $map;
    }

    /**
     * merge two maps while resolving alias keys already
     *
     * @param array $into
     * @param mixed $what
     * @param array $alias
     * @return array
     */
    public static function mergeMapWithAlias(array $into, $what, $alias = array())
    {
        if (!is_array($what)) {
            return $into;
        }

        $alias && $what = self::filterAliasMap($what, $alias);
        foreach ($what as $key => $value) {
            $into[$key] = $value;
        }

        return $into;
    }

    /**
     * guarded array traversal
     *
     * @param array $from
     * @param string|int|string[]|int[]|array $keyOrKeys
     * @param null $default
     * @return array|mixed|null
     */
    public static function item(array $from, $keyOrKeys, $default = null)
    {
        $keys = is_array($keyOrKeys) ? $keyOrKeys : array($keyOrKeys);
        $top = $from;
        foreach($keys as $key) {
            if (!isset($top[$key])) {
                return $default;
            }
            $top = $top[$key];
        }
        return $top;
    }

    /**
     * conditional append one sequence to another from one map to the other
     *
     * if $into[$key] is not set (e.g. NULL) will be turned into an empty sequence
     *
     * @param array $into
     * @param $key
     * @param array $from
     * @return array
     */
    public static function append(array $into, $key, array $from)
    {
        if (!isset($into[$key])) {
            $into[$key] = array();
        }

        if (array_key_exists($key, $from) && is_array($from[$key]) && is_array($into[$key])) {
            foreach ($from[$key] as $item) {
                $into[$key][] = $item;
            }
        }

        return $into;
    }

    /**
     * conditional copy of one or more keys
     *
     * @param array $into
     * @param string|int|string[]|array $keyOrKeys one or more keys to copy
     * @param array $from
     * @return array
     */
    public static function copy(array $into, $keyOrKeys, array $from)
    {
        $keys = is_array($keyOrKeys) ? $keyOrKeys : array($keyOrKeys);
        foreach ($keys as $key) {
            if (!array_key_exists($key, $from)) {
                continue;
            }
            $into[$key] = $from[$key];
        }

        return $into;
    }

    public static function copyNormalizeSequence(array $into, $keyOrKeys, array $from)
    {
        $keys = is_array($keyOrKeys) ? $keyOrKeys : array($keyOrKeys);
        foreach ($keys as $key) {
            if (!array_key_exists($key, $from)) {
                continue;
            }
            $into[$key] = self::normalizeSequence($from[$key]);
        }

        return $into;
    }

    /**
     * remove elements from array by key(s)
     *
     * @param array $from
     * @param int|string|string[]|int[]|array $keyOrKeys
     * @return array
     */
    public static function remove(array $from, $keyOrKeys)
    {
        $keys = is_array($keyOrKeys) ? $keyOrKeys : array($keyOrKeys);
        foreach ($keys as $key) {
            unset($from[$key]);
        }
        return $from;
    }

    /**
     * filter a sequence of strings against a list of allowed strings
     *
     * similar to array_values(array_intersect($sequence, $allowed))
     *
     * @param array|string[] $sequence
     * @param array|string[] $allowed
     * @return array|string[]
     */
    public static function filterSequence(array $sequence, array $allowed)
    {
        $allowed = array_flip($allowed);
        $filtered = array();
        foreach ($sequence as $item) {
            isset($allowed[$item]) && $filtered[] = $item;
        }
        return $filtered;
    }

    public static function filterEnum($string, array $allowed)
    {
        return in_array($string, $allowed, true) ? $string : null;
    }
}

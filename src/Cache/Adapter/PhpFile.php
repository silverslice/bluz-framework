<?php
/**
 * Bluz Framework Component
 *
 * @copyright Bluz PHP Team
 * @link https://github.com/bluzphp/framework
 */

/**
 * @namespace
 */
namespace Bluz\Cache\Adapter;

use Bluz\Cache\Cache;
use Bluz\Cache\CacheException;

/**
 * Adapter that caches data into php array.
 * It can cache data that support var_export.
 * This adapter very fast and cacheable by opcode cachers but it have some limitations related to var_export.
 * It's best to use for scalar data caching
 *
 * @package Bluz\Cache\Adapter
 * @link    http://php.net/manual/en/function.var-export.php
 * @link    http://php.net/manual/en/language.oop5.magic.php#object.set-state
 * @author  murzik
 */
class PhpFile extends FileBase
{
    /**
     * Cache data
     * @var array
     */
    protected $data = array();

    /**
     * {@inheritdoc}
     *
     * @param string $id
     * @return bool
     */
    protected function doContains($id)
    {
        $filename = $this->getFilename($id);

        if (!is_file($filename)) {
            return false;
        }

        $cacheEntry = include $filename;

        return $cacheEntry['ttl'] === Cache::TTL_NO_EXPIRY || $cacheEntry['ttl'] > time();
    }

    /**
     * {@inheritdoc}
     *
     * @param string $id
     * @return bool|mixed
     */
    protected function doGet($id)
    {
        $filename = $this->getFilename($id);

        if (!is_file($filename)) {
            return false;
        }

        if (defined('HHVM_VERSION')) {
            // XXX: workaround for https://github.com/facebook/hhvm/issues/1447
            $cacheEntry = eval(str_replace('<?php', '', file_get_contents($filename)));
        } else {
            $cacheEntry = include $filename;
        }

        if ($cacheEntry['ttl'] !== Cache::TTL_NO_EXPIRY && $cacheEntry['ttl'] < time()) {
            return false;
        }

        return $cacheEntry['data'];
    }

    /**
     * {@inheritdoc}
     *
     * @param string $id
     * @param mixed $data
     * @param int $ttl
     * @return integer The number of bytes that were written to the file, or false on failure.
     * @throws CacheException
     */
    protected function doSet($id, $data, $ttl = Cache::TTL_NO_EXPIRY)
    {
        if ($ttl > 0) {
            $ttl = time() + $ttl;
        }

        // if we have an array containing objects - we will have a problem.
        if (is_object($data) && !method_exists($data, '__set_state')) {
            throw new CacheException(
                "Invalid argument given, PhpFileAdapter only allows objects that implement __set_state() " .
                "and fully support var_export()."
            );
        }

        $fileName = $this->getFilename($id);
        $filePath = pathinfo($fileName, PATHINFO_DIRNAME);

        if (!is_dir($filePath)) {
            mkdir($filePath, 0777, true);
        }

        $cacheEntry = array(
            'ttl' => $ttl,
            'data' => $data
        );

        $cacheEntry = var_export($cacheEntry, true);
        $code = sprintf('<?php return %s;', $cacheEntry);

        return file_put_contents($fileName, $code);
    }

    /**
     * {@inheritdoc}
     *
     * @param string $id
     * @param mixed $data
     * @param int $ttl
     * @return bool|int
     * @throws CacheException
     */
    protected function doAdd($id, $data, $ttl = Cache::TTL_NO_EXPIRY)
    {
        if (!$this->doContains($id)) {
            return $this->doSet($id, $data, $ttl);
        } else {
            return false;
        }
    }
}

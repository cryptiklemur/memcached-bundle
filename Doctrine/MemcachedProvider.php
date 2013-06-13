<?php
/**
 * @author    Aaron Scherer <aequasi@gmail.com>
 * @date 2013
 * @license   http://www.apache.org/licenses/LICENSE-2.0.html Apache License, Version 2.0
 */
namespace Aequasi\Bundle\MemcachedBundle\Doctrine;

use \Aequasi\Bundle\MemcachedBundle\Cache\Memcached;

use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\CacheProvider;

/**
 * Memcached cache provider (with prefix support).
 *
 * Based on: Doctrine/Common/Cache/MemcacheCache.php
 */
class MemcachedProvider extends CacheProvider
{

	/**
	 * @var Memcached
	 */
	private $memcached;
	
	private function getNamespacedId($id)
	{
		return $this->memcached->getNamespacedId( $id );
	}

	/**
	 * Gets the memcached instance used by the cache.
	 *
	 * @return Memcached
	 */
	public function getMemcached()
	{
		return $this->memcached;
	}

	/**
	 * Sets the memcached instance to use.
	 *
	 * @param Memcached $memcached
	 */
	public function setMemcached( Memcached $memcached )
	{
		$this->memcached = $memcached;
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doFetch( $id )
	{
		return $this->memcached->get( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doContains( $id )
	{
		return (bool)$this->memcached->get( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doSave( $id, $data, $lifeTime = 0 )
	{
		if ( $lifeTime > 30 * 24 * 3600 ) {
			$lifeTime = time() + $lifeTime;
		}

		return $this->memcached->set( $id, $data, (int)$lifeTime );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doDelete( $id )
	{
		return $this->memcached->delete( $id );
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doFlush()
	{
		return $this->memcached->flush();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function doGetStats()
	{
		$servers = $this->memcached->getStats();
		$data = array(
			Cache::STATS_HITS              => 0,
			Cache::STATS_MISSES            => 0,
			Cache::STATS_UPTIME            => 0,
			Cache::STATS_MEMORY_USAGE      => 0,
			Cache::STATS_MEMORY_AVAILIABLE => 0,
		);

		foreach( $servers as $server ) {
			$stats = $this->getServerStats( $server );
			$data[ Cache::STATS_HITS ] += $stats[ Cache::STATS_HITS ];
			$data[ Cache::STATS_MISSES ] += $stats[ Cache::STATS_MISSES ];
			$data[ Cache::STATS_MEMORY_USAGE ] += $stats[ Cache::STATS_MEMORY_USAGE ];
			$data[ Cache::STATS_MEMORY_AVAILIABLE ] += $stats[ Cache::STATS_MEMORY_AVAILIABLE ];
			if( $data[ Cache::STATS_UPTIME ] < $stats[ Cache::STATS_UPTIME ] )
				$data[ Cache::STATS_UPTIME ] = $stats[ Cache::STATS_UPTIME ];
		}
		return $data;
	}

	protected function getServerStats( $stats )
	{
		return array(
			Cache::STATS_HITS              => $stats[ 'get_hits' ],
			Cache::STATS_MISSES            => $stats[ 'get_misses' ],
			Cache::STATS_UPTIME            => $stats[ 'uptime' ],
			Cache::STATS_MEMORY_USAGE      => $stats[ 'bytes' ],
			Cache::STATS_MEMORY_AVAILIABLE => $stats[ 'limit_maxbytes' ],
		);
	}
}

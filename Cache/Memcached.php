<?php
/**
 * @author    Aaron Scherer <aequasi@gmail.com>
 * @date 2013
 * @license   http://www.apache.org/licenses/LICENSE-2.0.html Apache License, Version 2.0
 */
namespace Aequasi\Bundle\MemcachedBundle\Cache;

use Doctrine\DBAL\Connection;
use Doctrine\Bundle\DoctrineBundle\Registry;

/**
 * Class to encapsulate PHP Memcached object
 */
class Memcached
{
	const NAMESPACE_CACHEKEY = 'NamespaceCacheKey[%s]';

	/**
	 * @var string The namespace to prefix all cache ids with
	 */
	protected $namespace = '';

	/**
	 * @var string The namespace version
	 */
	protected $namespaceVersion;

	/**
	 * 60 Second Cache
	 */
	const SIXTY_SECOND = 60;

	/**
	 * 30 Minute Cache
	 */
	const THIRTY_MINUTE = 1800;

	/**
	 * 1 Hour Cache
	 */
	const ONE_HOUR = 3600;

	/**
	 * 6 Hour Cache
	 */
	const SIX_HOUR = 21600;

	/**
	 * Infinite Cache
	 */
	const NO_EXPIRE = 0;

	/**
	 * No Cache
	 */
	const NO_CACHE = -1;

	/**
	 * @var bool
	 */
	protected $enabled;

	/**
	 * @var bool
	 */
	protected $initialize;

	/**
	 * @var bool
	 */
	protected $keyMap = false;

	/**
	 * @var Connection
	 */
	protected $keyMapConnection = null;

	/**
	 * @var \Memcached
	 */
	protected $memcached;

	/**
	 * @var bool
	 */
	protected $persistent = false;

	/**
	 * @var string
	 */
	protected $prefix = null;

	/**
	 * @var bool
	 */
	protected $debug = false;

	/**
	 * @var array Store of keymap info if inserted to the database
	 */
	protected $keyMapInfo = array();

	/**
	 * Constructor instantiates and stores Memcached object
	 *
	 * @param bool $enabled      Are we caching?
	 * @param bool $logging      Are we logging?
	 * @param null $persistentId Are we persisting?
	 */
	public function __construct( $enabled, $debug = false, $persistentId = null )
	{
		$this->enabled = $enabled;
		$this->calls   = array();
		$this->debug   = $debug;
		if ( $persistentId ) {
			$this->memcached  = new \Memcached( $persistentId );
			$this->initialize = count( $this->getServerList() ) == 0;
			$this->persistent = true;
		} else {
			$this->memcached  = new \Memcached();
			$this->initialize = true;
		}
	}

	/**
	 * Adds servers to the pool. If persistent, check count of current server list 
	 *
	 * @param array $serverList List of servers
	 *
	 * @return bool
	 */	
	public function addServers( array $serverList )
	{
		if( $this->persistent && sizeof( $this->getServerList() ) > 0 )
			return false;

		return $this->processRequest( 'addServers', array( $serverList ) );
	}

	/**
	 * Sets up Key Mapping, if enabled
	 *
	 * Creates the necessary tables, if they arent there, and updates the service
	 *
	 * @param array            $configs
	 * @param Registry         $doctrine
	 *
	 * @throws \Exception
	 */
	public function setupKeyMap( array $configs, Registry $doctrine )
	{
		if ( $configs[ 'enabled' ] ) {

			// Make sure the connection isn't empty
			if ( $configs[ 'connection' ] === '' ) {
				throw new \Exception( "Please specify a `connection` for the keyMap setting under memcached. " );
			}

			// Grab the connection from doctrine
			/** @var \Doctrine\DBAL\Connection $connection */
			$connection = $doctrine->getConnection( $configs[ 'connection' ] );

			// Fetch the memcached service, set key mapping to enabled, and set the connection
			$this->setKeyMapEnabled( true )
				->setKeyMapConnection( $connection );
		}
	}

	/**
	 * Sets whether or not we are mapping keys
	 *
	 * @param bool $keyMap
	 *
	 * @return $this
	 */
	public function setKeyMapEnabled( $keyMap )
	{
		$this->keyMap = $keyMap;

		return $this;
	}

	/**
	 * @param     $key
	 * @param     $payload
	 * @param int $time
	 *
	 * @return mixed
	 */
	public function cache( $key, $payload, $time = self::NO_EXPIRE )
	{
		if ( $this->isEnabled() && $time !== self::NO_CACHE ) {
			$result = $this->get( $key );
			if ( $result !== false ) {
				return $result;
			}
			$result = $this->getDataFromPayload( $payload );
			$this->set( $key, $result, $time );
		} else {
			$result = $this->getDataFromPayload( $payload );
		}

		return $result;
	}

	/**
	 * Gets whether or not mapping keys is enabled
	 *
	 * @return boolean
	 */
	public function isKeyMapEnabled()
	{
		return $this->keyMap;
	}

	/**
	 * Gets the Key Mapping Doctrine Connection
	 *
	 * @return Connection|null
	 */
	public function getKeyMapConnection()
	{
		return $this->keyMapConnection;
	}

	/**
	 * Sets the Key Mapping Doctrine Connection
	 *
	 * @param Connection $keyMapConnection
	 *
	 * @return $this
	 */
	public function setKeyMapConnection( Connection $keyMapConnection )
	{
		$this->keyMapConnection = $keyMapConnection;

		return $this;
	}

	/**
	 * @param $enabled
	 *
	 * @return $this
	 */
	public function setEnabled( $enabled )
	{
		$this->enabled = $enabled;

		return $this;
	}

	/**
	 * @return boolean
	 */
	public function isEnabled()
	{
		return $this->enabled;
	}

	/**
	 * @return bool
	 */
	public function hasError()
	{
		return $this->memcached->getResultCode() !== Memcached::RES_SUCCESS;
	}

	/**
	 * @return string
	 */
	public function getError()
	{
		return $this->memcached->getResultMessage();
	}

	/**
	 * @param $name
	 * @param $arguments
	 *
	 * @return mixed
	 */
	function __call( $name, $arguments )
	{
		return $this->processRequest( $name, $arguments );
	}

	/**
	 * @param $name
	 * @param $arguments
	 *
	 * @return mixed
	 */
	protected function processRequest( $name, $arguments )
	{
		$useId = array( 
			'add', 'delete', 'deleteByKey', 'deleteMulti', 'deleteMultiByKey',
			'increment', 'prepend', 'prependByKey', 'replace', 'replaceByKey',
			'touch', 'touchByKey', 'addByKey', 'append', 'appendByKey',
			'decrement', 'get', 'getByKey', 'getDelayed', 'getDelayedByKey',
			'getMulti', 'getMultiByKey', 'set', 'setByKey', 'setMulti',
			'setMultiByKey'
		);

		if( in_array( $name, $useId ) ) {
			$arguments[ 0 ] = $this->getNamespacedId( $arguments[ 0 ] );
		}
		
		$result = call_user_func_array( array( $this->memcached, $name ), $arguments );

		if( in_array( $name, array( 'add', 'set' ) ) ) {
			$this->addToKeyMap( $arguments[ 0 ], $arguments[ 1 ], $arguments[ 2 ] );
		}
		if( $name == 'delete' ) {
			$this->deleteFromKeyMap( $arguments[ 0 ] );
		}
		if( $name == 'flush' ) {
			$this->truncateKeyMap( );
		}

		return $result;
	}

	/**
	 * Adds the given key to the key map
	 *
	 * @param $id
	 * @param $data
	 * @param $lifeTime
	 *
	 * @return bool|int
	 */
	protected function addToKeyMap( $id, $data, $lifeTime )
	{
		if ( !$this->isKeyMapEnabled() ) {
			return false;
		}

		$data = array(
				'cache_key'   => $id,
				'memory_size' => $this->getPayloadSize( $data ),
				'lifeTime'    => $lifeTime,
				'expiration'  => date( 'Y-m-d H:i:s', strtotime( "now +{$lifeTime} seconds" ) ),
				'insert_date' => date( 'Y-m-d H:i:s' )
			     );
		if ( $lifeTime === null ) {
			unset( $data[ 'lifeTime' ], $data[ 'expiration' ] );
		}

		if( isset( $this->keyMapInfo[ $id ] ) ) {
			$data = array_merge( $data, $this->keyMapInfo[ $id ] );
		}

		try {
			return $this->getKeyMapConnection()->insert( 'memcached_key_map', $data );
		} catch( \Exception $e ) {
			error_log( "Could not write to `memcached_key_map`." );
		}
	}

	public function setKeyMapInfo( $key, $category = null, $description = null )
	{
		if ( !$this->isKeyMapEnabled() ) {
			return false;
		}

		$data = array();
		if( null !== $category ) {
			$data[ 'category' ] = $category;
		}

		if( null !== $description ) {
			$data[ 'description' ] = $description;
		}

		$this->keyMapInfo[ $key ] = $data;

		return true;
	}

	/**
	 * @param $id
	 *
	 * @return bool|int
	 */
	protected function deleteFromKeyMap( $id )
	{
		if ( !$this->isKeyMapEnabled() ) {
			return false;
		}

		return $this->getKeyMapConnection()->delete( 'memcached_key_map', array( 'cache_key' => $id ) );
	}

	/**
	 * @return bool|int
	 */
	protected function truncateKeyMap( )
	{
		if ( !$this->isKeyMapEnabled() ) {
			return false;
		}

		return $this->getKeyMapConnection()->executeQuery( 'TRUNCATE memcached_key_map' );
	}

	/**
	 * @param \Closure|callable|mixed $payload
	 *
	 * @return mixed
	 */
	protected function getDataFromPayload( $payload )
	{
		/** @var $payload \Closure|callable|mixed */
		if ( is_callable( $payload ) ) {
			if ( is_object( $payload ) && get_class( $payload ) == 'Closure' ) {
				return $payload();
			}

			return call_user_func( $payload );
		}

		return $payload;
	}

	/**
	 * Gets the memory size of the given variable
	 *
	 * @param $data
	 *
	 * @return int
	 */
	protected function getPayloadSize( $data )
	{
		$start_memory = memory_get_usage();
		$data         = unserialize( serialize( $data ) );

		return memory_get_usage() - $start_memory - PHP_INT_SIZE * 8;
	}

	/**
	 * Sets the prefix for this client
	 *
	 * @param string $prefix Prefix to use for this client
	 *
	 * @return LoggingMemcached
	 */
	public function setPrefix( $prefix )
	{
		$this->prefix = $prefix;

		return $this;
	}

	/**
	 * @return string Returns the prefix
	 */
	public function getPrefix( )
	{
		return $this->prefix;
	}

	/**
	 * @return bool Returns whether or not $this->prefix is empty()
	 */
	public function hasPrefix()
	{
		return !empty( $this->prefix );
	}

	/**
	 * Set the namespace to prefix all cache ids with.
	 *
	 * @param string $namespace
	 * @return void
	 */
	public function setNamespace($namespace)
	{
		$this->namespace = (string) $namespace;
	}

	/**
	 * Retrieve the namespace that prefixes all cache ids.
	 *
	 * @return string
	 */
	public function getNamespace()
	{
		return $this->namespace;
	}

	/**
	 * Prefix the passed id with the configured namespace value
	 *
	 * @param string $id  The id to namespace
	 * @return string $id The namespaced id
	 */
	private function getNamespacedId($id)
	{
		$namespaceVersion  = $this->getNamespaceVersion();

		return sprintf('%s[%s][%s]', $this->namespace, $id, $namespaceVersion);
	}

	/**
	 * Namespace cache key
	 *
	 * @return string $namespaceCacheKey
	 */
	private function getNamespaceCacheKey()
	{
		return sprintf(self::DOCTRINE_NAMESPACE_CACHEKEY, $this->namespace);
	}

	/**
	 * Namespace version
	 *
	 * @return string $namespaceVersion
	 */
	private function getNamespaceVersion()
	{
		if (null !== $this->namespaceVersion) {
			return $this->namespaceVersion;
		}

		$namespaceCacheKey = $this->getNamespaceCacheKey();
		$namespaceVersion = $this->doFetch($namespaceCacheKey);

		if (false === $namespaceVersion) {
			$namespaceVersion = 1;

			$this->doSave($namespaceCacheKey, $namespaceVersion);
		}

		$this->namespaceVersion = $namespaceVersion;

		return $this->namespaceVersion;
	}
}

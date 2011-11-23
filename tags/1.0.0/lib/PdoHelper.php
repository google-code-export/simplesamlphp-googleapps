<?php

/**
 * This class is used to store user information about provisioning.
 * Other Auth Filters check this database for things such as;
 * login delays, provisioning delays, and username changes.
 * By design, if no options are given it will try to create
 * its own sqlite database in the data directory.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package SimpleSAMLphp-GoogleApps
 * @version $Id$
 */
class sspmod_googleapps_PdoHelper extends sspmod_googleapps_BaseHelper {

	/**
	 * This is the database version number, only
	 * incremented when table changes are needed.
	 */
	const VERSION = 1;

	/**
	 * An array of class instances, one for each
	 * DSN. Used as a caching method to eliminate
	 * multiple instances for one DSN.
	 * 
	 * @var array
	 */
	protected static $instances = array();

	/**
	 * The number of seconds to delay the user from
	 * logging in. Typically for a newly provisioned
	 * account or a username change.
	 *
	 * @var int
	 */
	protected $delay;

	/**
	 * This is needed for un-serialization of the PDO instance.
	 * 
	 * @var string
	 */
	protected $dsn;

	/**
	 * This is needed for un-serialization of the PDO instance.
	 * 
	 * @var array
	 */
	protected $options;

	/**
	 * When an instance is created, the PDO connection
	 * instance is stored here for later use.
	 * 
	 * @var PDO
	 */
	protected $pdo;

	/**
	 * This is needed for un-serialization of the PDO instance.
	 * 
	 * @var string
	 */
	protected $password;

	/**
	 * Table name prefix used in all database queries.
	 * 
	 * @var string
	 */
	protected $prefix;

	/**
	 * This is needed for un-serialization of the PDO instance.
	 * 
	 * @var string
	 */
	protected $username;

	/**
	 * Basically an array of records in the users table,
	 * populated as requested (getUser). Used for caching
	 * methods to eliminate duplicate database queries.
	 * 
	 * @var array
	 */
	protected $users = array();

	/**
	 * Checks for the PDO extension and tries to make a
	 * connection to the DSN.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $dsn
	 * @param string $username
	 * @param string $password
	 * @param string $prefix
	 * @param int $delay
	 * @param array $options
	 */
	final protected function __construct($dsn, $username, $password, $prefix, $options, $delay) {

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Creating new PDO instance...'
		);

		// Make sure the PDO extension has been enabled
		if (!class_exists('PDO', FALSE)) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'The required PHP PDO extension is unavailable'
			);
		}

		// Make a connection to the database, set errors to be thrown
		$this->pdo = new PDO($dsn, $username, $password, $options);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// This comes from the SimpleSAML_Store_SQL class
		if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
			$this->pdo->exec('SET time_zone = "+00:00"');
		}

		// Remove trailing _ in prefix, defined in the SQL statements already
		if (substr($prefix, -1) == '_') {
			$prefix = substr($prefix, 0, -1);
		}

		// Store the configuration values
		$this->dsn = $dsn;
		$this->username = $username;
		$this->password = $password;
		$this->prefix = $prefix;
		$this->delay = $delay;

		// Create or update the tables
		$this->initTables();

		// Register this DSN instance
		self::$instances[$dsn] = $this;

		// Log debug info
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Created new PDO instance. ' .
			"DSN: $dsn Username: $username Prefix: $prefix Delay: $delay"
		);
	}

	/**
	 * Private to force singleton pattern.
	 * @return void
	 */
	final private function __clone() { throw new Exception('Cannot clone GoogleApps PdoHelper'); }

	/**
	 * Needed to unset the PDO instance when the SimpleSAML state is saved.
	 * When forwarding the user to the delay page, the state is saved but
	 * the PDO instance here is not serializable.
	 *
	 * @return array
	 */
	public function __sleep() {
		unset($this->pdo);
		return array_keys(get_object_vars($this));
	}

	/**
	 * With the above sleep method, when the state is restored we need to
	 * re-connect to the database and create a new PDO instance.
	 *
	 * @return void
	 */
	public function __wakeup() {

		// Make a connection to the database, set errors to be thrown
		$this->pdo = new PDO($this->dsn, $this->username, $this->password, $this->options);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

		// This comes from the SimpleSAML_Store_SQL class
		if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
			$this->pdo->exec('SET time_zone = "+00:00"');
		}
	}

	/**
	 * This is the singleton method used for getting an instance
	 * that is connected to a specific DSN. It will get the required
	 * configuration options in case a new connection needs to be created.
	 *
	 * @static
	 * @param SimpleSAML_Configuration $config
	 * @return sspmod_googleapps_PdoHelper
	 */
	public static function getInstance(SimpleSAML_Configuration $config) {

		// Log the request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Getting PDO connection...'
		);

		// Generate the default dns which should be used
		$default_dsn  = 'sqlite:';
		$default_dsn .= '%%DATADIR%%';
		$default_dsn .= 'googleapps_';
		$default_dsn .= $config->getString('apps.domain');
		$default_dsn .= '.sqlite';

		// Get all of the required config values
		$dsn = $config->getString('pdo.dsn', $default_dsn);
		$username = $config->getString('pdo.username', NULL);
		$password = $config->getString('pdo.username', NULL);
		$prefix = $config->getString('pdo.prefix', 'googleapps');
		$options = $config->getArray('pdo.options', array());
		$delay = $config->getInteger('provision.delay', 600);

		// Check if user requested the data directory
		if (stripos($dsn, '%%DATADIR%%')) {
			$data_dir = SimpleSAML_Configuration::getInstance()->getPathValue('datadir');
			$data_dir = str_replace('\\', '/', $data_dir);
			$dsn = str_ireplace('%%DATADIR%%', $data_dir, $dsn);
		}

		// Check for existing instance
		if (isset(self::$instances[$dsn])) {
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Found PDO connection in cache, returning. DSN: $dsn"
			);
			return self::$instances[$dsn];
		}

		// Make a new instance
		return new self($dsn, $username, $password, $prefix, $options, $delay);
	}

	/**
	 * This initializes the database tables. It checks for existing
	 * tables and the database version. If needed, it'll create the tables
	 * or make updates as necessary. Must be called when instance is created.
	 * 
	 * @throws SimpleSAML_Error_Exception
	 * @return
	 */
	protected function initTables() {

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Initializing PDO tables...'
		);

		// Try to get the database version, if it
		// fails then tables must need to be created
		try {
			$statement = $this->pdo->query('SELECT Version FROM ' . $this->prefix . '_version');
			$version = (int) $statement->fetchColumn();
		} catch (PDOException $e) {
			$this->pdo->exec('CREATE TABLE ' . $this->prefix . '_version (Version INTEGER NOT NULL)');
			$this->pdo->exec('CREATE TABLE ' . $this->prefix .
				'_users (UserId VARCHAR(64) NOT NULL UNIQUE, Username VARCHAR(64) NOT NULL, ' .
				'LastUpdated TIMESTAMP NOT NULL, DelayUntil TIMESTAMP NOT NULL, PRIMARY KEY (UserId))'
			);
			$this->pdo->exec('INSERT INTO ' . $this->prefix . '_version (Version) VALUES (' . self::VERSION . ')');
			SimpleSAML_Logger::notice(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'Created database tables for: ' . $this->dsn
			);
			return;
		}

		// Database version correct
		if ($version == self::VERSION) {
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Database version correct [$version] for " . $this->dsn
			);
			return;
		}

		// User must have moved DB to server w/ older module
		if ($version > self::VERSION) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'Database is newer than the module installed, please update the module code. ' . $this->dsn
			);
		}

		// Based on the current version of the database,
		// execute SQL statements to get it up to the current
		// version. This should continue through the switch {}
		// until updated to the latest version.
		switch ($version) {
			case 1:
				// SQL statements for CHANGES to the DB
				// This should get it up to version 2
				// Also Log an upgrade, notice message
			case 2:
				// SQL statements for CHANGES to the DB
				// This should get it up to version 3
				// Also Log an upgrade, notice message
			// Repeat as needed...
		}

		// SQL statement to update the db version
		$this->pdo->exec('UPDATE ' . $this->prefix . '_version SET Version = ' . self::VERSION);

		// Log debug info
		SimpleSAML_Logger::info(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Updated database tables for: ' . $this->dsn
		);
	}

	/**
	 * Query's the database for a specific user ID, caching
	 * and returning all the column values. Also converts the
	 * database timestamps into a PHP Unix timestamp. If no
	 * record is found it will return an empty array.
	 *
	 * @param string $userid
	 * @return array
	 */
	public function getUser($userid) {
		assert('is_string($userid) && $userid != ""');

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Attempting to get user record for: ' . $userid
		);

		// Check the cache for an existing record
		if (isset($this->users[$userid])) {
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'User record found in cache, returning. ' .
				self::var_export($this->users[$userid])
			);
			return $this->users[$userid];
		}

		try {

			// Query the database for the UserId
			$statement = $this->pdo->query(
				'SELECT * FROM ' . $this->prefix . '_users WHERE UserId = ' . $this->pdo->quote($userid)
			);

			// Get the column names => values
			$user = $statement->fetch(PDO::FETCH_ASSOC);

			// If no records found it will be false
			if (!$user) {
				$this->users[$userid] = array();
				SimpleSAML_Logger::debug(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'User record NOT found in database.'
				);
				return array();
			}

			// Convert database time format to unix timestamp
			$user['LastUpdated'] = strtotime($user['LastUpdated']);
			$user['DelayUntil'] = strtotime($user['DelayUntil']);

			// Register the user with cache
			$this->users[$userid] = $user;

			// Log debug info
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'User record found in database. ' .
				self::var_export($user)
			);

			// Return the finished product
			return $user;

		// For any errors, just return an empty array
		} catch (PDOException $e) {
			$this->users[$userid] = array();
			SimpleSAML_Logger::debug(self::getClassTitle(__FUNCTION__, __CLASS__) . $e->getMessage());
			return array();
		}
	}

	/**
	 * Updates the database and cached records for a specific
	 * user ID. Also checks for recent updates to eliminate
	 * duplicate updates made by multiple filters.
	 * 
	 * @param string $userid
	 * @param string $username
	 * @param bool $delay
	 * @return array
	 */
	public function setUser($userid, $username, $delay = FALSE) {
		assert('is_string($userid) && $userid != ""');
		assert('is_string($username) && $username != ""');

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting to set user record. UserID: $userid Username: $username Delay: " .
			($delay ? 'Yes' : 'No')
		);

		// Make timestamps
		$lastupdated = time();
		$delayuntil = ($delay ? $lastupdated + $this->delay : $lastupdated - 60);

		// Get the user data from the method, not member/property,
		// so that it checks the database, if needed.
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Checking for an existing record to update, or insert new record.'
		);
		$user = $this->getUser($userid);

		// Check if user already exists in the database
		if (!empty($user)) {

			// If the record has recently been updated
			// from other auth filters, and is about
			// about the same, then do not update
			if ($username == $user['Username'] &&
				($lastupdated - $user['LastUpdated']) <= 60 &&
				$delayuntil <= $user['DelayUntil']) {
				SimpleSAML_Logger::debug(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					"User record recently updated already: $username"
				);
				return $user;
			}

			// Get the later delay until time
			$delayuntil = ($delayuntil > $user['DelayUntil'] ? $delayuntil : $user['DelayUntil']);

			// The update statement
			$statement = 'UPDATE ' . $this->prefix . '_users SET ' .
				'UserId = ' . $this->pdo->quote($userid) . ', ' .
				'Username = ' . $this->pdo->quote($username) . ', ' .
				'LastUpdated = ' . $this->pdo->quote(date('Y-m-d H:i:s', $lastupdated)) . ', ' .
				'DelayUntil = ' . $this->pdo->quote(date('Y-m-d H:i:s', $delayuntil)) . ' ' .
				'WHERE UserId = ' . $this->pdo->quote($userid)
			;

		// No user found in the database, so insert a new record
		} else {
			$statement = 'INSERT INTO ' . $this->prefix . '_users ' .
				'(UserId, Username, LastUpdated, DelayUntil) VALUES (' .
				$this->pdo->quote($userid) . ', ' .
				$this->pdo->quote($username) . ', ' .
				$this->pdo->quote(date('Y-m-d H:i:s', $lastupdated)) . ', ' .
				$this->pdo->quote(date('Y-m-d H:i:s', $delayuntil)) . ')'
			;
		}

		// Execute the SQL query above
		$this->pdo->query($statement);

		// Update the cached record
		$this->users[$userid] = array(
			'UserId' => $userid,
			'Username' => $username,
			'LastUpdated' => $lastupdated,
			'DelayUntil' => $delayuntil
		);

		// All done
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Updated or inserted user record in database. Statement: $statement"
		);
		return $this->users[$userid];
	}
}

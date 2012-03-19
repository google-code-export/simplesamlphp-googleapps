<?php

/**
 * This base class should probably be used for any Auth Process
 * classes as it does a lot of the setup which will be needed
 * to communicate with Google Apps and track user provisioning info.
 * It SHOULD NOT be specified as a filter in SimpleSAMLphp config,
 * as it should throw a warning since it is an abstract class.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package simpleSAMLphp-GoogleApps
 * @version $Id$
 */
abstract class sspmod_googleapps_Auth_Process_Base extends SimpleSAML_Auth_ProcessingFilter {

	/**
	 * This is an instance of the ApiHelper class which
	 * is used to query Google Apps API. It stores required
	 * authentication data used with API queries.
	 *
	 * @var sspmod_googleapps_ApiHelper
	 */
	protected $api;


	/**
	 * List of attribute names in the config to be used
	 * when searching for a requests attribute value.
	 *
	 * @var array
	 */
	protected $attribute_map = array();


	/**
	 * When the process method is called the Attribute
	 * values are stored here, to be used by the
	 * getAttribute method later.
	 *
	 * @var array
	 */
	protected $attributes;


    /**
     * If the current filter was created by a chain
     * then this is the chains instance. Used to avoid
     * redundancy, running code twice.
     *
     * @var sspmod_googleapps_Auth_Process_Chain
     */
    protected $chain_class;


	/**
	 * If a config was specified in the filter config,
	 * then its' instance will be stored here for access.
	 *
	 * @var SimpleSAML_Configuration
	 */
	protected $config;


	/**
	 * This is an instance of the PdoHelper class which
	 * is used to store provisioning information about
	 * users.
	 *
	 * @var sspmod_googleapps_PdoHelper
	 */
	protected $pdo;


	/**
	 * This base constructor sets all the optional and required
	 * data for all GoogleApps filters. It should be called by
	 * any child classes.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param $config
	 * @param null|sspmod_googleapps_Auth_Process_Chain $chain
	 * @param $reserved
	 */
	public function __construct(&$config, $reserved, sspmod_googleapps_Auth_Process_Chain $chain = NULL) {
		parent::__construct($config, $reserved);

		// Log the filter creation
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__, __CLASS__) .
			'Creating new filter instance...'
		);

		// If a chain instance is passed then there is no need to
		// proceed with the config below, just get the values
		// from the chain, which would be the same.
		if (!is_null($chain)) {
			$this->api = $chain->api;
			$this->attribute_map = $chain->attribute_map;
			// $this->attributes = $chain->attributes; // Not needed, set by process()
            $this->chain_class = get_class($chain); // TODO: Issue w/ saving chain instance instead
			$this->config = $chain->config;
			$this->pdo = $chain->pdo;
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__, __CLASS__) .
				'Base configuration set from the chain instance.'
			);
			return;
		}

		// If a config was specified then try to load it
		if (isset($config['config']) && $config['config']) {
			$config_name  = (is_string($config['config']) ? $config['config'] : 'googleapps.php');
			$config_array = SimpleSAML_Configuration::getConfig($config_name)->toArray();
			unset($config['config']);
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__, __CLASS__) .
				"Loaded config file: $config_name"
			);
		}

		// Merge the filter config with the config file,
		// having the filter config overwrite the config file values
		$config_array = (isset($config_array) ? array_merge($config_array, $config) : $config);
		$this->config = SimpleSAML_Configuration::loadFromArray($config_array, 'module-googleapps');

		// Store the attribute names
		$this->attribute_map = array(
			'userid' => $this->config->getString('attribute.userid', 'objectGUID'),
			'username' => $this->config->getString('attribute.username', 'sAMAccountName'),
			'firstname' => $this->config->getString('attribute.firstname', 'givenName'),
			'lastname' => $this->config->getString('attribute.lastname', 'sn'),
			'dn' => $this->config->getString('attribute.dn', 'distinguishedName')
		);
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__, __CLASS__) .
			'Set the attribute map: ' . $this->var_export($this->attribute_map)
		);

		// Try to get the domain connection to GoogleApps
		$this->api = sspmod_googleapps_ApiHelper::getInstance($this->config);

		// Try to get the database instance
		$this->pdo = sspmod_googleapps_PdoHelper::getInstance($this->config);

		// Log debug info
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__, __CLASS__) .
			'Base configuration set from scratch.'
		);
	}


	/**
	 * This parent process method does a few initial checks
	 * and balances for the child classes to continue. All
	 * child classes need to call this method first, and check
	 * for the returned status code (bool) to continue or not.
	 *
	 * @param array $request
	 * @return bool
	 */
	public function process(&$request) {
		assert('is_array($request)');
		assert('isset($request["Attributes"])');

		// Log the filter process
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__, __CLASS__) .
			'Starting filter process...'
		);

		// Set the attribute values to be looked up by getAttribute()
		$this->attributes =& $request['Attributes'];
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__, __CLASS__) .
			'Set the request attributes: ' . implode(', ', array_keys($this->attributes))
		);

		// If this filter is in the chain then do not continue,
		// the chain already called the rest of this code
		if ($this->chain_class) {
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__, __CLASS__) .
				'Base process already ran by the chain instance.'
			);
			return TRUE;
		}

		// Get provisioning info from DB
		$user = $this->pdo->getUser($this->getAttribute('userid'));

		// Check if the user should be denied login yet
		if (isset($user['DelayUntil']) && $user['DelayUntil'] > time()) {
			$this->delayLogin($request);
		}

		// Check if user was recently updated, skip all provisioning tasks
		// However, if th user was recently provisioned then finish running filters
		if (isset($user['LastUpdated']) &&
		    ($user['LastUpdated'] + $this->config->getInteger('apps.interval', 86400)) > time() &&
		     $user['LastUpdated'] > $user['DelayUntil']) {
			SimpleSAML_Logger::info(
				$this->getClassTitle(__FUNCTION__, __CLASS__) .
				'User was recently updated, do not process filter(s). Username: ' . $user['Username']
			);
			return FALSE;
		}

		// Provisioning tasks can continue
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__, __CLASS__) .
			'Base process complete, filter process starting.'
		);
		return TRUE;
	}


	/**
	 * Standard getter method to access the requests attribute
	 * values. Valid options are set in the constructor.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $name
	 * @param string $type
	 * @return string|array
	 */
	protected function getAttribute($name, $type = 'string') {
		assert('is_string($name) && $name != ""');
		assert('is_string($type) && $type != ""');

		// Just cleanup the arguments
		$name = strtolower(trim($name));
		$type = strtolower(trim($type));

		// Log the attribute request
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__) .
			"Getting the attribute $name value, which should be a $type"
		);

		// Verify that the requested attribute is an option
		if (!isset($this->attribute_map[$name])) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
                "The attribute $name does not have a real name mapped."
			);
		}

		// Get the real attribute name
		$real_name = $this->attribute_map[$name];

		// Verify that the request attribute is available
		if (!isset($this->attributes[$real_name])) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				"The requested attribute $name does not have a value set for the real name $real_name"
			);
		}

		// Default value, defined for IDE
		$value = NULL;

		// Get the value based on type request
		switch ($type) {

			// Only thing special for strings is imploding arrays
			case 'string':
				$value = (
					is_array($this->attributes[$real_name]) ?
					implode(' ', $this->attributes[$real_name]) :
					(string) $this->attributes[$real_name]
				);
				break;

			// Just cast array types, will put other values in first element
			case 'array':
				$value = (array) $this->attributes[$real_name];
				break;

			// Wrong type requested
			default:
				throw new SimpleSAML_Error_Exception(
					$this->getClassTitle(__FUNCTION__) .
					"Invalid value type requested [$type] for $name which is really $real_name"
				);
		}

		// Convert binary attributes to hex
		// TODO: Better way to detect binary values
		if ($real_name == 'objectGUID') {
			$value = bin2hex($value);
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__) .
				"Converted the attribute $name value from binary to hex: $value"
			);
		}

		// All done
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__) .
			"Returning value for $name which is really $real_name and type $type. " .
			$this->var_export($value)
		);
		return $value;
	}


	/**
	 * Since multiple filters may need to delay the user from being
	 * logged in, this method will complete the process to do that.
	 * It's just an easy way to update the "delay" code in the future
	 * and of course code "reuse".
	 *
	 * @param array $request
	 * @param string $reason Should be "created" or "renamed"
	 * @return void
	 */
	protected function delayLogin(array &$request, $reason = 'default') {
		assert('is_string($reason) && $reason != ""');

		// Get user info for the delay
		$user = $this->pdo->getUser($this->getAttribute('userid'));

		// Info not in PDO, caller should update record before delaying
		if (empty($user)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'User record not found in PDO: ' . $this->getAttribute('userid')
			);
		}

		// Just log the delay
		SimpleSAML_Logger::info(
			$this->getClassTitle(__FUNCTION__) .
			'User login being delayed. Username: ' . $user['Username'] .
			' Until: ' . date('m/d/y g:i A', $user['DelayUntil']) .
			' Reason: ' . ucfirst($reason)
		);

		// Store the delay time and complete the delay process
		$request['GoogleApps']['DelayUntil'] = $user['DelayUntil'];
		$request['GoogleApps']['DelayReason'] = strtolower($reason);
		$request['PersistentAuthData'][] = 'GoogleApps';
		$id = SimpleSAML_Auth_State::saveState($request, 'googleapps:AuthProcess');
		$url = SimpleSAML_Module::getModuleURL('googleapps/delay_202.php');
		SimpleSAML_Utilities::redirect($url, array('StateId' => $id));
	}


	/**
	 * Local utility function to get the caller name which
	 * will be used in logging messages.
	 *
	 * @param string $method Should be the __FUNCTION__ magic constant value
     * @param string $class If passed, the parent class name will be appended
	 * @return string
	 */
	protected function getClassTitle($method, $class = NULL) {
		assert('is_string($method) && $method != ""');
		assert('is_string($class) || is_null($class)');

		// Separate each part of the caller name
		$caller = get_class($this);
		$caller = explode('_', $caller);

		// Separate each part of the chain name
		$chain = ($this->chain_class ? explode('_', $this->chain_class) : NULL);

		// Separate each part of the class name,
		// only if name is not the same as caller
		$class = ($class == implode('_', $caller) ? NULL : $class);
		$class = ($class ? explode('_', $class) : $class);

		// Build the name
		$name = 'GoogleApps';
		$name .= ($chain ? ':' . end($chain) : '');
		$name .= ':' . end($caller);
		$name .= ($class ? ':' . end($class) : '');
		$name .= '->' . $method . '() : ';

		// Return the complete name
		return $name;
	}


	/**
	 * Local utility function to get details about a variable,
	 * basically converting it to a string to be used in a log
	 * message. The var_export() function returns several lines
	 * so this will remove the new lines and trim each line.
	 *
	 * @param mixed $value
	 * @return string
	 */
	protected function var_export($value) {
		$export = var_export($value, TRUE);
		$lines = explode("\n", $export);
		foreach ($lines as &$line) {
			$line = trim($line);
		}
		return implode(' ', $lines);
	}
}
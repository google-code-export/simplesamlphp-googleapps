<?php

/**
 * This authentication process filter will dynamically provision
 * GoogleApps user accounts. It also updates existing users with
 * their latest first and last names. And by tracking previous
 * usernames it will also re-name an account as needed.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package simpleSAMLphp-GoogleApps
 * @version $Id$
 */
class sspmod_googleapps_Auth_Process_Provision extends sspmod_googleapps_Auth_Process_Base {

	/**
	 * Change the default priority to be earlier
	 * than the default of 50. Provisioning should
	 * always be first for any GoogleApps filters.
	 *
	 * @var int
	 */
	public $priority = 49;


	/**
	 * Temporary storage for the current users password.
	 * Used if module is configured to sync the users password
	 * with Google Apps. Note: Requires a hack of the
	 * SimpleSAMLphp source.
	 *
	 * @var string
	 */
	protected static $password;


	/**
	 * Typical setter method for the users current password.
	 * No getter so that other code cannot access the password.
	 *
	 * @param string $password
	 * @param string $username
	 */
	public static function setPassword($password, $username = '[unknown]') {
		self::$password = trim((string) $password);
		SimpleSAML_Logger::info("GoogleApps:Provision:setPassword() Password captured for user: [$username]");
	}


	/**
	 * This is the method which is called when the filter is
	 * executed. It will check with GoogleApps for existing
	 * user info and updated it as needed, else provision a new
	 * account. Updates to the PDO database are also given.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param $request
	 * @return
	 */
	public function process(&$request) {

		// Run the parent method, will return
		// FALSE if user was recently provisioned
		if (!parent::process($request)) {
			return;
		}

		// Get the required attribute values
		$local = array(
			'userid'    => $this->getAttribute('userid'),
			'username'  => $this->getAttribute('username'),
			'firstname' => $this->getAttribute('firstname'),
			'lastname'  => $this->getAttribute('lastname')
		);

		// Get the users record from database
		$pdo = $this->pdo->getUser($local['userid']);

		// If the user logged in previously, and with a
		// different name, then check GoogleApps for that account.
		$username = (
			isset($pdo['Username'])
			? $pdo['Username']
			: $local['username']
		);

		// Request user info from GoogleApps
		SimpleSAML_Logger::debug($this->getClassTitle(__FUNCTION__) . "Checking for an existing user: [$username]");
		$xml = $this->api->get('https://apps-apis.google.com/a/feeds/%%DOMAIN%%/user/2.0/' . urlencode($username));

		// Bad return from GoogleApps API
		if (!($xml instanceof SimpleXMLElement) && !is_bool($xml)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				"Invalid XML returned from GoogleApps for user [$username]: $xml"
			);
		}

		// Init some vars for processing
		$update = array();
		$remote = $this->parseResults($xml);
		$local_username  = strtolower($local['username']);
		$remote_username = strtolower($remote['username']);
		$status = (!$xml ? 'create' : 'update');

		// Determine the differences
		if ($local['firstname'] != $remote['firstname'])  $update['firstname'] = $local['firstname'];
		if ($local['lastname']  != $remote['lastname'])   $update['lastname']  = $local['lastname'];
		if ($local_username     != $remote_username)      $update['username']  = $local['username'];
		if ($status == 'create' || $this->syncPassword()) $update['password']  = $this->getPassword();

		// Nothing to update...
		if (empty($update)) {
			$this->pdo->setUser($local['userid'], $local['username']);
			SimpleSAML_Logger::info(
				$this->getClassTitle(__FUNCTION__) .
				"Existing user found, no updates required for " .
				"{$local['lastname']}, {$local['firstname']} [{$local['username']}]"
			);
			return;
		}

		// Logging message for debugging
		SimpleSAML_Logger::info($this->getClassTitle(__FUNCTION__) . "Attempting to $status user account: [$username]");

		// Send user account info to Google Apps API
		$type = ($status == 'create' ? 'post' : 'put');
		$url  = 'https://apps-apis.google.com/a/feeds/%%DOMAIN%%/user/2.0';
		$url .= ($status == 'update' ? '/' . urlencode($username) : '');
		$xml  = $this->api->$type($url, $this->buildUserEntry($update));

		// Check returned content type from API request
		if (!($xml instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				"Unable to $status user account for [{$username}], result: $xml"
			);
		}

		// Update the users record from the database
		$this->pdo->setUser($local['userid'], $local['username'], (isset($update['username']) ? TRUE : FALSE));

		// Logging message for debugging
		$message = $this->getClassTitle(__FUNCTION__) . "User account {$status}d";
		if ($status == 'create') {
			$message .= " for {$local['lastname']}, {$local['firstname']} [{$local['username']}]";
		} elseif (count($update) == 1 && isset($update['password'])) {
			$message .= ", password synced for {$local['lastname']}, {$local['firstname']} [{$local['username']}]";
		} else {
			$message .= ". From: {$remote['lastname']}, {$remote['firstname']} [{$remote['username']}]";
			$message .= " To: {$local['lastname']}, {$local['firstname']} [{$local['username']}]";
		}
		SimpleSAML_Logger::notice($message);

		// If the username changes, login should be delayed
		if (isset($update['username'])) {
			$this->delayLogin($request, ($status == 'create' ? 'created' : 'renamed'));
		}
	}


	/**
	 * Takes the XML User Entry Atom feed from a GoogleApps API query and
	 * parses the XML date for specific attribute values.
	 *
	 * @param mixed $xml
	 * @return array
	 * @throws SimpleSAML_Error_Exception
	 */
	protected function parseResults($xml) {

		// No result to parse..
		if (!($xml instanceof SimpleXMLElement)) {
			return array(
				'username'  => '',
				'firstname' => '',
				'lastname'  => ''
			);
		}

		// Array to store GoogleApps account info
		$results = array(
			'username'  => '//apps:login/@userName',
			'firstname' => '//apps:name/@givenName',
			'lastname'  => '//apps:name/@familyName'
		);

		/**
		 * Search for each field using the xpath query
		 * @var SimpleXMLElement $xml
		 */
		foreach ($results as $attribute => &$query) {
			if ($xpath = $xml->xpath($query)) {
				$query = (string) $xpath[0];
			} else {
				throw new SimpleSAML_Error_Exception(
					$this->getClassTitle(__FUNCTION__) .
					"Unable to find the $attribute from the GoogleApps response: " .
					$this->var_export($xml)
				);
			}
		}

		// All results found
		return $results;
	}


	/**
	 * Built a Google Apps User Entry Atom feed to be used with
	 * a API call to create/update user account information.
	 *
	 * @param array $attributes
	 * @return string
	 */
	protected function buildUserEntry(array $attributes) {
		assert('!empty($attributes)');

		// Prep attribute values for use in XML
		foreach ($attributes as &$value) {
			$value = htmlspecialchars($value);
		}

		// Open the User Entry Atom feed
		$xml = <<<ATOM
<atom:entry xmlns:atom="http://www.w3.org/2005/Atom"
  xmlns:apps="http://schemas.google.com/apps/2006">
    <atom:category scheme="http://schemas.google.com/g/2005#kind"
      term="http://schemas.google.com/apps/2006#user"/>

ATOM;

		// Add the login element, if/as needed
		if (isset($attributes['username']) && isset($attributes['password'])) {
			$xml .= '    <apps:login userName="' . $attributes['username'] . '"';
			$xml .= ' password="' . $attributes['password'] . '" hashFunctionName="SHA-1" />';
		} elseif (isset($attributes['username'])) {
			$xml .= '    <apps:login userName="' . $attributes['username'] . '" />';
		} elseif (isset($attributes['password'])) {
			$xml .= '    <apps:login password="' . $attributes['password'] . '" hashFunctionName="SHA-1" />';
		}

		// Line return between elements
		$xml .= PHP_EOL;

		// Add the name element, if/as needed
		if (isset($attributes['firstname']) && isset($attributes['lastname'])) {
			$xml .= '    <apps:name familyName="' . $attributes['lastname'] . '"';
			$xml .= ' givenName="' . $attributes['firstname'] . '" />';
		} elseif (isset($attributes['firstname'])) {
			$xml .= '    <apps:name givenName="' . $attributes['firstname'] . '" />';
		} elseif (isset($attributes['lastname'])) {
			$xml .= '    <apps:name familyName="' . $attributes['lastname'] . '" />';
		}

		// Close the User Entry Atom feed
		$xml .= PHP_EOL . '</atom:entry>' . PHP_EOL;

		// All done
		return $xml;
	}


	/**
	 * Returns weather or not to sync the users password based on the config option.
	 * The provision.password MUST be boolean TRUE to enable sync, anything else is not.
	 *
	 * @return bool
	 */
	protected function syncPassword() {
		try { return $this->config->getBoolean('provision.password', FALSE); }
		catch (Exception $e) { return FALSE; }
	}


	/**
	 * Getter for the password to use with the Google Apps account.
	 * Could be a number of options depending on the module config.
	 *
	 * @return string
	 * @throws SimpleSAML_Error_Exception
	 */
	protected function getPassword() {

		// If not to sync, get the default password or generate random
		if (!$this->syncPassword()) {
			return sha1($this->config->getString('provision.password', $this->generatePassword()));
		}

		// Check if password was captured
		if (!is_string(self::$password)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Users password NOT captured for [' .
				$this->getAttribute('username') .
				']. Verify the GoogleApps modification for SimpleSAML has been applied.'
			);
		}

		// Make sure captured password is valid
		if (!self::$password) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Users password captured for [' .
				$this->getAttribute('username') .
				'] but not a valid Google Apps password.'
			);
		}

		// Return captured password
		return sha1(self::$password);
	}


	/**
	 * When creating a new GoogleApps account, a password is
	 * required. However, since SimpleSAML is doing the authentication
	 * then it doesn't matter what the password is. For security reasons
	 * we may generate a random password for each new user.
	 *
	 * @return string
	 */
	protected function generatePassword() {

		// The starting string containing many character options
		$chars = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ1234567890';

		// Make each character its own array element
		$chars = str_split($chars);

		// Get 10 random character keys
		$keys = array_rand($chars, 10);

		// Take the keys and get the character value
		$password = array();
		foreach ($keys as $key) {
			$password[] = $chars[$key];
		}

		// Randomize the order again
		shuffle($password);

		// Convert the array back to a string
		$password = implode('', $password);

		// GoogleApps wants the SHA1 value
		return $password;
	}
}

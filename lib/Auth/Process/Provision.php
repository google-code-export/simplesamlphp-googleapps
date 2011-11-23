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
			'userid' => $this->getAttribute('userid'),
			'username' => $this->getAttribute('username'),
			'firstname' => $this->getAttribute('firstname'),
			'lastname' => $this->getAttribute('lastname')
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
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__) .
			'Checking for an existing user: ' . $username
		);
		$xml = $this->api->get(
			'https://apps-apis.google.com/a/feeds/%%DOMAIN%%/user/2.0/' . urlencode($username)
		);

		// Not found, create a new user
		if ($xml === FALSE) {
			SimpleSAML_Logger::notice(
				$this->getClassTitle(__FUNCTION__) .
				'Existing user not found, creating new user and delaying login. ' .
				$local['lastname'] . ', ' . $local['firstname'] . ' [' . $local['username'] . ']'
			);
			$this->createUser($local['username'], $local['firstname'], $local['lastname']);
			$this->pdo->setUser($local['userid'], $local['username'], TRUE);
			$this->delayLogin($request, 'created');
		}

		// Wrong response from GoogleApps
		if (!($xml instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				"Invalid XML returned from GoogleApps: $xml"
			);
		}

		// Array to store GoogleApps account info
		$remote = array();

		// Get the username
		if ($xpath = $xml->xpath('//apps:login/@userName')) {
			$remote['username'] = (string) $xpath[0];
		} else {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Unable to find the username from the GoogleApps response: ' .
				$this->var_export($xml)
			);
		}

		// Get the first name
		if ($xpath = $xml->xpath('//apps:name/@givenName')) {
			$remote['firstname'] = (string) $xpath[0];
		} else {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Unable to find the first name from the GoogleApps response: ' .
				$this->var_export($xml)
			);
		}

		// Get the last name
		if ($xpath = $xml->xpath('//apps:name/@familyName')) {
			$remote['lastname'] = (string) $xpath[0];
		} else {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Unable to find the last name from the GoogleApps response: ' .
				$this->var_export($xml)
			);
		}

		// No difference in user information,
		// continue with the SAML request.
		if (strtolower($local['username']) == strtolower($remote['username']) &&
		    $local['firstname'] == $remote['firstname'] &&
		    $local['lastname'] == $remote['lastname']) {
			$this->pdo->setUser($local['userid'], $local['username']);
			SimpleSAML_Logger::info(
				$this->getClassTitle(__FUNCTION__) .
				'Existing user found, no updated required. ' . $local['lastname'] .
				', ' . $local['firstname'] . ' [' . $local['username'] . ']'
			);
			return;
		}

		// Log info
		SimpleSAML_Logger::notice(
			$this->getClassTitle(__FUNCTION__) .
			'Existing user found, updates required. From: ' .
			$remote['lastname'] . ', ' . $remote['firstname'] . ' [' . $remote['username'] . '] To: ' .
			$local['lastname'] . ', ' . $local['firstname'] . ' [' . $local['username'] . ']'
		);

		// Update user information
		$this->updateUser($remote['username'], $local['username'], $local['firstname'], $local['lastname']);
		$delay = ($local['username'] != $remote['username'] ? TRUE : FALSE);
		$this->pdo->setUser($local['userid'], $local['username'], $delay);

		// Forward user to the delayed page, if needed
		if ($delay) {
			$this->delayLogin($request, 'renamed');
		}
	}


	/**
	 * Takes user information and creates a new GoogleApps account.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $username
	 * @param string $firstname
	 * @param string $lastname
	 * @return void
	 */
	protected function createUser($username, $firstname, $lastname) {
		assert('is_string($username) && $username != ""');
		assert('is_string($firstname) && $firstname != ""');
		assert('is_string($lastname) && $lastname != ""');

		// The XML framework to send to GoogleApps
		$body = <<<ATOM
<atom:entry xmlns:atom="http://www.w3.org/2005/Atom"
  xmlns:apps="http://schemas.google.com/apps/2006">
    <atom:category scheme="http://schemas.google.com/g/2005#kind"
        term="http://schemas.google.com/apps/2006#user" />
    <apps:login userName="%%USERNAME%%"
        password="%%PASSWORD%%" hashFunctionName="SHA-1" />
    <apps:name familyName="%%LASTNAME%%" givenName="%%FIRSTNAME%%" />
</atom:entry>
ATOM;

		// Insert data into the XML
		$password = sha1($this->config->getString('provision.password', $this->getRandomPassword()));
		$body = str_ireplace('%%USERNAME%%', htmlspecialchars($username), $body);
		$body = str_ireplace('%%PASSWORD%%', $password, $body);
		$body = str_ireplace('%%FIRSTNAME%%', htmlspecialchars($firstname), $body);
		$body = str_ireplace('%%LASTNAME%%', htmlspecialchars($lastname), $body);

		// Send the request to GoogleApps
		$xml = $this->api->post('https://apps-apis.google.com/a/feeds/%%DOMAIN%%/user/2.0', $body);

		// Valid XML will be returned if successful
		if (!($xml instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				"Unable to create a new user account, $username. $xml"
			);
		}

		// All done
		SimpleSAML_Logger::info(
			$this->getClassTitle(__FUNCTION__) .
			"Created new user account: $lastname, $firstname [$username]"
		);
	}


	/**
	 * Takes user information and updates an existing GoogleApps account.
	 * If the username has changed then the account is renamed as well.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $remote Existing GoogleApps username
	 * @param string $local Local username, possibly different
	 * @param string $firstname
	 * @param string $lastname
	 * @return void
	 */
	protected function updateUser($remote, $local, $firstname, $lastname) {
		assert('is_string($remote) && $remote != ""');
		assert('is_string($local) && $local != ""');
		assert('is_string($firstname) && $firstname != ""');
		assert('is_string($lastname) && $lastname != ""');

		// The XML framework to send to GoogleApps
		$body = <<<ATOM
<atom:entry xmlns:atom="http://www.w3.org/2005/Atom"
  xmlns:apps="http://schemas.google.com/apps/2006">
    <atom:category scheme="http://schemas.google.com/g/2005#kind"
        term="http://schemas.google.com/apps/2006#user"/>
    <apps:name familyName="%%LASTNAME%%" givenName="%%FIRSTNAME%%"/>

ATOM;

		// If username changed, update GoogleApps
		$body .= ($local != $remote ? '    <apps:login userName="%%USERNAME%%"/>' . PHP_EOL : '');

		// Close the Atom entry
		$body .= '</atom:entry>' . PHP_EOL;

		// Insert data into the XML
		$body = str_ireplace('%%USERNAME%%', htmlspecialchars($local), $body);
		$body = str_ireplace('%%FIRSTNAME%%', htmlspecialchars($firstname), $body);
		$body = str_ireplace('%%LASTNAME%%', htmlspecialchars($lastname), $body);

		// Send the request to GoogleApps
		$xml = $this->api->put(
			'https://apps-apis.google.com/a/feeds/%%DOMAIN%%/user/2.0/' . urlencode($remote),
			$body
		);

		// Valid XML will be returned if successful
		if (!($xml instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				"Unable to update existing user account, $remote. $xml"
			);
		}

		// All done
		SimpleSAML_Logger::info(
			$this->getClassTitle(__FUNCTION__) .
			"Updated existing user: $lastname, $firstname [$local]"
		);
	}


	/**
	 * When creating a new GoogleApps account, a password is
	 * required. However, since SimpleSAML is doing the authentication
	 * then it doesn't matter what the password is. For security reasons
	 * we generate a random password for each new user.
	 *
	 * @return string
	 */
	protected function getRandomPassword() {

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
		$password = implode($password);

		// GoogleApps wants the SHA1 value
		return $password;
	}
}

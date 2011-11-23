<?php

/**
 * This class is used by the AuthFilter classes to send/get
 * information from the GoogleApps API. It authenticates to
 * the API and stores required auth tokens to be used for later
 * requests. Also acts as an abstraction layer to provide
 * the ability to make requests to different API's.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package SimpleSAMLphp-GoogleApps
 * @version $Id$
 */
class sspmod_googleapps_ApiHelper extends sspmod_googleapps_BaseHelper {

	/**
	 * This is the version of the entire GoogleApps module.
	 * Will conform to the PHP versioning format to support
	 * version_compare() http://www.php.net/version_compare
	 */
	const VERSION = '1.0.0';

	/**
	 * List of HTTP codes that GoogleApps may return. Used
	 * in exceptions when not 200 or 201.
	 * http://code.google.com/apis/gdata/docs/2.0/reference.html#HTTPStatusCodes
	 * 
	 * @var array
	 */
	protected static $http_codes = array(
		400 => 'Bad Request',
		401 => 'Unauthorized',
		403 => 'Incorrect Input or Forbidden',
		404 => 'Not Found',
		409 => 'Conflict',
		410 => 'Gone',
		412 => 'Precondition Failed',
		500 => 'Internal Server Error',
		503 => 'Quotas Exceeded'
	);

	/**
	 * This value is used when creating the expire timestamp
	 * for authentication data. The default is 23 hours and
	 * 45 minutes. Can be changed manually here.
	 *
	 * @var int
	 */
	protected static $expires_in = 85500;

	/**
	 * This array contains all instances of this class for
	 * each domain connected to. The key is the domain name
	 * with the actual instance as the value. Used for the
	 * singleton pattern.
	 *
	 * @var array
	 */
	protected static $instances = array();

	/**
	 * The Auth token returned by the GoogleApps API connection.
	 * This is used in the header for any subsequent requests to the API.
	 *
	 * @var string
	 */
	protected $auth_token;

	/**
	 * Customer ID returned by the GoogleApps API connection.
	 * This is used in some URL's for GoogleApps Org requests.
	 *
	 * @var string
	 */
	protected $customer_id;

	/**
	 * Domain name of the GoogleApps which it is connected to.
	 *
	 * @var string
	 */
	protected $domain;

	/**
	 * Timestamp when the current connection (auth token) expires,
	 * causing a new connection to be made.
	 *
	 * @var int
	 */
	protected $expires;

	/**
	 * The time in seconds which the timeout settings will be
	 * set in the cURL requests. Retrieved from the config.
	 *
	 * @var int
	 */
	protected $timeout;

	/**
	 * The constructor is protected to force the singleton pattern.
	 * All new instances must be created by getConnection().
	 *
	 * @param string $domain
	 * @param string $auth_token
	 * @param string $customer_id
	 * @param string $expires
	 * @param int $timeout
	 */
	final protected function __construct($domain, $auth_token, $customer_id, $expires, $timeout) {
		assert('is_string($domain) && $domain != ""');
		assert('is_string($auth_token) && $auth_token != ""');
		assert('is_string($customer_id) && $customer_id != ""');
		assert('is_int($expires) && $expires > 0');
		assert('is_int($timeout) && $timeout > 0');

		// Set the values
		$this->domain = $domain;
		$this->auth_token = $auth_token;
		$this->customer_id = $customer_id;
		$this->expires = $expires;
		$this->timeout = $timeout;

		// Add to instance cache
		self::$instances[$domain] = $this;

		// Log debug info
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Created new API connection to ' . $domain .
			' which expires ' . date('m/d/y g:i A', $expires) .
			". Timeout Seconds: $timeout Customer ID: $customer_id Auth Token: $auth_token"
		);
	}

	/**
	 * Private to force singleton pattern.
	 * @return void
	 */
	final private function __clone() { throw new Exception('Cannot clone GoogleApps ApiHelper'); }

	/**
	 * This is the main method to get an ApiHelper instance. It will
	 * first check a couple cache sources for existing connection data.
	 * Else it will use the domain and login info to create a new
	 * connection to GoogleApps, then store the date in cache.
	 *
	 * @static
	 * @throws SimpleSAML_Error_Exception
	 * @param SimpleSAML_Configuration $config
	 * @return sspmod_googleapps_ApiHelper
	 */
	public static function getInstance(SimpleSAML_Configuration $config) {

		// Just log the instance request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Getting API connection...'
		);

		// Get the required data from the config
		$domain = $config->getString('apps.domain');
		$username = $config->getString('apps.username');
		$password = $config->getString('apps.password');
		$timeout = $config->getInteger('apps.timeout', 30);

		// Check for an existing connection to a Google Apps domain
		// Also verify that the connection hasn't expired
		if (isset(self::$instances[$domain])) {
			$instance = self::$instances[$domain];
			if ($instance->expires > time()) {
				SimpleSAML_Logger::debug(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'Found API connection in cache, returning. Domain: ' . $domain
				);
				return $instance;
			} else {
				unset(self::$instances[$domain]);
				SimpleSAML_Logger::debug(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'Found API connection in cache has expired, deleting and creating new connection. Domain: ' .
					$domain
				);
			}
		}

		// Check for cached connection data file
		if ($cache = self::getCacheFile($domain)) {
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'Found API connection in cache file, creating new instance and returning.'
			);
			return new self($domain, $cache['auth_token'], $cache['customer_id'], $cache['expires'], $timeout);
		}

		// Make sure CURL is available
		if (!function_exists('curl_init')) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'The required PHP cURL extension is unavailable.'
			);
		}

		// Make the POST field data
		$fields = array(
			'Email' => $username . '@' . $domain,
			'Passwd' => $password,
			'accountType' => 'HOSTED',
			'service' => 'apps',
			'source' => 'SimpleSAMLphp-GoogleApps-' . self::VERSION
		);

		// cURL options to be set
		$options = array(
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_URL => 'https://www.google.com/accounts/ClientLogin',
			CURLOPT_POST => TRUE,
			CURLOPT_POSTFIELDS => http_build_query($fields)
		);

		// Send request to the GoogleApps API
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting to login to Google ClientLogin for $domain with $username"
		);
		$response = (string) self::query($options);

		// Parse the response to look for the auth token
		// Done this way instead of preg_match for performance
		$auth_token = '';
		$lines = array_reverse(explode("\n", $response)); // Auth= is typically last
		foreach ($lines as $line) {
			$keyval = explode('=', $line);
			if (count($keyval) != 2) continue;
			list($field, $value) = $keyval;
			if ($field == 'Auth') {
				$auth_token = $value;
				break;
			}
		}

		// Verify the auth token was found above
		if (!$auth_token) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Could not find the Auth token for $domain from Google ClientLogin response: $response"
			);
		}

		// cURL options to change for the Customer ID request
		$options = array(
			CURLOPT_CONNECTTIMEOUT => $timeout,
			CURLOPT_TIMEOUT => $timeout,
			CURLOPT_URL => 'https://apps-apis.google.com/a/feeds/customer/2.0/customerId',
			CURLOPT_HTTPGET => TRUE,
			CURLOPT_HTTPHEADER => array(
				'Content-type: application/atom+xml',
				'Authorization: GoogleLogin auth=' . $auth_token
			)
		);

		/**
		 * Send request to the GoogleApps API
		 * @var $response SimpleXMLElement
		 */
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting to get the Customer Id for $domain with Auth token: $auth_token"
		);
		$response = self::query($options);

		// Make sure the returned content is XML
		if (!($response instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Invalid XML returned from the $domain Customer Id request: $response"
			);
		}

		// Search for the Customer ID
		if (!($xpath = $response->xpath('//apps:property[@name="customerId"]/@value'))) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Could not find the Customer Id for $domain from the response: " . self::var_export($response)
			);
		}

		// Customer ID found
		$customer_id = (string) $xpath[0];

		// Log the info
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Retrieved API connection data from GoogleApps, creating new instance and returning.'
		);

		// Create a new connection instance
		$connection = new self($domain, $auth_token, $customer_id, time() + self::$expires_in, $timeout);

		// Save the connection
		self::setCacheFile($connection);

		// All done
		return $connection;
	}

	/**
	 * This method checks the local filesystem for a temporary
	 * cache file. If one exists it will return the data in an array.
	 * Else it will return NULL|FALSE on any other errors.
	 *
	 * @static
	 * @param string $domain
	 * @return array|bool|null
	 */
	protected static function getCacheFile($domain) {
		assert('is_string($domain) && $domain != ""');

		// Log the request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Checking for API cache file, for connection details.'
		);

		// Build the file path to the temporary connection information
		$file = SimpleSAML_Configuration::getInstance()->getPathValue('datadir');
		$file = str_replace('\\', '/', $file);
		$file .= 'googleapps_' . $domain . '.xml';

		// Check for an existing cache file
		if (!file_exists($file)) {
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Cache file does not exist: $file"
			);
			return NULL;
		}

		// This readability check is just to help with debugging needs
		// Nice to know if it might be a permissions issue with file structure
		if (!is_readable($file)) {
			SimpleSAML_Logger::warning(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to read the cache file: $file"
			);
			return FALSE;
		}

		// Get the connection cache XML file
		// Check for XML errors at the same time
		// http://www.php.net/manual/en/simplexml.examples-errors.php
		libxml_use_internal_errors(true);
		if (!($xml = @simplexml_load_file($file))) {

			// Get errors and create error message
			$errors = libxml_get_errors();
			array_walk($errors, create_function('&$v,$k', '$v=$v->message;'));
			$errors = implode('; ', $errors);

			// Log the XML issues
			SimpleSAML_Logger::warning(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to load cache file XML [$file], error(s): " .
				implode('; ', $errors)
			);

			// Method failed
			return FALSE;
		}

		// Get the values from the XML file
		// Convert to proper type at the same time
		$cache = array(
			'auth_token' => (string) @$xml->{'auth_token'}[0],
			'customer_id' => (string) @$xml->{'customer_id'}[0],
			'expires' => strtotime((string) @$xml->{'expires'}[0])
		);

		// If for some reason the expires element is missing,
		// just check the file modification time and add to that.
		if (!$cache['expires']) {
			$cache['expires'] = filemtime($file);
			$cache['expires'] += self::$expires_in;
			SimpleSAML_Logger::info(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Getting expiration from file modification time: $file"
			);
		}

		// If the connection cache has expired,
		// delete the file and don't return the data
		if ($cache['expires'] < time()) {
			SimpleSAML_Logger::debug(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Cache file has expired, deleting: $file"
			);
			unlink($file);
			return NULL;
		}

		// Just verify something is set
		if (!$cache['auth_token']) {
			SimpleSAML_Logger::warning(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to find the auth_token in cache file, deleting: $file"
			);
			unlink($file);
			return FALSE;
		}

		// Just verify something is set
		if (!$cache['customer_id']) {
			SimpleSAML_Logger::warning(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to find the customer_id in cache file, deleting: $file"
			);
			unlink($file);
			return FALSE;
		}

		// Cache is good, return data
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Retrieved API connection data from cache file: $file"
		);
		return $cache;
	}

	/**
	 * This simply takes the connection info and writes
	 * the XML cache file, used for storing Auth tokens.
	 *
	 * @static
	 * @param sspmod_googleapps_ApiHelper $connection
	 * @return bool
	 */
	protected static function setCacheFile(self $connection) {

		// Log the request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Attempting to set the API connection cache file...'
		);

		// Create the XML to save in the cache file
		$xml = new SimpleXMLElement('<connection></connection>');
		$xml->addChild('auth_token', $connection->auth_token);
		$xml->addChild('customer_id', $connection->customer_id);
		$xml->addChild('expires', date('Y-m-d H:i:s', $connection->expires));

		// Build the file path to the temporary connection information
		$file = SimpleSAML_Configuration::getInstance()->getPathValue('datadir');
		$file = str_replace('\\', '/', $file);
		$file .= 'googleapps_' . $connection->domain . '.xml';

		// Save XML to cache file, but if fails just log
		// because connection info is still valid. Used
		// file_put_contents so that a file lock could be included.
		if (!file_put_contents($file, $xml->asXML(), LOCK_EX)) {
			SimpleSAML_Logger::warning(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to save to the cache file: $file"
			);
			return FALSE;
		}

		// Successful
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Saved API connection data to cache file: $file"
		);
		return TRUE;
	}

	/**
	 * This method does the actual API request to GoogleApps.
	 * Only requires cURL options to be passed, including URL.
	 * Once the request is complete it will try to convert XML
	 * data to a SimpleXMLElement object, else just return the
	 * string. Any HTTP errors are thrown in an exceptions code.
	 * It is defined static so that the getConnection() method
	 * can also make calls to it.
	 * 
	 * Note: Auth headers should be defined in the passed options.
	 * 
	 * @static
	 * @throws SimpleSAML_Error_Exception
	 * @param array $options
	 * @return SimpleXMLElement|string|bool
	 */
	protected static function query(array $options) {
		assert('is_array($options)');

		// Verify that the only required option is set
		if (!isset($options[CURLOPT_URL])) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'The required cURL option CURLOPT_URL is not set in the options' .
				self::var_export($options)
			);
		}

		// Make sure these options are set
		$options[CURLOPT_RETURNTRANSFER] = TRUE;
		$options[CURLOPT_SSL_VERIFYPEER] = FALSE;
		$options[CURLOPT_SSL_VERIFYHOST] = FALSE;

		// Make sure CURL is available and get resource
		if (!($curl = curl_init())) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'Could not initialize the PHP cURL extension'
			);
		}

		// Set all the CURL options
		if (!curl_setopt_array($curl, $options)) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'Could not set the cURL options for request: ' . $options[CURLOPT_URL] .
				' Error message: ' . curl_error($curl)
			);
		}

		// Execute request to Google
		if (!($response = curl_exec($curl))) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				'Could not complete the cURL request for request: ' . $options[CURLOPT_URL] .
				' Error message: ' . curl_error($curl)
			);
		}

		// Check the returned HTTP code for status info
		// http://code.google.com/apis/gdata/docs/2.0/reference.html#HTTPStatusCodes
		$http_code = curl_getinfo($curl, CURLINFO_HTTP_CODE);
		switch ($http_code) {

			// Good request
			case 200:
			case 201:
				break;

			// Not found
			case 404:
				SimpleSAML_Logger::debug(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'API request returned http code 404 which means "Not Found"'
				);
				return FALSE;

			// Not found
			// Sometimes 400 Bad Request is returned with a errorCode="1301", which is "Not Found"
			case 400:
				if (stripos($response, 'errorCode="1301"')) {
					SimpleSAML_Logger::debug(
						self::getClassTitle(__FUNCTION__, __CLASS__) .
						'API request returned http code 400 with error code 1301 which means "Not Found"'
					);
					return FALSE;
				}

			// Bad request
			default:

				// Known error code with description
				if (isset(self::$http_codes[$http_code])) {
					throw new SimpleSAML_Error_Exception(
						self::getClassTitle(__FUNCTION__, __CLASS__) .
						'Error ' . $http_code . ' (' . self::$http_codes[$http_code] . ') from request: ' .
						$options[CURLOPT_URL] . ' Response: ' . $response,
						$http_code
					);
				}

				// If here, then its an unknown error code
				throw new SimpleSAML_Error_Exception(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'Unknown error code [' . $http_code . '] from request: ' .
					$options[CURLOPT_URL] . ' Response: ' . $response,
					$http_code
				);
		}

		// Check if response is XML
		if (stripos(curl_getinfo($curl, CURLINFO_CONTENT_TYPE), 'xml') !== FALSE) {

			// Attempt to convert
			libxml_use_internal_errors(true);
			if (!($xml = @simplexml_load_string($response))) {

				// Get errors and create error message
				$errors = libxml_get_errors();
				array_walk($errors, create_function('&$v,$k', '$v=$v->message;'));
				$errors = implode('; ', $errors);

				// Just log the issues, returning the string instead
				SimpleSAML_Logger::warning(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'Request returned invalid XML: ' .
					$errors . '. Returned: ' . $response
				);

			} else {
				// Conversion worked, return object
				$response = $xml;
				SimpleSAML_Logger::debug(
					self::getClassTitle(__FUNCTION__, __CLASS__) .
					'Converted response to XML for the request.'
				);
			}
		}

		// Close the cURL resource
		curl_close($curl);

		// All done
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			'Request complete, returning result for: ' . $options[CURLOPT_URL]
		);
		return $response;
	}

	/**
	 * Sets up a HTTP GET request for GoogleApps API
	 * then sends the request to query(), returning
	 * the results or throwing errors.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $url
	 * @return SimpleXMLElement|string|bool
	 */
	public function get($url) {
		assert('is_string($url) && $url != ""');

		// Replace the GoogleApps specific pieces in the URL
		$url = str_ireplace('%%DOMAIN%%', $this->domain, $url);
		$url = str_ireplace('%%CUSTID%%', $this->customer_id, $url);

		// Set the cURL options for the request
		$options = array(
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_URL => $url,
			CURLOPT_HTTPGET => TRUE,
			CURLOPT_HTTPHEADER => array(
				'Content-type: application/atom+xml',
				'Authorization: GoogleLogin auth=' . $this->auth_token
			)
		);

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting GET request to URL: $url"
		);

		// Attempt request to GoogleApps, returning
		// any errors for the caller to handle.
		try {
			return self::query($options);
		} catch (SimpleSAML_Error_Exception $e) {
			throw $e;
		}
	}

	/**
	 * Sets up a HTTP POST request for GoogleApps API
	 * then sends the request to query(), returning
	 * the results or throwing errors.
	 * 
	 * @throws SimpleSAML_Error_Exception
	 * @param string $url
	 * @param array $fields
	 * @return SimpleXMLElement|string|bool
	 */
	public function post($url, $fields) {
		assert('is_string($url) && $url != ""');

		// Replace the GoogleApps specific pieces in the URL
		$url = str_ireplace('%%DOMAIN%%', $this->domain, $url);
		$url = str_ireplace('%%CUSTID%%', $this->customer_id, $url);

		// Replace the GoogleApps specific pieces in the field(s)
		if (is_array($fields)) {
			foreach ($fields as &$field) {
				$field = str_ireplace('%%DOMAIN%%', $this->domain, $field);
				$field = str_ireplace('%%CUSTID%%', $this->customer_id, $field);
			}
		} elseif (is_string($fields)) {
			$fields = str_ireplace('%%DOMAIN%%', $this->domain, $fields);
			$fields = str_ireplace('%%CUSTID%%', $this->customer_id, $fields);
		}

		// Set the cURL options for the request
		$options = array(
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_URL => $url,
			CURLOPT_POST => TRUE,
			CURLOPT_POSTFIELDS => (is_array($fields) ? http_build_query($fields) : (string) $fields),
			CURLOPT_HTTPHEADER => array(
				'Content-type: application/atom+xml',
				'Authorization: GoogleLogin auth=' . $this->auth_token
			)
		);

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting POST request to URL: $url Post Fields: " . $options[CURLOPT_POSTFIELDS]
		);

		// Attempt request to GoogleApps, returning
		// any errors for the caller to handle.
		try {
			return self::query($options);
		} catch (SimpleSAML_Error_Exception $e) {
			throw $e;
		}
	}

	/**
	 * Sets up a HTTP PUT request for GoogleApps API
	 * then sends the request to query(), returning
	 * the results or throwing errors.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $url
	 * @param string $body
	 * @return SimpleXMLElement|string|bool
	 */
	public function put($url, $body) {
		assert('is_string($url) && $url != ""');
		assert('is_string($body) && $body != ""');

		// Replace the GoogleApps specific pieces in the URL
		$url = str_ireplace('%%DOMAIN%%', $this->domain, $url);
		$url = str_ireplace('%%CUSTID%%', $this->customer_id, $url);

		// Replace the GoogleApps specific pieces in the body
		$body = str_ireplace('%%DOMAIN%%', $this->domain, $body);
		$body = str_ireplace('%%CUSTID%%', $this->customer_id, $body);

		// Try to open a memory space else create a temp file
		// Used to store the body contents in a resource handler
		if (!($temp = fopen('php://temp/maxmemory:262144', 'w')) &&
			!($temp = tmpfile())) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to create temporary PHP memory for PUT request: $url"
			);
		}

		/**
		 * Write the body to the temporary memory
		 * @var $temp resource Fix for phpStorm
		 */
		if (!fwrite($temp, $body)) {
			throw new SimpleSAML_Error_Exception(
				self::getClassTitle(__FUNCTION__, __CLASS__) .
				"Unable to write to temporary PHP memory for PUT request: $url"
			);
		}

		// Basically rewind to the beginning
		fseek($temp, 0);

		// Set the cURL options for the request
		$options = array(
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_URL => $url,
			CURLOPT_PUT => TRUE,
			CURLOPT_BINARYTRANSFER => TRUE,
			CURLOPT_INFILE => $temp,
			CURLOPT_INFILESIZE => strlen($body),
			CURLOPT_HTTPHEADER => array(
				'Content-type: application/atom+xml',
				'Authorization: GoogleLogin auth=' . $this->auth_token
			)
		);

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting PUT request to URL: $url Body: $body"
		);

		// Attempt request to GoogleApps, returning
		// any errors for the caller to handle.
		try {
			$response = self::query($options);
			fclose($temp);
			return $response;
		} catch (SimpleSAML_Error_Exception $e) {
			fclose($temp);
			throw $e;
		}
	}

	/**
	 * Sets up a HTTP DELETE request for GoogleApps API
	 * then sends the request to query(), returning
	 * the results or throwing errors.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param string $url
	 * @return SimpleXMLElement|string|bool
	 */
	public function delete($url) {
		assert('is_string($url) && $url != ""');

		// Replace the GoogleApps specific pieces in the URL
		$url = str_ireplace('%%DOMAIN%%', $this->domain, $url);
		$url = str_ireplace('%%CUSTID%%', $this->customer_id, $url);

		// Set the cURL options for the request
		$options = array(
			CURLOPT_CONNECTTIMEOUT => $this->timeout,
			CURLOPT_TIMEOUT => $this->timeout,
			CURLOPT_URL => $url,
			CURLOPT_CUSTOMREQUEST => 'DELETE',
			CURLOPT_HTTPHEADER => array(
				'Content-type: application/atom+xml',
				'Authorization: GoogleLogin auth=' . $this->auth_token
			)
		);

		// Log request
		SimpleSAML_Logger::debug(
			self::getClassTitle(__FUNCTION__, __CLASS__) .
			"Attempting DELETE request to URL: $url"
		);

		// Attempt request to GoogleApps, returning
		// any errors for the caller to handle.
		try {
			return self::query($options);
		} catch (SimpleSAML_Error_Exception $e) {
			throw $e;
		}
	}
}

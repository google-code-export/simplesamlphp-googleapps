<?php

/**
 * This authentication process filter will move users into
 * the proper OU. If the OU structure does not exist it will
 * be created.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package simpleSAMLphp-GoogleApps
 * @version $Id$
 */
class sspmod_googleapps_Auth_Process_Organize extends sspmod_googleapps_Auth_Process_Base {

	/**
	 * This is the method which is called when the filter is
	 * executed. It will check a users OU path for changes.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param $request
	 * @return
	 */
	public function process(&$request) {

		// Run the parent method, will return
		// FALSE if user was recently organized
		if (!parent::process($request)) {
			return;
		}

		// Get the required request attributes
		$local['userid'] = $this->getAttribute('userid');
		$local['username'] = $this->getAttribute('username');
		$local['dn'] = $this->getAttribute('dn');

		// Get OUs from users DN
		$ou_array = $this->getOu($local['dn']);

		// Make the OU path
		$local['ou'] = implode('/', $ou_array);

		// Retrieve the users current OU location from GoogleApps
		$xml = $this->api->get(
			'https://apps-apis.google.com/a/feeds/orguser/2.0/%%CUSTID%%/' .
			urlencode($local['username'] . '@') . '%%DOMAIN%%'
		);

		// If the user was not found...
		if ($xml === FALSE) {
			SimpleSAML_Logger::warning(
				$this->getClassTitle(__FUNCTION__) .
				'User not found, make sure they are Provisioned first: ' . $local['username']
			);
		}

		// Something went wrong with the request
		if (!($xml instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Invalid XML returned from GoogleApps: ' . $xml
			);
		}

		// Search for the OU from GoogleApps response
		if (!($xpath = $xml->xpath('//apps:property[@name="orgUnitPath"]/@value'))) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Could not find the OU path for user: ' . $local['username'] .
				' XML: ' . $this->var_export($xml)
			);
		}

		// Extract OU path from search results
		$remote['ou'] = (string) @$xpath[0];

		// If no OU path specified change to root, /
		$local['ou'] = ($local['ou'] ? $local['ou'] : '/');
		$remote['ou'] = ($remote['ou'] ? $remote['ou'] : '/');

		// Check if no updates need to be done
		if ($local['ou'] == $remote['ou']) {
			$this->pdo->setUser($local['userid'], $local['username']);
			SimpleSAML_Logger::info(
				$this->getClassTitle(__FUNCTION__) .
				'No OU changes needed for user. Username: ' .
				$local['username'] . ' OU: ' . $local['ou']
			);
			return;
		}

		// Make sure OU to move to exists
		if (!empty($ou_array)) {
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__) .
				'Verifying OU structure exists: ' . $local['ou']
			);
			$this->makeOuStructure($ou_array);
		}

		// The XML framework to send to GoogleApps
		$body = <<<ATOM
<atom:entry xmlns:atom='http://www.w3.org/2005/Atom' xmlns:apps='http://schemas.google.com/apps/2006'>
    <apps:property name="oldOrgUnitPath" value="%%OLDPATH%%" />
    <apps:property name="orgUnitPath" value="%%NEWPATH%%" />
</atom:entry>
ATOM;

		// Insert data into the XML
		$body = str_ireplace('%%NEWPATH%%', htmlspecialchars($local['ou']), $body);
		$body = str_ireplace('%%OLDPATH%%', htmlspecialchars($remote['ou']), $body);

		// Send the request to GoogleApps
		$xml = $this->api->put(
			'https://apps-apis.google.com/a/feeds/orguser/2.0/%%CUSTID%%/' .
			urldecode($local['username'] . '@') . '%%DOMAIN%%',
			$body
		);

		// Valid XML will be returned if successful
		if (!($xml instanceof SimpleXMLElement)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Could not move user ' . $local['username'] .
				' to OU. From: ' . $remote['ou'] . ' To: ' . $local['ou']
			);
		}

		// Update the PDO info
		$this->pdo->setUser($local['userid'], $local['username']);

		// Log debug info
		SimpleSAML_Logger::notice(
			$this->getClassTitle(__FUNCTION__) .
			'User ' . $local['username'] . ' moved to OU. From: ' .
			$remote['ou'] . ' To: ' . $local['ou']
		);
	}


	/**
	 * Utility function to get the OU path from the
	 * users full DN. Then reverses the path for conform
	 * to GoogleApps structure.
	 *
	 * @param string $dn
	 * @return array
	 */
	protected function getOu($dn) {
		assert('is_string($dn) && $dn != ""');

		// Find commas and escape/remove
		$dn = str_replace(array('\\\,', '\,'), '%%COMMA%%', $dn);

		// Separate each RDN
		$dn = explode(',', $dn);

		// Find just the OUs and replace commas
		$ou = array();
		foreach ($dn as $rdn) {
			list($type, $value) = explode('=', $rdn);
			if ($type == 'OU') $ou[] = str_replace('%%COMMA%%', ',', $value);
		}

		// GoogleApps OU's are specified in reverse order
		$ou = array_reverse($ou);

		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__) .
			'OU path from DN [' . implode(',', $dn) . '] found to be: ' . implode('/', $ou)
		);

		// All done
		return $ou;
	}


	/**
	 * This function will make sure that the OU path exists.
	 * If it does not, it will create it. The parent OU path
	 * is first checked before creating the OU, recursively
	 * building/verifying the OU path.
	 * 
	 * @throws SimpleSAML_Error_Exception
	 * @param array $ou
	 * @return
	 */
	protected function makeOuStructure(array $ou) {
		assert('!empty($ou)');

		// Escape OU names to be used in URL path
		$escaped = array();
		foreach ($ou as $rdn) {
			$escaped[] = urlencode($rdn);
		}

		// The API will return something if OU was found
		// No need to continue and make a new OU
		if ($this->api->get(
			'https://apps-apis.google.com/a/feeds/orgunit/2.0/%%CUSTID%%/' . implode('/', $escaped)
		)) {
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__) .
				'OU path already exists: ' . implode('/', $ou)
			);
			return;
		}

		// Need to separate paths
		$parent = $ou;
		$current = array_pop($parent);

		// Check parent OU structure
		if (!empty($parent)) {
			$this->makeOuStructure($parent);
		}

		// Change to the URL escaped version
		// of the OU structure, per Google API docs
		$parent = $escaped;
		array_pop($parent);
		$parent = (empty($parent) ? '/' : implode('/', $parent));

		// This is the Atom XML sent to the API
		$body = <<<ATOM
<atom:entry xmlns:atom='http://www.w3.org/2005/Atom' xmlns:apps='http://schemas.google.com/apps/2006'>
<id>https://apps-apis.google.com/a/feeds/customer/2.0/%%CUSTID%%</id>
    <apps:property name="name" value="%%OUNAME%%" />
    <apps:property name="description" value="Created by SimpleSAMLphp-GoogleApps on %%DATE%%" />
    <apps:property name="parentOrgUnitPath" value="%%PARENTOU%%" />
</atom:entry>
ATOM;

		// Enter the OU info into the XML, %%CUSTID%% is handled by the API class
		$boyd = str_ireplace('%%DATE%%', htmlspecialchars(date('F n, Y')), $body);
		$body = str_ireplace('%%OUNAME%%', htmlspecialchars($current), $body);
		$body = str_ireplace('%%PARENTOU%%', htmlspecialchars($parent), $body);

		// If the API doesn't send a proper response then something happened
		if (!$this->api->post('https://apps-apis.google.com/a/feeds/orgunit/2.0/%%CUSTID%%', $body)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Could not create the OU: ' . implode('/', $ou));
		}

		// Log debug info
		SimpleSAML_Logger::notice(
			$this->getClassTitle(__FUNCTION__) .
			'Created new OU: ' . implode('/', $ou)
		);
	}
}
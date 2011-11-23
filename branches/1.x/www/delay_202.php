<?php
/**
 * Show a 202 Accepted page about process complete but delayed login.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package SimpleSAMLphp-GoogleApps
 * @version $Id$
 */

if (!array_key_exists('StateId', $_REQUEST)) {
	throw new SimpleSAML_Error_BadRequest('Missing required StateId query parameter.');
}

$state = SimpleSAML_Auth_State::loadState($_REQUEST['StateId'], 'googleapps:AuthProcess');

if (!isset($state['GoogleApps']['DelayUntil'])) {
	throw new SimpleSAML_Error_BadRequest('Missing required GoogleApps DelayUntil state parameter.');
}

if (!isset($state['GoogleApps']['DelayReason'])) {
	throw new SimpleSAML_Error_BadRequest('Missing required GoogleApps DelayReason state parameter.');
}

$globalConfig = SimpleSAML_Configuration::getInstance();
$t = new SimpleSAML_XHTML_Template($globalConfig, 'googleapps:delay_202.php');
$t->data['pageid'] = 'googleapps:delay_202';
$t->data['until'] = (int) $state['GoogleApps']['DelayUntil'];
$t->data['reason'] = $state['GoogleApps']['DelayReason'];
$t->data['restartURL'] = (string) @$state['SimpleSAML_Auth_State.restartURL'];
header('HTTP/1.0 202 Accepted');
$t->show();

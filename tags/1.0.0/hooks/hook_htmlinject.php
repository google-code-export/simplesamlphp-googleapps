<?php

/**
 * Hook to inject HTML meta refresh into the delay_202.php page
 *
 * @param array &$hookinfo  hookinfo
 */
function googleapps_hook_htmlinject(&$hookinfo) {
	assert('is_array($hookinfo)');
	assert('array_key_exists("head", $hookinfo)');
	assert('array_key_exists("page", $hookinfo)');

	// Only apply to the delay page
	if ($hookinfo['page'] != 'googleapps:delay_202') {
		return;
	}

	// Must have the state id set
	if (!isset($_REQUEST['StateId'])) {
		SimpleSAML_Logger::warning('GoogleApps:Hook:HTMLInject : The request StateId is not set.');
		return;
	}

	// Get the state, which should have the info
	$state = SimpleSAML_Auth_State::loadState($_REQUEST['StateId'], 'googleapps:AuthProcess');

	// Check for the delay timestamp
	if (!isset($state['GoogleApps']['DelayUntil'])) {
		SimpleSAML_Logger::warning('GoogleApps:Hook:HTMLInject : The GoogleApps DelayUntil state is not set.');
		return;
	}

	// Must know what URL to refreash to
	if (!isset($state['SimpleSAML_Auth_State.restartURL']) && $state['SimpleSAML_Auth_State.restartURL'] != '') {
		SimpleSAML_Logger::warning('GoogleApps:Hook:HTMLInject : The restart URL is not defined.');
		return;
	}

	// Calculate the delay to seconds
	$refresh = (int) $state['GoogleApps']['DelayUntil'] + 5 - time();

	// Must be a positive delay in seconds
	if ($refresh < 0) {
		SimpleSAML_Logger::warning('GoogleApps:Hook:HTMLInject : Invalid timestamp must have been passed, negative number.');
		return;
	}

	// Add the meta refresh header
	$hookinfo['head'][] =
			'<meta http-equiv="refresh" content="' . $refresh . '; url=' . $state['SimpleSAML_Auth_State.restartURL'] . '">';

	// Log the meta refresh
	SimpleSAML_Logger::debug("GoogleApps:Hook:HTMLInject : Meta refresh will run in $refresh seconds.");
}

<?php

/**
 * When more than one googleapps filter is needed then this chain
 * class should be used. It will execute the filters in the proper
 * order and make sure everything is completed for the first provisioning.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package simpleSAMLphp-GoogleApps
 * @version $Id$
 */
class sspmod_googleapps_Auth_Process_Chain extends sspmod_googleapps_Auth_Process_Base {

	/**
	 * Array of other filter instances to be
	 * processed by the chain.
	 *
	 * @var array
	 */
	protected $filters = array();


	/**
	 * Checks the config for filters to be processed
	 * and creates new instances of them.
	 *
	 * @throws SimpleSAML_Error_Exception
	 * @param $config
	 * @param $reserved
	 */
	public function __construct(&$config, $reserved) {
		parent::__construct($config, $reserved);

		// Get the filters defined to be ran
		$filters = $this->config->getArrayizeString('chain.filters', array());

		// Apparently the admin wants a chain but no filters..
		if (empty($filters)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'No filters defined for the process chain.'
			);
		}

		// Just a sanity check, if the chain is not needed
		if (count($filters) == 1) {
			SimpleSAML_Logger::info(
				$this->getClassTitle(__FUNCTION__) .
				'Only one filter defined for the process chain, chain not required. ' .
				implode($filters)
			);

		// Log the filters defined
		} else {
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__) .
				'Chain filters retrieved from config: ' . implode(', ', $filters)
			);
		}

		// Do not pass the config priority, so that we get the
		// filters default priority, used for sorting the order
		unset($config['%priority']);

		// Changes the element values keys instead,
		// this helps when checking for defined filters below.
		// Also helps get unique values.
		$filters = array_flip($filters);

		// Dynamically load the filters
		foreach ($filters as $filter => $nothing) {

			// User can pass the entire filter class name
			// or just the last suffix of the filter name
			if (strpos($filter, 'sspmod_') === 0) {
				$class = $filter;
			} elseif (strpos($filter, 'googleapps:') === 0) {
				$class = explode(':', $filter);
				array_shift($class);
				$class = implode('_', $class);
				$class = 'sspmod_googleapps_Auth_Process_' . $class;
			} else {
				$class = 'sspmod_googleapps_Auth_Process_' . $filter;
			}

			// Make sure the filter class exists, forces autoload if needed
			if (!class_exists($class, TRUE)) {
				throw new SimpleSAML_Error_Exception(
					$this->getClassTitle(__FUNCTION__) .
					"Filter [$filter] class does not exist: $class"
				);
			}

			// Must use reflection to check if class name is subclass of..
			$reflection = new ReflectionClass($class);

			// Must be a GoogleApps Auth Process Base filter
			if (!$reflection->isSubclassOf(get_parent_class())) {
				throw new SimpleSAML_Error_Exception(
					$this->getClassTitle(__FUNCTION__) .
					"Filter [$filter] class not a child of the Base class: $class"
				);
			}

			// Cannot be another chain, recursion may occur
			if ($class == get_class() || $reflection->isSubclassOf(get_class())) {
				throw new SimpleSAML_Error_Exception(
					$this->getClassTitle(__FUNCTION__) .
					"Filter [$filter] class cannot be another Chain class: $class"
				);
			}

			// Create a new instance of the defined filter
			$instance = new $class($config, $reserved, $this);
			$priority = $instance->priority;

			// Increment the priority until another is not found
			while (TRUE) {
				if (!isset($this->filters[$priority])) break;
				$priority++;
			}

			// Add the filter instance to the list
			$this->filters[$priority] = $instance;
		}

		// Sort by priority so that they are run in the proper order
		if (!ksort($this->filters, SORT_NUMERIC)) {
			throw new SimpleSAML_Error_Exception(
				$this->getClassTitle(__FUNCTION__) .
				'Could not sort filters, default priority must not be numeric in a filter class.'
			);
		}

		// Construct complete
		SimpleSAML_Logger::debug(
			$this->getClassTitle(__FUNCTION__) .
			'Created new process chain instance.'
		);
	}


	/**
	 * Just cycles through each filter and runs
	 * its' process.
	 *
	 * @param $request
	 * @return
	 */
	public function process(&$request) {

		// Run the parent method, will return
		// FALSE if user was recently checked
		if (!parent::process($request)) {
			return;
		}

		// Process each filter instance
		foreach ($this->filters as $filter) {
			SimpleSAML_Logger::debug(
				$this->getClassTitle(__FUNCTION__) .
				'Processing the chain filter: ' . get_class($filter)
			);
			/**
			 * @var $filter sspmod_googleapps_Auth_Process_Base
			 */
			$filter->process($request);
		}

		// All done
		SimpleSAML_Logger::info(
			$this->getClassTitle(__FUNCTION__) .
			'Chain process complete, user being logged in: ' . $this->getAttribute('username')
		);
	}
}
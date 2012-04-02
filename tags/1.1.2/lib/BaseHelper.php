<?php

/**
 * This just has some helpful utility functions that
 * helper classes could use.
 *
 * @author Ryan Panning <panman@traileyes.com>
 * @package SimpleSAMLphp-GoogleApps
 * @version $Id$
 */
class sspmod_googleapps_BaseHelper {

	/**
	 * Local utility function to get the caller name which
	 * will be used in logging messages.
	 *
	 * @param string $method Should be the __FUNCTION__ magic constant value
	 * @param string $class If passed, the parent class name will be appended
	 * @return string
	 */
	protected static function getClassTitle($method, $class) {
		assert('is_string($method) && $method != ""');
		assert('is_string($class) && $class != ""');

		// Separate each part of the class name
		$class = explode('_', $class);

		// Build the name
		$name = 'GoogleApps';
		$name .= ':' . end($class);
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
	protected static function var_export($value) {
		$export = var_export($value, TRUE);
		$lines = explode("\n", $export);
		foreach ($lines as &$line) {
			$line = trim($line);
		}
		return implode(' ', $lines);
	}
}

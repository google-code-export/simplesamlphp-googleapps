# Module Requirements #

In addition to the [SimpleSAMLphp Prerequisites](http://simplesamlphp.org/docs/trunk/simplesamlphp-install#section_3), this module has the following requirements:

  * PHP cURL extension must be enabled
  * PHP PDO extension (and specific driver) must be enabled
  * SimpleSAMLphp data directory must be writable

### cURL Extension ###

This module utilizes the highly used [PHP cURL extension](http://www.php.net/manual/en/book.curl.php) to communicate with the Google Apps API via HTTP. Verify that it is enabled by checking the [php\_info()](http://www.php.net/manual/en/function.phpinfo.php) for a cURL section. To enable, modify your php.ini file and un-comment the extension.

### PDO Extension ###

In order to provide provisioning delay's and username change tracking, this module saves information into a database. It supports [PDO](http://www.php.net/manual/en/book.pdo.php) so different database backend products can be used. Verify that it is enabled by checking the [php\_info()](http://www.php.net/manual/en/function.phpinfo.php) for a PDO section. To enable, modify your php.ini file and un-comment the extension and specific driver extension.

### Data Directory ###

Not many other modules use the **`simplesamlphp/data`** directory so it may not be writable by PHP. This module saves temporary Google Apps API connection information here. Please make sure the proper permissions are set so that SimpleSAMLphp can write to the directory.
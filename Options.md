# Configuration Options #

Most of the configuration options are used in multiple filters and can be defined in one configuration file to be shared between filters. The options listed below should be defined in either the config file or filter config with the option name as the key and values as the element (eg: `array('apps.username' => 'my_admin')`).

| **Option** | **Required** | **Filter(s)** | **Data Type(s)** | **Default Value** |
|:-----------|:-------------|:--------------|:-----------------|:------------------|
| [apps.domain](Options#apps.domain.md) | Yes | [All](Filters.md) | string | _empty string_ |
| [apps.username](Options#apps.username.md) | Yes | [All](Filters.md) | string | _empty string_ |
| [apps.password](Options#apps.password.md) | Yes | [All](Filters.md) | string | _empty string_ |
| [apps.timeout](Options#apps.timeout.md) | No | [All](Filters.md) | int | 30 |
| [apps.interval](Options#apps.interval.md) | No | [All](Filters.md) | int | 86400 |
| [chain.filters](Options#chain.filters.md) | No | [Chain](Filters#googleapps:Chain.md) | array | array() |
| [provision.password](Options#provision.password.md) | No | [Provision](Filters#googleapps:Provision.md) | string|bool | _random string_ |
| [provision.delay](Options#provision.delay.md) | No | [Provision](Filters#googleapps:Provision.md) | int | 600 |
| [pdo.dsn](Options#pdo.dsn.md) | No | [All](Filters.md) | string | "sqlite:/simplesamlphp/data/googleapps\_apps.domain.sqlite" |
| [pdo.username](Options#pdo.username.md) | No | [All](Filters.md) | string | NULL |
| [pdo.password](Options#pdo.password.md) | No | [All](Filters.md) | string | NULL |
| [pdo.prefix](Options#pdo.prefix.md) | No | [All](Filters.md) | string | "googleapps" |
| [pdo.options](Options#pdo.options.md) | No | [All](Filters.md) | array | array() |
| [attribute.userid](Options#attribute.userid.md) | No | [All](Filters.md) | string | "objectGUID" |
| [attribute.username](Options#attribute.username.md) | No | [All](Filters.md) | string | "sAMAccountName" |
| [attribute.firstname](Options#attribute.firstname.md) | No | [Provision](Filters#googleapps:Provision.md) | string | "givenName" |
| [attribute.lastname](Options#attribute.lastname.md) | No | [Provision](Filters#googleapps:Provision.md) | string | "sn" |
| [attribute.dn](Options#attribute.dn.md) | No | [Organize](Filters#googleapps:Organize.md) | string | "distinguishedName" |


## apps.domain ##

This is the domain name which Google Apps is setup for. It should be the full domain name only, do NOT include `http://`.


## apps.username ##

Username of the Google Apps admin account to login to the API with.

_Note: This user must be "Super Admin" in your Google Apps domain, not a user with elevated privileges._


## apps.password ##

Password for the above Google Apps admin user.


## apps.timeout ##

Timeout value, in seconds, which is passed to the cURL extension for HTTP requests. This is the longest time the module will wait for Google Apps API to complete a task before failing.


## apps.interval ##

In order to prevent too much traffic to the Google Apps API, then exceeding quotas, this option specifies how often the module should check with the API for updates.

The value of this option is an integer representing the number of seconds between checks. The default value of 86400 is actually 24 hours. (60 `*` 60 `*` 24) == (seconds `*` minutes `*` hours)

_Note: Renamed in version 1.1 from provision.interval_


## chain.filters ##

If multiple filters/tasks need to be ran, then the Chain filter should be used as it will share config options and resources between all filters. This is an array of string elements with the names of filters that should be ran. The filters will be ran in the order defined, except [Provision](Filters#googleapps:Provision.md) which will always run first.

_Note: The filter names can be the short name (eg: Provision), filter name (eg: googleapps:Provision), or full class name (eg: sspmod\_googleapps\_Auth\_Process\_Provision)._


## provision.password ##

When new accounts are created they require a default password, even though you are likely using SimpleSAMLphp as a SSO solution. By default, the filter will generate a random 10 character `[a-zA-Z0-9]` password. If this option is defined with a string, it will use that string as the password instead.

Some Google Apps products do not support an SSO setup such as SimpleSAMLphp and default to whatever the actual Google Apps account password is. _As of version 1.1,_ if this option is set to boolean `TRUE`, AND the SimpleSAMLphp modification below has been applied, then the module will sync whatever password they entered into SimpleSAMLphp login with their Google Apps account.

Since SimpleSAMLphp does not allow modules access to the users password, a modification must be applied to capture the password for this module. Once captured, the password is encrypted (sha1) and stored temporarily in a SimpleSAMLphp session, _never_ permanently to a database. Follow the directions below to apply the mod:

  1. Open the file `simplesamlphp/modules/core/lib/Auth/UserPassBase.php` in a plain text editor, such as Notepad (Win) or TextEdit (Mac)
  1. Go to line 176, or look for the line `$attributes = $source->login($username, $password);` towards the bottom
  1. _BELOW_ that line, add the following code block
  1. Save and close that file, all done

```
			/* googleapps module modification to capture users password to sync with Google Apps */
			if (SimpleSAML_Module::isModuleEnabled('googleapps')) {
				sspmod_googleapps_Auth_Process_Provision::setPassword($password, $username);
			}
```


## provision.delay ##

When a new account is created or the username changes, Google recommends that the user not login for 10 minutes (which is the default setting). This option can be changed to a lower value but you may run into errors when SimpleSAMLphp forwards the user back to Google Apps. In this case you'll just need to play around with different delay's to get the sweet spot.


## pdo.dsn ##

The module tracks certain information when a user logs in to detect when changes are needed and when to check with Google Apps API. This information is saved in a database using the PHP PDO driver. It provides abstraction between different database backends so you may choose which to use. See the [PHP Documentation](http://www.php.net/manual/en/pdo.construct.php) for DSN options and formats.

By default, this module will automatically create a Sqlite database file in your `simplesamlphp/data` directory.


## pdo.username ##

When connecting to the database, this is the username used (which is not required for all PDO drivers). Again, see the [PHP Documentation](http://www.php.net/manual/en/pdo.construct.php) for details.


## pdo.password ##

When connecting to the database, this is the password used (which is not required for all PDO drivers). Again, see the [PHP Documentation](http://www.php.net/manual/en/pdo.construct.php) for details.


## pdo.prefix ##

The filter will add a prefix to all tables created in the database. This is helpful when an existing database is used which has other tables already created. An underscore is added between this prefix and the table name, so you don't have to add one on the end.


## pdo.options ##

When connecting to the database, these are the driver specific options. Again, see the [PHP Documentation](http://www.php.net/manual/en/pdo.construct.php) for details.


## attribute.userid ##

Users unique ID attribute defined in the `$request['Attributes']` when the filter is `process()`'ed. By default, this module is configured for ActiveDirectory with the LDAP module. The value of this attribute should never change.

_Note: This should NOT be the username, but rather the object ID in whatever directory service you are using._


## attribute.username ##

Username attribute defined in the `$request['Attributes']` when the filter is `process()`'ed. By default, this module is configured for ActiveDirectory with the LDAP module.


## attribute.firstname ##

First name attribute defined in the `$request['Attributes']` when the filter is `process()`'ed. By default, this module is configured for ActiveDirectory with the LDAP module.


## attribute.lastname ##

Last name attribute defined in the `$request['Attributes']` when the filter is `process()`'ed. By default, this module is configured for ActiveDirectory with the LDAP module.


## attribute.dn ##

The organize filter parses out the users OU path from the distinguished name attribute defined in the `$request['Attributes']` when the filter is `process()`'ed. By default, this module is configured for ActiveDirectory with the LDAP module.
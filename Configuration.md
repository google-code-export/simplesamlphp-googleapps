# Module Configuration #

The configuration of the module is meant to be simple yet flexible. However, there are a few things to configure before you are up-and-running. First, let Google Apps allow the module to provision/create new accounts. Second, tell the module how to connect to Google Apps. Lastly, enable the module filters so that SimpleSAMLphp runs them.

## Google Apps Configuration ##

Before this module can be used, the Provisioning API must be enabled in your Google Apps Dashboard. This allows the module to communicate with and manage your Google Apps users.

  1. Visit your Google Apps domain Dashboard (ex: http://www.google.com/a/example.org/)
  1. Login as a Google Apps domain admin
  1. Click on the **Domain settings** at the top
  1. Click on the **User settings** tab
  1. Check the **Enable provisioning API** option
  1. Click the **Save changes** button
  1. Click on the **Dashboard** at the top, you should now see a warning at the top "_API access is enabled..._" which is OK

## Config File (Optional) ##

The module filters can be set to look at a config file for their [options](Options.md). This is helpful to keep all the settings in one place, easier to update in the future. You can also have more than one config file, in the case where you have multiple domains. These files should be saved in the **`simplesamlphp/config`** folder. The default file name is **`googleapps.php`** but you can define a specific file name in the filter config.

#### Example ####

```
<?php
$config = array(
	'apps.domain' => 'example.org',
	'apps.username' => 'my_admin',
	'apps.password' => 'Abc123!',
	'apps.interval' => 57600, // 16 hours
	'provision.delay' => 300, // 5 minutes
	'chain.filters' => array('Provision', 'Organize')
);
```

## Enable Filter(s) ##

Even if a config file is used, the module filters still need to be enabled. There are several ways to enable filters, but in this case where Google Apps is probably setup to SSO with SimpleSAMLphp, we are going to work with the **`simplesamlphp/metadata/saml20-sp-remote.php`** file. Checkout the SimpleSAMLphp documentation for more about [Authentication Processing Filters](http://simplesamlphp.org/docs/trunk/simplesamlphp-authproc).

  1. Edit the **`simplesamlphp/metadata/saml20-sp-remote.php`** file
  1. Find the **`$metadata['google.com']`** section (or your specific domain name)
  1. Add an **`'authproc' => array()`** array element (if not already created)
  1. In the **`'authproc'`** array added above, add the [filter(s)](Filters.md) that you'd like to enable. See examples below

#### Examples ####

For the most basic configuration, specify your [options](Options.md) in the config file above and then enable the filter to look there.

```
$metadata['google.com'] = array(
	// Your specific IDp settings...
	'authproc' => array(
		60 => array(
			'class' => 'googleapps:Chain',
			'config' => TRUE // Will look for googleapps.php
		)
	)
);
```

If you have multiple config files you can specify a string as the config name.

```
$metadata['google.com'] = array(
	// Your specific IDp settings...
	'authproc' => array(
		60 => array(
			'class' => 'googleapps:Chain',
			'config' => 'googleapps_example.org.php'
		)
	)
);
```

Filter options always over-ride the config options (in the case where you provide both).

```
$metadata['google.com'] = array(
	// Your specific IDp settings...
	'authproc' => array(
		60 => array(
			'class' => 'googleapps:Provision',
			'config' => TRUE,
			'provision.delay' => 120
		)
	)
);
```

And if you don't use a config file, all the options can be defined in the filter config.

```
$metadata['google.com'] = array(
	// Your specific IDp settings...
	'authproc' => array(
		60 => array(
			'class' => 'googleapps:Chain',
			'apps.domain' => 'example.org',
			'apps.username' => 'my_admin',
			'apps.password' => 'Abc123!',
			'chain.filters' => array('Provision', 'Organize')
		)
	)
);
```

Finally, you can run the filters separately (not recommended).


```
$metadata['google.com'] = array(
	// Your specific IDp settings...
	'authproc' => array(
		40 => array(
			'class' => 'googleapps:Provision',
			'apps.domain' => 'example.org',
			'apps.username' => 'my_admin',
			'apps.password' => 'Abc123!',
			'provision.delay' => 300
		),
		60 => array(
			'class' => 'googleapps:Organize',
			'apps.domain' => 'example.org',
			'apps.username' => 'other_admin',
			'apps.password' => 'Xyz987!',
			'attributes.dn' => 'myDNattrib'
		)
	)
);
```
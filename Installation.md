# Module Installation #

There are two methods of installation; un-pack a zip file or use the pack.php tool. Most users will probably be familiar and most comfortable with downloading the zip and unpacking, but the pack.php tool provided by SimpleSAMLphp allows easy installation/upgrading via command line.

### Zip Download ###

The zip download simply contains the googleapps module folder which you extract to the SimpleSAMLphp modules folder. When updates come out you simply overwrite existing files.

  1. Visit the [Downloads](https://code.google.com/p/simplesamlphp-googleapps/downloads/list) page to get the latest release
  1. Unpack/copy the **`googleapps`** folder to the **`simplesamlphp/modules`** folder
  1. Make a new, empty file in the **`simplesamlphp/modules/googleapps`** folder named **`enable`**, with no extension
  1. **`googleapps`** module should now be installed and enabled, continue to the [Configuration](Configuration.md)

### Pack.php Tool ###

To use the pack.php tool, you'll need command line / SSH access to the SimpleSAMLphp installation. This may be the limiting factor for some hosting providers, in which case you'll have to use the Zip method above. Be sure to checkout the [pack.php tool documentation](http://simplesamlphp.org/docs/trunk/pack) before continuing to make sure the tool will work for you.

  1. SSH into the box where SimpleSAMLphp is installed
  1. **`cd`** to the SimpleSAMLphp installation directory
  1. Execute the command **`bin/pack.php install http://simplesamlphp-googleapps.googlecode.com/svn/trunk/definition.json`**
  1. **`googleapps`** module should now be installed and enabled, continue to the [Configuration](Configuration.md)

#### Branches ####

The project has been separated into branches of development to provide access to stable releases or development code. When using the pack.php tool above, you can select which branch to install:

  * **`1.x-svn`** Stable releases using SVN commands in the pack.php tool (default)
  * **`1.x-zip`** Stable releases using Zip commands in the pack.php tool
  * **`dev`** Direct trunk access to the latest code, uses SVN commands. **NOT FOR PRODUCTION USE**

### SVN Checkout ###

Since this module is on Google Project Hosting in an SVN repository, you could use client tools to checkout a copy. This way when there are new releases you can Switch/Update to the next release tag. This is very similar to the Pack.php Tool above, just a more manual process on your part.

#### Checkout Options ####

There are many locations where you could checkout from. Typically you would either checkout a tag or branch and update from there.

  * **`/tags`** Each release will be tagged separately allowing you to `Switch` between tags as needed
  * **`/branches`** Each branch will have changes merged allowing you to `Update` a checkout when updates are released
  * **`/trunk`** All development occurs here allowing you to `Update` to get all changes **NOT FOR PRODUCTION USE**
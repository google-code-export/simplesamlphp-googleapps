The different Google API tasks have been separated into different authentication process filters. That way you can pick and choose what tasks that need to be performed.



## googleapps:Chain ##

This is a special filter that does nothing on its' own. All it does is take a [list/array of other googleapps filters](Options#chain.filters.md) that should be run. The benefit of using this, rather than configuring each filter separately, is that the config options and resources are shared between all filters, improving performance.

## googleapps:Provision ##

Probably the most popular filter will be Provision. It will create a new user if they do not exist and rename users if their name and/or username changes. Note: This does not fill in and other profile details.

## googleapps:Organize ##

If your source is LDAP, this will replicate your Organization Units (as needed) to Google Apps and place the users in the same container as in LDAP. Very helpful to keep accounts organized the same way rather than manually moving them in Google Apps once created.
# Development Notes #

This page contains some notes for those who develop and update this modules code. Just a place to store these notes.

### Logging ###

Logging is very important for debugging. Proper logging is crucial to support this module.

  * ANY changes to Google Apps should be;
    1. Logged as a notice
    1. State the changes From: and To: _(except sensitive data, ex: passwords)_
  * Add a log message before a major process _(ex: Google Apps API call)_
  * However, don't "over-log" as the log file could become large
  * Any fatal messages should be thrown as a SimpleSAML\_Error\_Exception
    * SimpleSAMLphp will automatically log the Exceptions message
    * SimpleSAMLphp will then halt processing of the SAML request
  * Try to include some user specific data to help determine which request the message is for _(ex: username)_

### Branch Management ###

All development work, from which the working copy is pulled, should be based on the trunk. Any changes that need to be applied to a branch should then be merged. Unless there are situations where a change should only be applied to a particular branch and not trunk. Tags should never be modified as they are a source to the release code.

### Release Process ###

Details about the step for each new release to be added here.'

  1. Update the version numbers in the `definition.json` file in trunk
  1. Update the version number in the `lib/ApiHelper.php` file in trunk
  1. Merge the above changes and any other code changes to the proper branch(s)
  1. Make a new Tag with the correct version number (X.Y.Z) from the proper branch (not trunk)
  1. Export the new tag code into a `googleapps` folder
  1. Zip the `googleapps` folder and rename the zip to GoogleApps-X.Y.Z.zip (with proper version number)
  1. Upload the zip file as a New Download, label as `Featured` and `Type-Archive`
  1. Edit the previous version zip download and add the `Deprecated` label
  1. In `Issue Tracking` add a new `AppliesTo` label for the new version
  1. In `Issue Tracking` add new `Target` and `FixedIn` labels for the next possible version
  1. Add a posting to the Announcements group, CC simplesamlphp@googlegroups.com
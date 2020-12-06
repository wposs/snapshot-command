wposs/snapshot-command
======================

Backup / Restore WordPress installation

[![Build Status](https://travis-ci.org/wposs/snapshot-command.svg?branch=master)](https://travis-ci.org/wposs/snapshot-command)

Quick links: [Using](#using) | [Installing](#installing) | [Contributing](#contributing) | [Support](#support)

## Using

This package implements the following commands:

### wp snapshot

Backup / Restore WordPress installation

~~~
wp snapshot
~~~





### wp snapshot create

Creates a snapshot of WordPress installation.

~~~
wp snapshot create [--name=<name>] [--config-only]
~~~

**OPTIONS**

	[--name=<name>]
		Snapshot nice name.

	[--config-only]
		Store only configuration values WordPress version, Plugin/Theme version.

**EXAMPLES**

    # Create file/full snapshot.
    $ wp snapshot create

    # Create config snapshot. Will not work with 3rd party themes/plugins.
    $ wp snapshot create --config-only



### wp snapshot list

List all the backup snapshots.

~~~
wp snapshot list [--format=<format>]
~~~

**OPTIONS**
	[--format=<format>]
		Render output in a particular format.
		---
		default: table
		options:
		  - table
		  - csv
		  - ids
		  - json
		  - count
		  - yaml
		---
		**EXAMPLES**

    $ wp snapshot list



### wp snapshot inspect

Get information of the installation for given backup.

~~~
wp snapshot inspect <id>
~~~

**OPTIONS**

	<id>
		ID / Name of Snapshot to inspect.

**EXAMPLES**

    $ wp snapshot inspect 1



### wp snapshot restore

Restores a snapshot of WordPress installation.

~~~
wp snapshot restore <id>
~~~

**OPTIONS**

	<id>
		ID / Name of Snapshot to restore.

**EXAMPLES**

    $ wp snapshot restore 1



### wp snapshot delete

Delete a given snapshot.

~~~
wp snapshot delete <id>
~~~

**OPTIONS**

	<id>
		ID / Name of Snapshot to delete.

**EXAMPLES**

    $ wp snapshot delete 1



### wp snapshot configure

Configure credentials for external storage.

~~~
wp snapshot configure [--service=<service>]
~~~

Supported services are:
 - Amazon S3

**OPTIONS**

	[--service=<service>]
		Third party storage service to store backup zip.
		---
		default: aws
		options:
		  - aws
		---

**EXAMPLES**

    $ wp snapshot configure --service=aws



### wp snapshot push

Push the snapshot to an external storage service.

~~~
wp snapshot push <id> [--service=<service>] [--alias=<alias>]
~~~

	<id>
		ID / Name of Snapshot to inspect.

**OPTIONS**

	[--service=<service>]
		Third party storage service to store backup zip.
		---
		default: aws
		options:
		  - aws
		---

	[--alias=<alias>]
		Name of the remote where the snapshot should be pushed.

**EXAMPLES**

    # Push snapshot to AWS.
    $ wp snapshot push 1 --service=aws

    # Push snapshot to remote path via alias.
    $ wp snapshot push 2 --alias=@staging

## Installing

This package is included with WP-CLI itself, no additional installation necessary.

To install the latest version of this package over what's included in WP-CLI, run:

    wp package install git@github.com:wposs/snapshot-command.git

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

For a more thorough introduction, [check out WP-CLI's guide to contributing](https://make.wordpress.org/cli/handbook/contributing/). This package follows those policy and guidelines.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/wposs/snapshot-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/wposs/snapshot-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible. For more guidance, [review our bug report documentation](https://make.wordpress.org/cli/handbook/bug-reports/).

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/wposs/snapshot-command/issues/new) to discuss whether the feature is a good fit for the project.

Once you've decided to commit the time to seeing your pull request through, [please follow our guidelines for creating a pull request](https://make.wordpress.org/cli/handbook/pull-requests/) to make sure it's a pleasant experience. See "[Setting up](https://make.wordpress.org/cli/handbook/pull-requests/#setting-up)" for details specific to working on this package locally.

## Support

Github issues aren't for general support questions, but there are other venues you can try: https://wp-cli.org/#support


*This README.md is generated dynamically from the project's codebase using `wp scaffold package-readme` ([doc](https://github.com/wp-cli/scaffold-package-command#wp-scaffold-package-readme)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*

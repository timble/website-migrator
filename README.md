# Website Migration Tool

This tool helps you grab an existing Joomla website and prepare it for use in our CI workflows.

## Installation

* Clone this repository
* Install dependencies with `composer install`

## Usage

### Fetch sites

The following command will execute these steps:

* Download the files from the remote server to the `repository` directory
* Move the writables directories (such as images) to the `shared` directory
* Initializes the Git repository and commits all files
* Dump the database on the server and download. The tool does this by extracting the database connection details from `configuration.php` on the server.
* Initializes Capistrano for deployment.

The command needs the following arguments:

```
vendor/bin/robo migrate <project name> <ssh host> <ssh user> <remote path to documentroot> <local target directory> <ssh port> <list of writable directories>
```

Example, if you run the following command: 

```
vendor/bin/robo migrate www.site.com ssh.site.com username /var/www/www.site.com /Users/johndoe/Sites/www.site.com 22 images joomlatools-files
```

the `/Users/johndoe/Sites/www.site.com` directory will contain following files:

* `repository` : the Git repository ready to be pushed to origin.
* `shared` : The writable directories. In our example, this directory will now contain `images` and `joomlatools-files` subdirectories.
* `www.site.com.sql` : the SQL dump of the original site


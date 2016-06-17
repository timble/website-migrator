# {{title}}

## Deploying with Capistrano

### Installation

Install bundler if you haven't already:

    gem install bundler

Go to the root folder of the cloned repository and execute this command:

    bundle install

Make sure to add your private key identity to the authorization agent on your system. This is required so that Capistrano can 
use your local key to checkout the repository on the server.
Assuming `~/.ssh/id_rsa` is the SSH key you use on GitHub, run this command to add it: 

    ssh-add ~/.ssh/id_rsa

### Deploying

To deploy to production, execute the following:

    bundle exec cap production deploy

### Rolling back

If you want to rollback to the previous version, issue the rollback command:

    bundle exec cap production deploy:rollback
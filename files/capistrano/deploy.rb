set :application, '{{application}}'
set :repo_url, '{{repository}}'
set :deploy_to, '/var/www/{{application}}/capistrano'

set :log_level, (ENV['LOG_LEVEL'] || :info)
set :pty, false

set :linked_dirs, %w{ {{linked_dirs}} }
set :linked_files, %w{ {{linked_files}} }
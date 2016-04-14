set :stage, :production
set :branch, "master"

server '{{server}}', user: '{{user}}', port: {{port}}, roles: %w{web app db}, primary: true

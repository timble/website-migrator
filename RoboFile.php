<?php
class RoboFile extends \Robo\Tasks
{
    public function migrate($project_name, $host, $user, $remote_path, $target = '', $ssh_port = 22, array $writables = array('images'))
    {
        $project_name = preg_replace('/[^a-zA-Z0-9\.\-\_]+/', '', $project_name);

        if (empty($target)) {
            $target = realpath(__DIR__) . '/' . $project_name;
        }

        $this->stopOnFail(true);

        if (substr($remote_path, -1) != '/') {
           $remote_path .= '/';
        }

        if (!file_exists($target))
        {
            $this->taskFileSystemStack()
                    ->mkdir($target)
                    ->run();
        }

        $repository = $target . '/repository';
        $files      = $target . '/shared';

        $create = $this->taskFileSystemStack();

        foreach (array($repository, $files) as $dir)
        {
            if (!file_exists($dir)) {
               $create->mkdir($dir);
            }
        }

        $create->run();

        // Copy the files locally (exclude configuration.php/cache/tmp/logs)
        $this->rsync($host, $user, $remote_path, $target, $ssh_port, $writables);

        // Set aside the writable directories (images, ..) and add to .gitignore
        $ignores = $this->taskWriteToFile($repository.'/.gitignore');

        if (!file_exists($repository.'/.gitignore'))
        {
            $ignores->line('/cache')
                    ->line('/administrator/cache')
                    ->line('/tmp')
                    ->line('/logs')
                    ->line('/configuration.php');
        }
        else $ignores->append();

        foreach ($writables as $writable)
        {
            $from = $repository . '/'.$writable;
            $to   = $files . '/' . $writable;

            $ignores->line('/'.$writable);

            if (!file_exists($from))
            {
                $this->say('Warning: directory ' . $writable . ' does not exist!');

                continue;
            }
        }

        $ignores->run();

        // Init git repo and commit the files
        $this->taskGitStack()
            ->dir($repository)
            ->exec('init')
            ->exec('remote add origin git@github.com:cta-int/' . $project_name . '.git')
            ->add('-A')
            ->commit('Initial commit')
            ->run();

        // Fetch database
        $tmp = tempnam('/tmp/', $project_name.'-');
        $this->taskRsync()
            ->fromUser($user)
            ->fromHost($host)
            ->option('copy-links')
            ->fromPath($remote_path.'/configuration.php')
            ->toPath($tmp)
            ->remoteShell("ssh -p $ssh_port")
            ->run();

        require $tmp;

        $config = new JConfig();

        unlink($tmp);

        $mysqldump = $this->taskExec('mysqldump')
                        ->arg('-h' . $config->host)
                        ->arg('-u' . escapeshellarg($config->user))
                        ->arg('-p' . escapeshellarg($config->password))
                        ->arg($config->db . ' > ' . $project_name . '.sql');

        $this->taskSshExec($host, $user)
            ->port($ssh_port)
            ->remoteDir($remote_path)
            ->exec($mysqldump)
            ->run();

        $this->taskRsync()
            ->fromUser($user)
            ->fromHost($host)
            ->fromPath($remote_path.'/' . $project_name . '.sql')
            ->toPath($target)
            ->option('copy-links')
            ->remoteShell("ssh -p $ssh_port")
            ->run();

        $this->taskSshExec($host, $user)
            ->port($ssh_port)
            ->remoteDir($remote_path)
            ->exec('rm -f ' . $project_name . '.sql')
            ->run();

        // Init Capistrano
        $this->taskWriteToFile($repository.'/Gemfile')
            ->line('source \'https://rubygems.org\'')
            ->line('')
            ->line('gem \'capistrano\', \'~> 3.3.0\', require: false, group: :development')
            ->run();

        $git_repo = 'git@github.com:timble/' . $project_name . '.git';
        $this->taskExec('bundle')
                ->arg('install')
                ->dir($repository)
                ->run();

        $this->taskFileSystemStack()
            ->mkdir($repository.'/config')
            ->mkdir($repository.'/config/deploy')
            ->copy(__DIR__.'/files/capistrano/deploy.rb', $repository.'/config/deploy.rb')
            ->copy(__DIR__.'/files/capistrano/Capfile', $repository.'/Capfile')
            ->copy(__DIR__.'/files/capistrano/production.rb', $repository.'/config/deploy/production.rb')
            ->copy(__DIR__.'/files/capistrano/README.md', $repository.'/README.md')
            ->run();

        $linked_dirs = array_unique(array_merge($writables, array('tmp', 'logs', 'cache', 'administrator/cache')));

        $this->taskReplaceInFile($repository.'/config/deploy.rb')
            ->from(array('{{application}}', '{{repository}}', '{{linked_dirs}}', '{{linked_files}}'))
            ->to(array($project_name, $git_repo, implode(' ', $linked_dirs), 'configuration.php'))
            ->run();

        $this->taskReplaceInFile($repository.'/config/deploy/production.rb')
            ->from(array('{{server}}', '{{user}}', '{{port}}'))
            ->to(array('127.0.0.1', 'deploy', '22'))
            ->run();

        $this->taskWriteToFile($repository.'/.gitignore')
            ->append()
            ->line('/.capistrano')
            ->run();

        $this->taskGitStack()
            ->dir($repository)
            ->add('-A')
            ->commit('Setup Capistrano')
            ->run();

        $this->say('Done!');
    }

    public function rsync($host, $user, $remote_path, $target = '', $ssh_port = 22, array $writables = array('images'))
    {
        if (empty($target))
        {
            $this->say('Error: Target is empty');
            return;
        }

        $this->stopOnFail(true);

        if (substr($remote_path, -1) != '/') {
           $remote_path .= '/';
        }

        if (!file_exists($target))
        {
            $this->taskFileSystemStack()
                    ->mkdir($target)
                    ->run();
        }

        $repository = rtrim($target, '/') . '/repository';
        $shared     = rtrim($target, '/') . '/shared';

        $create = $this->taskFileSystemStack();

        foreach (array($repository, $shared) as $dir)
        {
            if (!file_exists($dir)) {
               $create->mkdir($dir);
            }
        }

        $create->run();

        // Copy the files locally (exclude configuration.php/cache/tmp/logs) to repository directory
        $exclude = array('/configuration.php', '/cache', '/logs', '/tmp', '/administrator/cache/');

        // Exclude writables directory
        foreach ($writables as $writable) {
            $exclude[] = '/'.$writable;
        }

        $this->say("Copying files to repository directory");

        $this->taskRsync()
            ->fromUser($user)
            ->fromHost($host)
            ->fromPath($remote_path)
            ->toPath($repository)
            ->remoteShell("ssh -p $ssh_port")
            ->option('copy-links')
            ->recursive()
            ->excludeVcs()
            ->exclude($exclude)
            ->checksum()
            ->wholeFile()
            ->progress()
            ->humanReadable()
            ->stats()
            // ->verbose()
            // ->dryRun()
            ->run();

        // Copy writables directory to shared directory
        $exclude = array('*');
        $include = array('*/');

        // Include writables
        foreach ($writables as $writable) {
            $include[] = '/'.$writable.'/**';
        }

        $this->say("Copying files to shared directory");

        $this->taskRsync()
            ->fromUser($user)
            ->fromHost($host)
            ->fromPath($remote_path)
            ->toPath($shared)
            ->remoteShell("ssh -p $ssh_port")
            ->option('copy-links')
            ->recursive()
            ->includeFilter($include)
            ->excludeVcs()
            ->exclude($exclude)
            ->option('prune-empty-dirs')
            ->checksum()
            ->wholeFile()
            ->progress()
            ->humanReadable()
            ->stats()
            // ->verbose()
            // ->dryRun()
            ->run();
    }
}
<?php
class RoboFile extends \Robo\Tasks
{
    public function migrate($project_name, $host, $user, $remote_path, $target = '', $ssh_port = 22, array $writables = array('images'))
    {
        if (empty($target)) {
            $target = realpath(__DIR__) . '/' . $project_name;
        }

        $this->stopOnFail(true);

        if (substr($remote_path, -1) != '/') {
           $remote_path .= '/';
        }

        if (!file_exists($target)) {
            throw new Exception('Target directory ' . $target . ' does not exist');
        }

        $repository = $target . '/repository';
        $files      = $target . '/files';

        $create = $this->taskFileSystemStack();

        foreach (array($repository, $files) as $dir)
        {
            if (!file_exists($dir)) {
               $create->mkdir($dir);
            }
        }

        $create->run();

        // Copy the files locally (exclude configuration.php/cache/tmp/logs)
        $this->taskRsync()
            ->fromUser($user)
            ->fromHost($host)
            ->fromPath($remote_path)
            ->toPath($repository)
            ->remoteShell("ssh -p $ssh_port")
            ->recursive()
            ->excludeVcs()
            ->exclude(array('configuration.php', 'cache/', 'logs/', 'tmp/', 'administrator/cache/'))
            ->checksum()
            ->wholeFile()
            ->progress()
            ->humanReadable()
            ->stats()
            //->verbose()
            //->dryRun()
            ->run();

        // Set aside the writable directories (images, ..) and add to .gitignore
        $moves   = $this->taskFileSystemStack();
        $ignores = $this->taskWriteToFile($repository.'/.gitignore');

        if (!file_exists($repository.'/.gitignore'))
        {
            $ignores->line('/cache')
                    ->line('/administrator/cache')
                    ->line('/tmp')
                    ->line('/logs');
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

            $moves->rename($from, $to);
        }

        $moves->run();
        $ignores->run();

        // Init git repo and commit the files
        $git_email = $this->askDefault('Git author e-mail', get_current_user() . '@timble.net');
        $git_repo  = $this->askDefault('Git repository', 'git@github.com:timble/' . $project_name . '.git');

        $this->taskGitStack()
            ->dir($repository)
            ->exec('init')
            ->exec('config user.email ' . $git_email)
            ->exec('remote add origin ' . $git_repo)
            ->add('-A')
            ->commit('Initial commit')
            ->push('origin', 'master')
            ->run();

        // Fetch database
        $tmp = tempnam('/tmp/', $project_name.'-');
        $this->taskRsync()
            ->fromUser($user)
            ->fromHost($host)
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
            ->remoteShell("ssh -p $ssh_port")
            ->run();

        $this->taskSshExec($host, $user)
            ->port($ssh_port)
            ->remoteDir($remote_path)
            ->exec('rm -f ' . $project_name . '.sql')
            ->run();

        // Init Capistrano
    }
}
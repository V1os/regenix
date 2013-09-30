<?php
namespace regenix\console\commands;


use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use regenix\Regenix;
use regenix\cache\Cache;
use regenix\console\BackgroundProcess;
use regenix\console\ConsoleCommand;
use regenix\console\RegenixCommand;
use regenix\console\RegenixProcess;
use regenix\lang\CoreException;
use regenix\lang\File;
use regenix\lang\String;
use regenix\logger\Logger;
use regenix\scheduler\Scheduler;
use regenix\scheduler\SchedulerTask;

class SchedulerCommand extends RegenixCommand {

    const CHECK_APP_LOADED = true;

    protected function configure() {
        $this
            ->setName('scheduler')
            ->setDescription('Start scheduler')
            ->addOption(
                'daemon',
                null,
                InputOption::VALUE_NONE
            )
            ->addOption(
                'interval',
                 null,
                InputOption::VALUE_IS_ARRAY | InputOption::VALUE_OPTIONAL,
                'Set interval in seconds for scheduler loop, default 2 sec'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output){
        $console = $this->getApplication();

        $interval = $input->getOption('interval');
        $interval = (int)$interval[0];
        if (!$interval)
            $interval = 2;

        $pid = getmygid();
        $name = $console->app->getName();

        $this->writeln('Scheduler (%s) is started with update interval = ' . $interval . 's', $name);
        $scheduler = new Scheduler($console->app->getName());
        $tasks = $scheduler->getTasks();
        $this->writeln('    Task count: %s', sizeof($tasks));

        if ($input->getOption('daemon')){
            $process = new Process('WMIC path win32_process get Processid,Commandline');
            $process->run(function($err, $out){
                $this->writeln($out);
            });

            /*
            $process = new BackgroundProcess('regenix scheduler');
            $process->start();
            $pid = '?';*/

            $this->writeln('    Scheduler PID: %s', $pid);
            $this->writeln();
            $this->writeln('  (!) Scheduler is working in background, to stop it use `regenix scheduler stop`');
        } else {
            $this->writeln('    Scheduler PID: ' . getmygid());
        }

        $file = new File($console->app->getLogPath() . '/scheduler.pid');
        if (!$file->exists())
            $file->getParentFile()->mkdirs();
        else {

        }

        $file->open('w');
        $file->write($pid);
        $file->close();
        $this->writeln();

        if (sizeof($tasks) === 0){
            $file->delete();
            throw new CoreException('Can`t run scheduler with empty task list');
        } else {
            if (!$input->getOption('daemon')){
                $scheduler->run($interval, function(){

                }, function(\Exception $e, SchedulerTask $task){
                    $this->writeln('[%s] %s', get_class($e), get_class($task));
                    $this->writeln('    message: %s', $e->getMessage());
                    $this->writeln('    %s (line: %s)', $e->getFile(), $e->getLine());
                    $this->writeln('    trace:');
                    $this->writeln($e->getTraceAsString());

                    Logger::error('[%s] %s (msg: %s, %s at %s)',
                        get_class($e), get_class($task), $e->getMessage(), $e->getFile(), $e->getLine());
                });
                $file->delete();
            }
        }
    }
}
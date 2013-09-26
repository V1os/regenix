<?php
namespace regenix\console\commands;

use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Process\Process;
use regenix\console\RegenixCommand;
use regenix\deps\ConnectException;
use regenix\deps\DependencyDownloadException;
use regenix\deps\DependencyNotFoundException;
use regenix\deps\Origin;
use regenix\deps\Repository;
use regenix\exceptions\HttpException;
use regenix\lang\CoreException;
use regenix\lang\File;
use regenix\lang\String;

class DepsCommand extends RegenixCommand {

    const CHECK_APP_LOADED = true;

    protected function configure() {
        $this
            ->setName('deps')
            ->setDescription('Manage dependencies of a current app, example: deps update')
            ->addArgument(
                'task',
                InputArgument::OPTIONAL | InputArgument::IS_ARRAY,
                'additional propel command'
            )
            ->addOption(
                'force',
                InputOption::VALUE_NONE
            );
    }

    /** @var Repository */
    protected $repository;

    protected function execute(InputInterface $input, OutputInterface $output){
        $console = $this->getApplication();
        $console->app->loadDeps();
        $this->repository = new Repository($console->app->deps);

        if($task = $input->getArgument('task')){
            $sub = 'sub_' . $task[0];
            if (!method_exists($this, $sub)){
                $this->writeln('The command `deps %s` not found', $task[0]);
                exit(1);
            }
            $this->{$sub}();
            return;
        }

        $this->writeln('Dependencies of `%s`:', $console->app->getName());
        $this->writeln();

        $this->renderDeps('Assets', 'assets', $console->app->deps['assets']);
        $this->writeln();
        $this->renderDeps('Modules', 'modules', $console->app->deps['modules']);
        $this->writeln();
        $this->renderComposerDeps('Composer', $console->app->deps['composer']['require']);

        $this->checkConflicts();
    }

    protected function renderDep($group, $pattern, $level = 0){
        $find = $this->repository->findLocalVersion($group, $pattern);
        $status = 'ok';
        if (!$find)
            $status = 'not found';
        else {
            if (!$this->repository->isValid($group, $find['version'])){
                $status = 'invalid';
            }
        }
        $this->writeln('        %s- %s %s (use v%s) [%s]',
            str_repeat('-', $level), $group, $pattern, $find ? $find['version'] : '?', $status);

        if ($find){
            $meta = $this->repository->getLocalMeta($group, $find['version']);
            if ($meta && is_array($meta['deps'])){
                foreach($meta['deps'] as $gr => $p){
                    $this->renderDep($gr, $p['version'], $level + 1);
                }
            }
        }
    }

    protected function renderDeps($label, $env, $deps){
        $this->writeln('    %s:', $label);
        $this->writeln();
        if(sizeof($deps)){
            $this->repository->setEnv($env);
            foreach($deps as $group => $dep){
                $this->renderDep($group, $dep['version']);
            }
        } else {
            $this->writeln('        * empty');
        }

        $this->writeln();
    }

    protected function renderComposerDeps($label, $deps){
        $this->writeln('    %s:', $label);
        $this->writeln();
        $console = $this->getApplication();
        if(sizeof($deps)){
            foreach($deps as $group => $dep){
                $status = 'ok';
                if (!is_dir($vendorDir = $console->app->getPath() . 'vendor/' . $group . '/'))
                    $status = 'not installed';

                $this->writeln('        - %s (%s) [%s]', $group, $dep, $status);
            }
        } else {
            $this->writeln('        * empty');
        }

        $this->writeln();
    }

    protected function checkConflicts($menv = false){
        $vars = array('assets', 'modules');
        foreach($vars as $env){
            if ($menv && $menv !== $env) continue;

            $all = $this->repository->getAll($env, false);
            foreach($all as $gr => $versions){
                if (sizeof($versions) > 1){
                    $this->writeln('[!!!] version conflict `%s/%s`: %s', $env, $gr, implode(' or ', array_keys($versions)));
                    $this->writeln();
                }
            }
        }
    }

    protected function update($env, $group, $dep, $step = 0){
        try {
            $local = $this->repository->findLocalVersion($group, $dep['version']);
            if ($local['skip']){
                //$this->writeln('[ok, %s skip]', $local['version']);
                return;
            }

            $this->write(($step ? '  try '.$step.': ' : '->').' %s/%s/%s', $env, $group, $dep['version']);
            if ($dep['skip']){
                $this->writeln('[manual skip]');
                return;
            }

            $result = $this->repository->download($group, $dep['version'], $this->input->getOption('force'));
            if (!$this->repository->isValid($group, $result['version'])){
                $this->writeln('[error, download invalid]');
                if ($step < 5){
                    $this->update($env, $group, $dep, $step + 1);
                } else
                    throw new DependencyDownloadException($env, $group, $result['version']);
            }

            if ($local && $local['version'] != $result['version'])
                $this->writeln('[ok upgrade, %s -> %s]', $local['version'], $result['version']);
            else
                $this->writeln('[ok, %s %s]', $result['version'], $result['skip'] ? 'skip' : 'downloaded');

            if (is_array($result['deps']) && sizeof($result['deps'])){
                foreach($result['deps'] as $gr => $el){
                    if (strpos($group, '/') !== false){
                        $group = explode('/', $group);
                        $myEnv = $group[0];
                        $group = $group[1];
                    } else {
                        $myEnv = $env;
                    }

                    try {
                        $this->update($myEnv, $gr, $el);
                    } catch (\Exception $e){
                        throw new \Exception($e->getMessage());
                    }
                }
            }

        } catch (DependencyNotFoundException $e){
            $this->writeln('[error, not found]');
        } catch (DependencyDownloadException $e){
            if ($step < 5){
                $this->writeln('[error, can`t download], repeat ->');
                $this->update($env, $group, $dep, $step + 1);
            } else
                throw $e;
        } catch (ConnectException $e){
            if ($step < 2){
                $this->writeln('[error, can`t connect]');
                $this->update($env, $group, $dep, $step + 1);
            } else
                throw $e;
        }

    }

    private static function recursiveDelete($str){
        if(is_file($str)){
            return @unlink($str);
        }
        elseif(is_dir($str)){
            $scan = glob(rtrim($str,'/').'/*');
            foreach($scan as $index=>$path){
                self::recursiveDelete($path);
            }
            return @rmdir($str);
        }
    }

    protected function sub_clean(){
        $this->writeln('Remove all dependencies: ..');
        $this->writeln();
        $envs = array('assets', 'modules');

        foreach($envs as $env){
            $this->repository->setEnv($env);
            foreach($this->repository->findAll() as $group => $versions){
                $this->writeln('    Remove `%s`:', $group);
                foreach($versions as $version){
                    $dir = ROOT . $env . '/' . $group . '~' . $version . '/';
                    self::recursiveDelete($dir);
                    $this->writeln('       - v%s [%s]', $version, is_dir($dir) ? 'error' : 'success');
                }
                $this->writeln();
            }
        }
        $this->writeln('[success] Remove all done.');
    }

    protected function sub_find(){
        $task = $this->input->getArgument('task');

        $group = $task[1];
        if (!$group || sizeof(explode('/', $group)) !== 2){
            $this->writeln('[error] Group invalid name, try example: `regenix deps find assets/jquery`');
            exit(1);
        }

        $group = explode('/', $group);
        $this->repository->setEnv($group[0]);

        $this->writeln('Finding `%s/%s` in repository:', $group[0], $group[1]);
        $this->writeln('    %s', $this->repository->getAddress());
        $this->writeln();
        $meta = null;
        try {
            $meta = $this->repository->getMeta($group[1]);
        } catch (\Exception $e){
            try {
                if ($e instanceof HttpException){
                    throw $e;
                }

                $meta = $this->repository->getMeta($group[1]);
            } catch(HttpException $ne){
                $this->writeln('    - not found');
                exit(1);
            } catch (\Exception $e2){
                $this->writeln('[error] Can`t connect to repository');
                $this->writeln('    Last exception: %s', $e2->getMessage());
                exit(1);
            }
        }

        foreach($meta as $version => $info){
            $this->writeln('    - v' . $version);
        }
    }

    protected function sub_update(){
        $variants = array('assets', 'modules');
        $start = microtime(1);

        $command = $this->getApplication();
        $task = $this->input->getArgument('task');

        if ($task[1]){
            $variant = $task[1];
            if (!in_array($variant, $variants, true)){
                $this->writeln('It cannot find `%s` group dependencies', $variant);
                return;
            }
        }

        try {
            foreach($variants as $env){
                if ($variant){
                    if ($variant !== $env){
                        continue;
                    }
                }

                $this->repository->setEnv($env);
                $deps = $command->app->deps[$env];
                foreach((array)$deps as $group => $dep){
                    $this->update($env, $group, $dep);
                }
                $this->writeln();
            }

            $this->checkConflicts();

            $composer = $command->app->deps['composer'];
            if ($composer && $composer['require']){
                $this->writeln('Composer starting ...');
                $json = $composer;
                $file = new File($command->app->getPath() . 'composer.json');

                $file->open('w');
                    $file->write(json_encode($json));
                $file->close();

                $cmd = String::format('cd "%s" && composer update', $command->app->getPath());
                $process = new Process($cmd);
                $result = $process->run(function($type, $out){
                    $this->writeln($out);
                });
                if ($result > 0){
                    throw new CoreException('Composer has returned error code: %s', $result);
                }

                $file->delete();
                $this->writeln();
            }

            $time = round((microtime(1) - $start));
            $this->writeln('[success] Total time %s s, completed %s', $time, date('d.m.Y h:i:s'));

        } catch (\Exception $e){
            $this->writeln();
            $this->writeln('[error] Can`t update dependencies.');
            $this->writeln('    Try again run `update` or `update --force` command');
            $this->writeln();
            $this->writeln('Last exception: %s', $e->getMessage());
            exit(1);
        }
    }
}
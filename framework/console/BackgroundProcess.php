<?php
namespace regenix\console;

use Symfony\Component\Process\Process;
use regenix\lang\String;

class BackgroundProcess {

    private $commandLine;

    public function __construct($commandline) {
        $this->setCommandline($commandline);
    }

    public function setCommandline($commandline) {
        $this->commandLine = $commandline;

    }

    public function start(){
        $my = String::random(30);
        $commandline = $this->commandLine;
        if (defined('PHP_WINDOWS_VERSION_BUILD')){
            $res = (popen('start "' .$my. '" /b ' . $commandline, 'r'));
            print_r($my);
            pclose($res);
        } else {
            return exec('nohup ' . $commandline . ' > /dev/null & echo $!');
        }
    }

    public function getCommandLine(){
        return $this->commandLine;
    }
}
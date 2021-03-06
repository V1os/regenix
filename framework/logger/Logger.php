<?php

namespace regenix\logger;

use regenix\Regenix;
use regenix\Application;
use regenix\config\PropertiesConfiguration;
use regenix\lang\CoreException;
use regenix\lang\File;
use regenix\lang\FileIOException;
use regenix\lang\IClassInitialization;
use regenix\lang\String;

abstract class Logger implements IClassInitialization {

    const type = __CLASS__;

        const LEVEL_FATAL = 100;
        const LEVEL_ERROR = 99;
        const LEVEL_WARN  = 98;
        const LEVEL_INFO  = 97;
        const LEVEL_DEBUG = 96;

    protected static function writeLog($level, $args){
        foreach(self::$handlers as $info){
            if ( $info['level'] <= $level )
                $info['handler']->writeLog($level, $args);
        }
    }

    /**
     * @param int $level
     * @return string
     */
    public static function getLevelString($level){
        switch($level){
            case self::LEVEL_FATAL: return "fatal";
            case self::LEVEL_ERROR: return "error";
            case self::LEVEL_WARN: return "warn";
            case self::LEVEL_INFO: return "info";
            case self::LEVEL_DEBUG: return "debug";
        }
    }

    public static function getLevelOrd($level){
        switch(strtolower(trim($level))){
            case 'fatal': return self::LEVEL_FATAL;
            case 'error': return self::LEVEL_ERROR;
            case 'warn':  return self::LEVEL_INFO;
            case 'info': return self::LEVEL_INFO;
            case 'debug': return self::LEVEL_DEBUG;
            default:
                throw new CoreException('Logger level `%s` unknown', $level);
        }
    }

    public static function fatal($message){
        self::writeLog(self::LEVEL_FATAL, func_get_args());
    }

    public static function error($message){
        self::writeLog(self::LEVEL_ERROR, func_get_args());
    }

    public static function warn($message){
        self::writeLog(self::LEVEL_WARN, func_get_args());
    }

    public static function info($message){
        self::writeLog(self::LEVEL_INFO, func_get_args());
    }

    public static function debug($message){
        self::writeLog(self::LEVEL_DEBUG, func_get_args());
    }

    /**
     * @var LoggerHandler[]
     */
    private static $handlers = array();

    /**
     * @param int $level
     * @param LoggerHandler $handler
     */
    public static function registerHandler($level, LoggerHandler $handler){
        self::$handlers[] = array('level' => $level, 'handler' => $handler);
    }

    /**
     * clear all handlers
     */
    public static function clearHandlers(){
        self::$handlers = array();
    }

    /**
     * @param null|Application|array $configOrApp
     * @throws \regenix\lang\CoreException
     */
    public static function initialize($configOrApp = null){
        if ($configOrApp && $configOrApp instanceof Application)
            $app = $configOrApp;
        else
            $app = Regenix::app();

        if ($app){
            $enable   = $app->config->getBoolean('logger.enable', true);
            $division = $app->config->getBoolean('logger.division', true);
            $level    = $app->config->getString('logger.level', 'info');
            $logPath  = $app->getLogPath();
        } else
            $enable = false;

        if ($configOrApp && is_array($configOrApp)){
            $enable = true;
            if (isset($configOrApp['division']))
                $division = $configOrApp['division'];
            else if (!isset($division))
                $division = true;

            if (isset($configOrApp['level']))
                $level = $configOrApp['level'];
            else if (!isset($level))
                $level = 'info';

            if (isset($configOrApp['logpath']))
                $logPath = $configOrApp['logpath'];
            else if (!isset($logPath))
                throw new CoreException('Please, specify log path for Logger');
        }

        if ( $enable ){
            self::registerHandler(self::getLevelOrd($level),
                new LoggerDefaultHandler($logPath, $division));
        }
    }
}

abstract class LoggerHandler {
    abstract public function writeLog($level, array $args);
}

class LoggerDefaultHandler extends LoggerHandler {

    private $fp;
    private $fps = array();
    private $division;
    private $logPath;

    public function __construct($logPath, $division = true){
        $this->division = $division;
        $file     = $logPath . 'system.log';
        $this->logPath = $logPath;
        $path     = new File(dirname($file));

        if (!$path->exists())
            if (!$path->mkdirs()){
                throw new CoreException('Can`t create `%s` directory for logs', $path->getPath());
            }
        $this->fp = fopen($file, 'a+');
    }

    public function __destruct(){
        fclose($this->fp);
    }

    public function writeLog($level, array $args){
        $message = String::formatArgs($args[0], array_slice($args, 1));
        $time = @date("[Y/M/d H:i:s]");
        $lv = Logger::getLevelString($level);
        $out = "$time($lv): $message" . PHP_EOL;
        fwrite($this->fp, $out);
        if ($this->division){
            $fp = $this->fps[ $level ];
            if (!$fp){
                $fp = $this->fps[ $level ] = fopen($this->logPath . $lv . '.log', 'a+');
            }
            fwrite($fp, "$time: $message" . PHP_EOL);
        }
    }
}
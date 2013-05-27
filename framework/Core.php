<?php
namespace framework {

    use framework\cache\SystemCache;
    use framework\config\ConfigurationReadException;
    use framework\config\PropertiesConfiguration;
    use framework\deps\Repository;
    use framework\exceptions\JsonFileException;
    use framework\exceptions\TypeException;
    use framework\lang\FileNotFoundException;
    use framework\lang\CoreException;
    use framework\exceptions\CoreStrictException;
    use framework\exceptions\HttpException;
    use framework\lang\File;
    use framework\lang\ClassScanner;
    use framework\lang\String;
    use framework\libs\Captcha;
    use framework\logger\Logger;
    use framework\modules\AbstractModule;
    use framework\mvc\Controller;
    use framework\mvc\Request;
    use framework\mvc\Response;
    use framework\mvc\Result;
    use framework\mvc\URL;
    use framework\mvc\route\Router;
    use framework\mvc\route\RouterConfiguration;
    use framework\mvc\session\APCSession;
    use framework\mvc\template\BaseTemplate;
    use framework\mvc\template\TemplateLoader;

abstract class Core {

    const type = __CLASS__;
    
    /** @var string */
    public static $tempDir;

    /** @var Project[] */
    private static $projects = array();

    public static function getVersion(){
        return '0.6';
    }

    public static function init($rootDir, $inWeb = true){
        $rootDir = str_replace(DIRECTORY_SEPARATOR, '/', realpath($rootDir));
        if (substr($rootDir, -1) !== '/')
            $rootDir .= '/';

        define('ROOT', $rootDir);

        $frameworkDir = str_replace(DIRECTORY_SEPARATOR, '/', realpath(__DIR__)) . '/';
        define('REGENIX_ROOT', $frameworkDir);

        require REGENIX_ROOT . 'lang/PHP.php';

        // TODO
        ini_set('display_errors', 'Off');
        error_reporting(E_ALL ^ E_NOTICE);
        set_include_path($rootDir);
        self::$tempDir = sys_get_temp_dir() . '/';

        unset($_POST, $_GET, $_REQUEST);

        // register class loader
        ClassScanner::init($rootDir, array(REGENIX_ROOT));

        if ($inWeb){
            self::_registerTriggers();
            self::_deploy();

            self::_registerProjects();
            self::_registerCurrentProject();

            if (!Project::current())
                register_shutdown_function(array(Core::type, 'shutdown'), null);
        } else {
            set_time_limit(0);
            header_remove();

            define('IS_DEV', true);
            define('IS_PROD', false);
            define('APP_MODE', 'dev');

            defined('STDIN') or define('STDIN', fopen('php://stdin', 'r'));
            define('CONSOLE_STDOUT', fopen('php://stdout', 'w+'));
        }
    }

    public static function initConsole($rootDir){
        self::init($rootDir, false);
    }

    private static function _registerTriggers(){
        SDK::registerTrigger('beforeRequest');
        SDK::registerTrigger('afterRequest');
        SDK::registerTrigger('finallyRequest');
        SDK::registerTrigger('registerTemplateEngine');
    }

    private static function _deployZip($zipFile){
        $name   = basename($zipFile, '.zip');
        $appDir = Project::getSrcDir() . $name . '/';

        // check directory exists
        if (file_exists($appDir)){
            $dir = new File($appDir);
            $dir->delete();
            $dir->mkdirs();
        }

        $zip = new \ZipArchive();
        if ($zip->open($zipFile)){
            $result = $zip->extractTo($appDir);
            if (!$result)
                throw CoreException::formated('Can`t extract zip archive "%s" in apps directory', basename($zipFile));

            $zip->close();
        }

        $file = new File($zipFile);
        $file->delete();
    }

    private static function _deploy(){
        foreach (glob(Project::getSrcDir() . "*.zip") as $zipFile) {
            self::_deployZip($zipFile);
        }
    }

    private static function _registerProjects(){
        $dirs = scandir(Project::getSrcDir());
        $root = Project::getSrcDir();
        foreach ($dirs as $dir){
            if ($dir == '.' || $dir == '..') continue;
            if (is_dir($root . $dir))
                self::$projects[ $dir ] = new Project( $dir );
        }
    }

    private static function _registerCurrentProject(){
        /** 
         * @var Project $project
         */
        foreach (self::$projects as $project){
            $url = $project->findCurrentPath();
            if ( $url ){
                register_shutdown_function(array(Core::type, 'shutdown'), $project);
                $project->setUriPath( $url );
                $project->register();
                return;
            }
        }
        
        throw new HttpException(500, "Can't find project for current request");
    }
    
    public static function processRoute(){
        $project = Project::current();
        $router  = $project->router;
        
        $request = Request::current();
        $request->setBasePath( $project->getUriPath() );
                
        $router->route($request);

        try {
            if (!$router->action){
                throw new HttpException(404, 'Not found');
            }

            // TODO optimize ?
            $tmp = explode('.', $router->action);
            $controllerClass = implode('\\', array_slice($tmp, 0, -1));
            $actionMethod    = $tmp[ sizeof($tmp) - 1 ];

            /** @var $controller Controller */
            $controller = new $controllerClass;
            $controller->actionMethod = $actionMethod;
            $controller->routeArgs    = $router->args;
            try {
                $reflection = new \ReflectionMethod($controller, $actionMethod);
                $controller->actionMethodReflection = $reflection;
            } catch(\ReflectionException $e){
                throw new HttpException(404, $e->getMessage());
            }

            $declClass = $reflection->getDeclaringClass();
            
            if ( $declClass->isAbstract() ){
                throw CoreException::formated('Can\'t use "%s.%s()" as action method', $controllerClass, $actionMethod);
            }

            SDK::trigger('beforeRequest', array($controller));
            
            $controller->callBefore();
            $return = $router->invokeMethod($controller, $reflection);

            // if use return statement
            $controller->callReturn($return);

        } catch (Result $result){
            $response = $result->getResponse();
        } catch (\Exception $e){
            
            if ( $controller ){
                try {
                    if ($e instanceof HttpException){
                        $controller->callHttpException($e);
                    }

                    // if no result, do:
                    $controller->callException($e);
                } catch (Result $result){
                    /** @var $responseErr Response */
                    $responseErr = $result->getResponse();
                }
            }
            
            if ( !$responseErr )
                throw $e;
            else {
                $response = $responseErr;
                if ($e instanceof HttpException)
                    $response->setStatus($e->getStatus());
            }
        }
        
        if ( !$responseErr ){
            $controller->callAfter();
            SDK::trigger('afterRequest', array($controller));
        }
        
        if ( !$response ){
            throw CoreException::formated('Unknown type of action `%s.%s()` result for response', $controllerClass, $actionMethod);
        }
        
        $response->send();
        $controller->callFinally();
        SDK::trigger('finallyRequest', array($controller));
    }

    private static function catchError($error, $logPath){
        $title = 'Fatal Error';

        switch($error['type']){
            case E_PARSE: $title = 'Parse Error'; break;
            case E_COMPILE_ERROR: $title = 'Compiler Error'; break;
            case E_CORE_ERROR: $title = 'Core Error'; break;
        }

        $file = str_replace('\\', '/', $error['file']);
        $error['line'] += CoreException::getErrorOffsetLine($file);
        $file = $error['file'] = CoreException::getErrorFile($file);
        $file = str_replace(str_replace('\\', '/', ROOT), '', $file);

        $source = null;
        if (IS_DEV && file_exists($error['file']) && is_readable($error['file']) ){
            $fp = fopen($error['file'], 'r');
            $n  = 1;
            $source = array();
            while($line = fgets($fp, 4096)){
                if ( $n > $error['line'] - 7 && $n < $error['line'] + 7 ){
                    $source[$n] = $line;
                }
                if ( $n > $error['line'] + 7 )
                    break;
                $n++;
            }
        }

        $hash = substr(md5(rand()), 12);
        if ($logPath){
            $can = true;
            if (!is_dir($logPath))
                $can = mkdir($logPath, 0777, true);

            if ($can){
                $fp = fopen($logPath . 'fail.log', 'a+');
                $time = date("[Y/M/d H:i:s]");
                    fwrite($fp,  "[$hash]$time" . PHP_EOL . "($title): $error[message]" . PHP_EOL);
                    fwrite($fp, $file . ' (' . $error['line'] . ')'.PHP_EOL . PHP_EOL);
                fclose($fp);
            }
        }
        include ROOT . 'framework/views/system/errors/fatal.phtml';
    }

    private static function catchAny(\Exception $e){
        if ( $e instanceof HttpException ){
            $template = TemplateLoader::load('errors/' . $e->getStatus() . '.html');
            $template->putArgs(array('e' => $e));

            $response = new Response();
            $response->setStatus($e->getStatus());
            $response->setEntity($template);
            $response->send();
            return;
        }

        $stack = CoreException::findProjectStack($e);
        if ($stack === null && IS_CORE_DEBUG){
            $stack = current($e->getTrace());
        }
        $info  = new \ReflectionClass($e);

        if ($stack){
            $file = str_replace('\\', '/', $stack['file']);
            $stack['line']        += CoreException::getErrorOffsetLine($file);
            $file = $stack['file'] = CoreException::getErrorFile($file);

            $file = str_replace(str_replace('\\', '/', ROOT), '', $file);

            $source = null;
            if (file_exists($stack['file']) && is_readable($stack['file']) ){
                $fp = fopen($stack['file'], 'r');
                $n  = 1;
                $source = array();
                while($line = fgets($fp, 4096)){
                    if ( $n > $stack['line'] - 7 && $n < $stack['line'] + 7 ){
                        $source[$n] = $line;
                    }
                    if ( $n > $stack['line'] + 7 )
                        break;
                    $n++;
                }
            }
        }

        $hash = substr(md5(rand()), 12);
        $template = TemplateLoader::load('system/errors/exception.html');

        $template->putArgs(array('exception' => $e,
            'stack' => $stack, 'info' => $info, 'hash' => $hash,
            'desc' => $e->getMessage(), 'file' => $file, 'source' => $source
        ));

        Logger::error('%s, in file `%s(%s)`, id: %s', $e->getMessage(), $file ? $file : "nofile", (int)$stack['line'], $hash);

        $response = new Response();
        $response->setStatus(500);
        $response->setEntity($template);
        $response->send();
    }

    /**
    public static function catchCoreException(CoreException $e){
        self::catchAny($e);
    }

    public static function catchErrorException(\ErrorException $e){
        self::catchAny($e);
    }*/

    public static function catchException(\Exception $e){
        self::catchAny($e);
    }

    public static function getFrameworkPath(){
        return ROOT . 'framework/';
    }

    /**
     * @return bool
     */
    public static function isCLI(){
        return PHP_SAPI === 'cli';
    }

    public static function errorHandler($errno, $errstr, $errfile, $errline){
        if ( APP_MODE_STRICT ){
            $project = Project::current();
            $errfile = str_replace('\\', '/', $errfile);

            // only for project sources
            if (!$project || String::startsWith($errfile, $project->getPath())){
                if ( $errno === E_DEPRECATED
                    || $errno === E_USER_DEPRECATED
                    || $errno === E_WARNING ){
                    throw CoreStrictException::formated($errstr);
                }

                // ignore tmp dir
                if (!$project || String::startsWith($errfile, $project->getPath() . 'tmp/') )
                    return false;

                if (String::startsWith($errstr, 'Undefined variable:')
                        || String::startsWith($errstr, 'Use of undefined constant')){
                    throw CoreStrictException::formated($errstr);
                }
            }
        }
    }
    
    public static function shutdown(Project $project){
        $error = error_get_last();
        if ($error){
            switch($error['type']){
                case E_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                case E_PARSE:
                case E_USER_ERROR:
                case 4096: // Catchable fatal error
                {
                    self::catchError($error,
                        $project->config->getBoolean('logger.fatal.enable',true)
                            ? $project->getPath() . 'log/'
                            : false);

                    break;
                }
            }
        }

        ignore_user_abort(true);   
        
        ob_end_flush();
        ob_flush();
        flush();
    }


    /*** utils ***/
    public static function setTempDir($dir){
        if ( !is_dir($dir) ){
            if ( !mkdir($dir, 0777, true) ){
                throw new exceptions\CoreException('Unable to create temp directory `/tmp/`');
            }
            chmod($dir, 0777);
        }
        self::$tempDir = $dir;
    }
}


    abstract class AbstractBootstrap {

        /** @var Project */
        protected $project;

        public function setProject(Project $project){
            $this->project = $project;
        }

        public function onStart(){}
        public function onEnvironment(&$env){}
        public function onTest(array &$tests){}
        public function onUseTemplates(){}
        public function onTemplateRender(BaseTemplate $template){}
    }


    /**
     * Class Project
     * @package framework
     */
    class Project {

        private $name;
        private $paths = array();

        /** @var string */
        private $currentPath;

        /** @var string */
        private $mode = 'dev';

        /** @var string */
        private $secret;

        /** @var PropertiesConfiguration */
        public $config;

        /** @var array */
        public $deps;

        /** @var mvc\route\Router */
        public $router;

        /** @var Repository */
        public $repository;

        /** @var AbstractBootstrap */
        public $bootstrap;

        /** @var array */
        protected $assets;

        /**
         * @param string $projectName root directory name of project
         * @param bool $inWeb
         */
        public function __construct($projectName, $inWeb = true){
            $this->name   = $projectName;

            SystemCache::setId($projectName);
            $cacheName = 'appconf';

            $configFile   = $this->getPath() . 'conf/application.conf';
            $this->config = SystemCache::getWithCheckFile($cacheName, $configFile);

            if ($this->config === null){
                $this->config = new PropertiesConfiguration(new File( $configFile ));
                SystemCache::setWithCheckFile($cacheName, $this->config, $configFile);
            }

            //if ($inWeb){
            $port = $this->config->getNumber('http.port', 0);
            if ($port){
                Request::current()->setPort($port);
            }

            $this->applyConfig( $this->config );
        }

        /**
         * get project name (root directory name)
         * @return string
         */
        public function getName(){
            return $this->name;
        }

        /**
         * get project root path
         * @return string
         */
        public function getPath(){
            return self::getSrcDir() . $this->name . '/';
        }

        public function getViewPath(){
            return self::getPath() . 'app/views/';
        }

        public function getModelPath(){
            return self::getPath() . 'app/models/';
        }

        public function getTestPath(){
            return self::getPath() . 'tests/';
        }

        public function getLogPath(){
            return ROOT . 'logs/' . $this->name . '/';
        }

        /**
         * get public upload directory
         * @return string
         */
        public function getPublicPath(){
            return ROOT . 'public/' . $this->name . '/';
        }

        /*
         * пути можно указывать с доменами и с портами
         * examples:
         *
         *   domain.com:80/s1/
         *   domain.com:80/s2/
         */
        public function setPaths(array $paths){
            foreach(array_unique($paths) as $path){
                $this->paths[ $path ] = new URL( $path );
            }
        }


        /**
         * replace part configuration
         * @param \framework\config\Configuration|\framework\config\PropertiesConfiguration $config
         */
        public function applyConfig(PropertiesConfiguration $config){
            $paths = $config->getArray("app.paths", array('/'));
            $this->setPaths( $paths );
        }


        /**
         * @return boolean
         */
        public function findCurrentPath(){
            $request = Request::current();
            foreach ($this->paths as $url){
                if ( $request->isBase( $url ) )
                    return $url;
            }

            return null;
        }


        public function setUriPath(URL $url){
            $this->currentPath = $url->getPath();
        }

        public function getUriPath(){
            return $this->currentPath;
        }

        /**
         * @param string $group
         * @param bool $version, if false return last version
         * @return array
         * @throws static
         */
        public function getAsset($group, $version = false){
            $all      = $this->getAssets();
            $versions = $all[$group];

            if (!$versions)
                throw CoreException::formated('Asset `%s` not found', $group);

            if ($version){
                $info = $versions[$version];
                if (!is_array($info)){
                    throw CoreException::formated('Asset `%s/%s` not found', $group, $version);
                }
            } else {
                list($version, $info) = each($versions);
            }

            $meta = $this->repository->getLocalMeta($group, $version);
            if (!$meta)
                throw CoreException::formated('Meta information not found for `%s` asset, please run `deps update` for fix it', $group);

            $info['version'] = $version;
            return $info + $meta;
        }

        /**
         * Get files all assets
         * @param string $group
         * @param bool $version
         * @param array $included
         * @return array
         * @throws static
         * @throws FileNotFoundException
         */
        public function getAssetFiles($group, $version = false, &$included = array()){
            $info = $this->getAsset($group, $version);

            if ($included[$group][$info['version']])
                return array();

            $included[$group][$info['version']] = true;

            $result = array();
            if (is_array($info['deps'])){
                foreach($info['deps'] as $gr => $v){
                    $result = array_merge($result, $this->getAssetFiles($gr, $v, $included));
                }
            }

            $path   = '/assets/' . $group . '~' . $info['version'] . '/';
            foreach((array)$info['files'] as $file){
                $result[] = $path . $file;

                if (IS_DEV && !is_file(ROOT . $path . $file)){
                    throw new FileNotFoundException(new File($path . $file));
                }
            }

            return $result;
        }

        public function isDev(){
            return $this->mode != 'prod';
        }

        public function isProd(){
            return $this->mode === 'prod';
        }

        public function isMode($mode){
            return $this->mode === $mode;
        }


        public function register($inWeb = true){
            ClassScanner::addClassPath($this->getPath());

            Project::$instance = $this;
            SystemCache::setId($this->name);

            if (is_file($file = $this->getPath() . 'app/Bootstrap.php')){
                require $file;
                if (!class_exists('Bootstrap', false)){
                    throw CoreException::formated('Can`t find `Bootstrap` class at `%s`', $file);
                }
                $this->bootstrap = new \Bootstrap();
                $this->bootstrap->setProject($this);
            }

            // config
            $this->mode = strtolower($this->config->getString('app.mode', 'dev'));
            if ($this->bootstrap)
                $this->bootstrap->onEnvironment($this->mode);

            if (!$this->mode)
                throw CoreException::formated('App mode must be set in `conf/environment.php` or `conf/application.conf`');

            define('IS_PROD', $this->isProd());
            define('IS_DEV', $this->isDev());
            define('IS_CORE_DEBUG', $this->config->getBoolean('core.debug'));
            define('APP_MODE_STRICT', $this->config->getBoolean('app.mode.strict', IS_DEV));

            define('APP_MODE', $this->mode);
            $this->config->setEnv( $this->mode );

            define('APP_PUBLIC_PATH', $this->config->get('app.public', '/public/' . $this->name . '/'));
            $this->secret = $this->config->getString('app.secret');
            if ( !$this->secret ){
                throw new ConfigurationReadException($this->config, '`app.secret` must be set as random string');
            }

            // temp
            Core::setTempDir( sys_get_temp_dir() . '/regenix/' . $this->name . '/' );

            $sessionDriver = new APCSession();
            $sessionDriver->register();

            // module deps
            $this->_registerDependencies();

            // route
            $this->_registerRoute();

            if (IS_DEV)
                $this->_registerTests();

            if ($inWeb)
                $this->_registerSystemController();

            if ($this->bootstrap){
                $this->bootstrap->onStart();
            }
        }

        public function loadDeps(){
            $file = $this->getPath() . 'conf/deps.json';
            $this->deps = array();

            if (is_file($file)){
                if (IS_DEV){
                    $this->deps = json_decode(file_get_contents($file), true);
                    if (json_last_error()){
                        throw new JsonFileException('conf/deps.json');
                    }
                } else {
                    $this->deps = SystemCache::getWithCheckFile('app.deps', $file);
                    if ($this->deps === null){
                        $this->deps = json_decode(file_get_contents($file), true);
                        if (json_last_error()){
                            throw new JsonFileException('conf/deps.json', 'invalid json format');
                        }
                        SystemCache::setWithCheckFile('app.deps', $this->deps, $file, 60 * 5);
                    }
                }
            }
        }

        /**
         * Get all assets of project
         * @throws static
         * @return array
         */
        public function getAssets(){
            if (is_array($this->assets))
                return $this->assets;

            $this->assets = $this->repository->getAll('assets');

            if (IS_DEV){
                foreach($this->assets as $group => $versions){
                    foreach($versions as $version => $el){
                        if (!$this->repository->isValid($group, $version)){
                            throw CoreException::formated('Asset `%s/%s` not valid or not exists, please run in console `regenix deps update`', $group, $version);
                        }
                    }
                }
            }
            return $this->assets;
        }

        private function _registerDependencies(){
            $this->loadDeps();
            $this->repository = new Repository($this->deps);

            // modules
            $this->repository->setEnv('modules');
            foreach((array)$this->deps['modules'] as $name => $conf){
                $dep = $this->repository->findLocalVersion($name, $conf['version']);
                if (!$dep){
                    throw CoreException::formated('Can`t find `%s/%s` module, please run in console `regenix deps update`', $name, $conf['version']);
                } elseif (IS_DEV && !$this->repository->isValid($name, $dep['version'])){
                    throw CoreException::formated('Module `%s` not valid or not exists, please run in console `regenix deps update`', $name);
                }
                AbstractModule::register($name, $dep['version']);
            }

            if (IS_DEV)
                $this->getAssets();
        }

        private function _registerSystemController(){
            if ($this->config->getBoolean('captcha.enable')){
                $this->router->addRoute('GET', Captcha::URL, 'framework.mvc.SystemController.captcha');
            }

            if ($this->config->getBoolean('i18n.js')){
                $this->router->addRoute('GET', '/system/i18n.js', 'framework.mvc.SystemController.i18n_js');
                $this->router->addRoute('GET', '/system/i18n.{_lang}.js', 'framework.mvc.SystemController.i18n_js');
            }
        }

        private function _registerTests(){
            $this->router->addRoute('*', '/@test', 'framework.test.Tester.run');
            $this->router->addRoute('GET', '/@test.json', 'framework.test.Tester.runAsJson');
        }

        private function _registerRoute(){
            // routes
            $routeFile = $this->getPath() . 'conf/route';
            $this->router = SystemCache::getWithCheckFile('route', $routeFile);

            if ( $this->router === null ){
                $this->router = new Router();

                $routeConfig  = new RouterConfiguration();

                foreach (modules\AbstractModule::$modules as $name => $module){
                    $routeConfig->addModule($name, '.modules.' . $name . '.controllers.', $module->getRouteFile());
                }

                $routeConfig->setFile(new File($routeFile));
                $routeConfig->load();

                $this->router->applyConfig($routeConfig);
                SystemCache::setWithCheckFile('route', $this->router, $routeFile, 60 * 2);
            }
        }

        /** @var Project */
        private static $instance;

        /**
         * @return Project
         */
        public static function current(){
            return self::$instance;
        }

        private static $srcDir = null;
        public static function getSrcDir(){
            if ( self::$srcDir ) return self::$srcDir;

            return self::$srcDir = str_replace(DIRECTORY_SEPARATOR, '/', ROOT . 'apps/');
        }
    }


    /**
     * Class SDK
     * @package framework
     */
    abstract class SDK {

        private static $types = array();
        private static $handlers = array();

        private static function setCallable($callback, array &$to, $prepend = false){
            if ( IS_DEV && !is_callable($callback) ){
                throw new TypeException('callback', 'callable');
            }

            if ( $prepend )
                array_unshift($to, $callback);
            else
                $to[] = $callback;
        }

        /**
         * @param string $trigger
         * @param callable $callback
         * @param bool $prepend
         * @throws static
         */
        public static function addHandler($trigger, $callback, $prepend = false){
            if (IS_DEV && !self::$types[$trigger])
                throw CoreException::formated('Trigger type `%s` is not registered', $trigger);

            if (!self::$handlers[$trigger])
                self::$handlers[$trigger] = array();

            self::setCallable($callback, self::$handlers[$trigger], $prepend);
        }

        /**
         * @param string $name
         * @param array $args
         * @throws static
         */
        public static function trigger($name, array $args = array()){
            if (IS_DEV && !self::$types[$name])
                throw CoreException::formated('Trigger type `%s` is not registered', $name);

            foreach((array)self::$handlers[$name] as $handle){
                call_user_func_array($handle, $args);
            }
        }

        /**
         * @param string $name
         */
        public static function registerTrigger($name){
            self::$types[$name] = true;
        }

        /**
         * @param string $name
         */
        public static function unregisterTrigger($name){
            unset(self::$types[$name]);
        }

        /**
         * @param string $moduleUID
         * @return bool
         */
        public static function isModuleRegister($moduleUID){
            return (boolean)modules\AbstractModule::$modules[ $moduleUID ];
        }
    }
}

namespace {

    define('PHP_TRAITS', function_exists('trait_exists'));

    function dump($var){
        echo '<pre class="_dump">';
        print_r($var);
        echo '</pre>';
    }

    /**
     * get absolute all traits
     * @param $class
     * @param bool $autoload
     * @return array
     */
    function class_uses_all($class, $autoload = true) {
        $traits = array();
        if (!PHP_TRAITS)
            return $traits;

        do {
            $traits = array_merge(class_uses($class, $autoload), $traits);
        } while($class = get_parent_class($class));
        foreach ($traits as $trait => $same) {
            $traits = array_merge(class_uses($trait, $autoload), $traits);
        }
        return array_unique($traits);
    }

    /**
     * check usage trait in object
     * @param $object
     * @param $traitName
     * @param bool $autoload
     * @return bool
     */
    function trait_is_use($object, $traitName, $autoload = true){
        if (!PHP_TRAITS)
            return false;

        $traits = class_uses_all($object, $autoload);
        return isset($traits[$traitName]);
    }
}



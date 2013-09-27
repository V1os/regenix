<?php
namespace regenix\lang;

use regenix\mvc\Annotations;

/**
 * Class DI - Dependency Injection Container
 * @package regenix\lang
 */
final class DI {

    private static $reflections = array();
    private static $singletons = array();

    private static $binds = array();
    private static $namespaceBinds = array();
    private static $cacheNamespaceBinds = array();

    private function __construct(){}

    /**
     * @param $class
     * @return \ReflectionClass
     */
    private static function getReflection($class){
        if ($reflection = self::$reflections[$class])
            return $reflection;

        return self::$reflections[$class] = new \ReflectionClass($class);
    }

    private static function validateDI($interface, $implement){
        if (is_object($implement))
            $implement = get_class($implement);

        $meta = ClassScanner::find($interface);
        if (!$meta)
            throw new ClassNotFoundException($interface);

        if (!($info = ClassScanner::find($implement)))
            throw new ClassNotFoundException($implement);

        if (!$meta->isParentOf($implement)){
            throw new DependencyInjectionException('"%s" class should be implemented or inherited by "%s"', $implement, $interface);
        }

        if ($info->isAbstract() || $info->isInterface()){
            throw new DependencyInjectionException('"%s" cannot be an abstract class or interface');
        }
    }

    private static function _getInstance($class){
        if ($class[0] === '\\') $class = substr($class, 1);

        if ($bindClass = self::$binds[$class])
            $class = $bindClass;
        else {
            if ($tmp = self::$cacheNamespaceBinds[ $class ]){
                $class = $tmp;
            } else {
                foreach(self::$namespaceBinds as $interfaceNamespace => $implementNamespace){
                    if (String::startsWith($class, $interfaceNamespace)){
                        $newClass = $implementNamespace . substr($class, strlen($interfaceNamespace));
                        self::$cacheNamespaceBinds[$class] = $newClass;

                        if (REGENIX_IS_DEV)
                            self::validateDI($class, $newClass);

                        if (self::$singletons[$interfaceNamespace] === true){
                            self::$singletons[$class] = true;
                            return self::getInstance($class);
                        }

                        $class = $newClass;
                        break;
                    }
                }
            }
        }

        /*if (is_object($class))
            return $class;*/

        $reflection  = self::getReflection($class);
        $constructor = $reflection->getConstructor();

        $args = array();
        if ($constructor){
            foreach($constructor->getParameters() as $parameter){
                $class = $parameter->getClass();
                if ($class){
                    $args[] = self::getInstance($class->getName());
                } else {
                    $args[] = null;
                }
            }
            $object = $reflection->newInstance($args);
        } else {
            $object = $reflection->newInstance();
        }

        return $object;
    }

    public static function getInstance($class){
        $class     = str_replace('.', '\\', $class);
        $singleton = self::$singletons[$class];

        if ($singleton === true){
            return self::$singletons[$class] = self::_getInstance($class);
        } else if ($singleton){
            return $singleton;
        } else {
            return self::_getInstance($class);
        }
    }

    /**
     * @param $interfaceNamespace
     * @param $implementNamespace
     * @param bool $singleton
     */
    public static function bindNamespaceTo($interfaceNamespace, $implementNamespace,
                                           $singleton = false){
        $interfaceNamespace = str_replace('.', '\\', $interfaceNamespace);
        $implementNamespace = str_replace('.', '\\', $implementNamespace);

        if ($interfaceNamespace[0] === '\\')
            $interfaceNamespace = substr($interfaceNamespace, 1);

        if ($implementNamespace[0] === '\\')
            $implementNamespace = substr($implementNamespace, 1);

        self::$namespaceBinds[$interfaceNamespace] = $implementNamespace;
        self::$cacheNamespaceBinds = array();
        self::$singletons[ $interfaceNamespace ] = $singleton;
    }

    /**
     * @param $interface
     * @param $class
     * @param bool $singleton
     */
    public static function bindTo($interface, $class, $singleton = false){
        $interface = str_replace('.', '\\', $interface);
        if (!is_object($class))
            $class = str_replace('.', '\\', $class);

        if (REGENIX_IS_DEV)
            self::validateDI($interface, $class);

        self::$binds[ $interface ] = $class;
        if ($singleton){
            self::$singletons[ $interface ] = true;
        }
    }

    public static function bind($object){
        self::$singletons[get_class($object)] = $object;
    }

    public static function clear(){
        self::$singletons = array();
        self::$cacheNamespaceBinds = array();
        self::$namespaceBinds = array();
        self::$binds = array();
    }
}

class DependencyInjectionException extends CoreException {}
<?php

namespace framework\deps;

use framework\io\File;
use framework\lang\IClassInitialization;
use framework\lang\String;

class Repository implements IClassInitialization{

    private static $envList = array('assets', 'modules');

    /** @var array */
    protected $meta;

    /**
     * @var Origin
     */
    protected $origin;

    /**
     * @var array
     */
    protected $deps = array();

    /**
     * @var string
     */
    protected $env;


    public function __construct(array $deps){
        $this->deps = $deps;
        if ($deps['repository']){
            $this->setOrigin(Origin::createOriginByAddress($deps['repository']));
        }
    }

    public function setOrigin(Origin $origin){
        $this->origin = $origin;
    }

    public function setEnv($env){
        $this->env = $env;
        if ($this->origin)
            $this->origin->setEnv($env);
    }

    protected function getMeta($group){
        if ($this->meta[$group])
            return $this->meta[$group];

        return $this->meta[$group] = $this->origin->getMetaInfo($group);
    }

    protected function findMaxVersion($meta, $patternVersion){
        $curVersion = false;
        foreach($meta as $version => $dep){
            if (preg_match('#^' . $patternVersion . '$#', $version)){
                if ($curVersion === false || version_compare($version, $curVersion, '>')){
                    $curVersion = $version;
                }
            }
        }
        $result = $meta[$curVersion];
        if (is_array($result))
            $result['version'] = $curVersion;

        return $result;
    }

    /**
     * @param $group
     * @param $patternVersion
     * @return mixed
     */
    public function findLocalVersion($group, $patternVersion){
        $meta = array();
        $dirs = glob(ROOT . $this->env . '/' . $group . '~*', GLOB_ONLYDIR | GLOB_NOSORT);
        foreach($dirs as $dir){
            $asset = basename($dir);
            $curVer = explode('~', $asset, 2);
            $curVer = $curVer[1];

            if ($curVer){
                $meta[$curVer] = array();
            }
        }

        return $this->findMaxVersion($meta, $patternVersion);
    }

    /**
     * @param $group
     * @param string $patternVersion
     * @return mixed
     */
    protected function findVersion($group, $patternVersion){
        $meta = $this->getMeta($group);
        return $this->findMaxVersion($meta, $patternVersion);
    }

    /**
     * @param $group
     * @param $patternVersion
     * @param bool $force
     * @return bool
     * @throws DependencyNotFoundException
     * @throws DependencyDownloadException
     */
    public function download($group, $patternVersion, $force = false){
        $dep = $this->findVersion($group, $patternVersion);
        if (!$dep){
            throw new DependencyNotFoundException($this->env, $group, $patternVersion);
        } else {
            if (!$force
                && $this->isDownloaded($group, $dep['version'])
                && $this->isValid($group, $dep['version'], $dep)){
                $dep['skip'] = true;
                return $dep;
            }

            $toDir = ROOT . $this->env . '/' . $group . '~' . $dep['version'] . '/';
            foreach($dep['files'] as $file){
                $done = $this->origin->downloadDependency($group, $dep['version'], $file, $toDir);
                if (!$done){
                    throw new DependencyDownloadException($this->env, $group, $dep['version']);
                }
                if (String::endsWith($file, '.extract.zip')){
                    $zip = new \ZipArchive();
                    if ($zip->open($toDir . $file) === true){
                        $zip->extractTo($toDir);
                        $zip->close();
                    }
                    @unlink($toDir . $file);
                }
            }
            $tmp = new File($toDir);
            $tmp->mkdirs();
            file_put_contents($toDir . 'meta.json', json_encode($dep));

            return $dep;
        }
    }


    private $downloaded = array();

    /**
     * find all downloaded deps
     * @return array
     */
    public function getDownloaded(){
        if ($tmp = $this->downloaded[$this->env]){
            return $tmp;
        }

        $dir = ROOT . $this->env . '/';

        $result = array();
        $dirs   = scandir($dir);
        foreach($dirs as $item){
            if (is_dir($dir . $item)){
                $tmp = explode('~', $item, 2);
                $group   = $tmp[0];
                $version = $tmp[1];
                if ($version){
                    $result[$group][$version] = true;
                }
            }
        }

        return $this->downloaded[$this->env] = $result;
    }

    /**
     * @param string $group
     * @param string $version
     * @return bool
     */
    public function isDownloaded($group, $version){
        $downloaded = $this->getDownloaded();
        return isset($downloaded[$group][$version]);
    }

    /**
     * @param string $group
     * @param string $version
     * @param array|null $dep
     * @return bool
     */
    public function isValid($group, $version, $dep = null){
        $dir = ROOT . $this->env . '/' . $group . '~' . $version . '/';
        if (!is_file($dir . 'meta.json'))
            return false;

        $meta = json_decode(file_get_contents($dir . 'meta.json'), true);
        if (!$meta || json_last_error()){
            return false;
        }

        // TODO: how to check valid files? with archives?
        $files = $meta['files'];
        foreach($files as $file){
            if (String::endsWith($file, '.extract.zip'))
                break;

            if (!is_file($dir . $file) || filesize($dir . $file) === 0)
                return false;
        }

        if ($dep){
            $files = $dep['files'];
            foreach((array)$files as $file){
                if (String::endsWith($file, '.extract.zip'))
                    break;

                if (!is_file($dir . $file) || filesize($dir . $file) === 0)
                    return false;
            }
        }

        return true;
    }


    public static function initialize(){
        Origin::register(GithubOrigin::type);
        Origin::register(FileOrigin::type);
    }
}
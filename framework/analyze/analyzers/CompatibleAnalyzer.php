<?php
namespace regenix\analyze\analyzers;

use regenix\analyze\AnalyzeException;
use regenix\analyze\AnalyzeManager;
use regenix\analyze\Analyzer;
use regenix\lang\CoreException;
use regenix\lang\File;

class CompatibleAnalyzer extends Analyzer {

    const PHP_VER_53 = '5.3';
    const PHP_VER_54 = '5.4';
    const PHP_VER_55 = '5.5';

    /** @var string */
    protected $version;

    public function getSort(){
        return 500;
    }

    public function __construct(AnalyzeManager $manager, File $file, array $statements, $content){
        parent::__construct($manager, $file, $statements, $content);
        $configuration = $manager->getConfiguration();
        $this->version = $configuration->getString('php.version');
        if ($this->version){
            if ($this->version !== self::PHP_VER_53
                && $this->version !== self::PHP_VER_54
                && $this->version !== self::PHP_VER_55){
                throw new CoreException('Unknown PHP version - %s', $this->version);
            }
        }
    }

    public function analyze() {
        if (!$this->version)
            return;

        $traverser = new \PHPParser_NodeTraverser();
        $traverser->addVisitor(new \PHPParser_NodeVisitor_NameResolver());
        $traverser->addVisitor(new CompatibleNodeVisitor($this->file, $this->version));
        $traverser->traverse($this->statements);
    }
}

class CompatibleNodeVisitor extends \PHPParser_NodeVisitorAbstract {

    /** @var File */
    protected $file;

    /** @var string */
    protected $version;

    /**
     * @param File $file
     * @param string $version
     */
    public function __construct($file, $version){
        $this->file = $file;
        $this->version = $version;
    }
}

class CompatibleAnalyzeException extends AnalyzeException {}
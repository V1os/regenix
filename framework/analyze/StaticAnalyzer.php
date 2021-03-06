<?php
namespace regenix\analyze;

use regenix\lang\ClassScanner;
use regenix\lang\File;

class StaticAnalyzer extends Analyzer {

    public function getSort(){
        return 100;
    }

    public function analyze() {
        $traverser = new \PHPParser_NodeTraverser();
        $traverser->addVisitor(new \PHPParser_NodeVisitor_NameResolver());
        $traverser->addVisitor(new StaticNodeVisitor($this->file));
        $traverser->traverse($this->statements);
    }
}

class StaticNodeVisitor extends \PHPParser_NodeVisitorAbstract {

    /** @var File */
    protected $file;

    /** @var \PHPParser_Node_Stmt */
    protected $current = null;

    /** @var \PHPParser_Node_Stmt_ClassMethod */
    protected $currentMethod = null;

    public function __construct($file){
        $this->file = $file;
    }

    public function leaveNode(\PHPParser_Node $node) {
        if ($node instanceof \PHPParser_Node_Stmt_Trait){
            $this->current = $node;
        } elseif ($node instanceof \PHPParser_Node_Stmt_ClassMethod){
            $this->currentMethod = $node;
        } elseif ($node instanceof \PHPParser_Node_Name_FullyQualified){
            $this->checkClassExists($node);
        } elseif ($node instanceof \PHPParser_Node_Stmt_Class){
            $this->current = $node;
            $this->checkDefinedClass($node);
        } elseif ($node instanceof \PHPParser_Node_Expr_StaticCall){
            $this->checkStaticCall($node);
        }
    }

    protected function nameExists($name){
        $info = ClassScanner::find($name);
        if (!$info
            && !class_exists($name, false)
            && !interface_exists($name, false)
            && !_trait_exists($name, false)){
            return false;
        }
        return true;
    }

    protected function checkStaticCall(\PHPParser_Node_Expr_StaticCall $node){
        $class = $node->class;
        $method = $node->name;

        if (is_string($method)
            && ($class instanceof \PHPParser_Node_Name_FullyQualified
                || $class instanceof \PHPParser_Node_Name)){

            $className = $class->toString();
            if ($className === 'self' || $className === 'static' || $className === 'parent'){
                return;
            }

            $info = ClassScanner::find($className);
            if ($info){
                $methods = Analyzer::getMethods($className);
                $info = $methods[$method];
                $message = null;
                if (!$info){
                    $message = 'Method "%s" is not found in "%s" class';
                } else if (!$info['static']){
                    $message = 'Method "%s" of "%s" class is not static';
                } else if ($info['abstract']){
                    $message = 'Method "%s" of "%s" class is abstract, it cannot be invoked!';
                }

                if ($message){
                    throw new StaticAnalyzeException(
                        $this->file,
                        $node->getLine(),
                        $message,
                        $method, $className
                    );
                }

            } else {
                if (class_exists($class->toString(), false)){
                    if (!method_exists($class->toString(), $method)){
                        throw new StaticAnalyzeException(
                            $this->file,
                            $node->getLine(),
                            'Method "%s" is not found in "%s" class',
                            $method, $class->toString()
                        );
                    }
                }
            }
        }
    }

    protected function checkClassExists(\PHPParser_Node_Name_FullyQualified $node){
        $name = $node->toString();
        if (!$this->nameExists($name)){
            throw new StaticAnalyzeException(
                $this->file,
                $node->getLine(),
                'Class "%s" is not found, {static analyzing}',
                $name
            );
        }
    }

    protected function checkDefinedClass(\PHPParser_Node_Stmt_Class $node){
        $extend = $node->extends;

        if ($extend){
            $name = $extend->toString();
            $info = ClassScanner::find($name);

            if (!class_exists($name, false) && !($info && $info->isClass())){
                throw new StaticAnalyzeException(
                    $this->file,
                    $node->getLine(),
                    '"%s" is not class and not applicable for `extends` statement',
                    $name
                );
            }
        }

        $implements = $node->implements;
        foreach($implements as $implement){
            $name = $implement->toString();
            if (interface_exists($name, false))
                continue;

            $info = ClassScanner::find($name);
            if ($info && $info->isInterface()) continue;

            throw new StaticAnalyzeException(
                $this->file,
                $node->getLine(),
                '"%s" is not interface and not applicable for `implements` statement',
                $name
            );
        }
    }
}

class StaticAnalyzeException extends AnalyzeException {}
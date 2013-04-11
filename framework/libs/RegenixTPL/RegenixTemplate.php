<?php
namespace framework\libs\RegenixTPL {

/**
 * Class RegenixTemplate
 * @package framework\libs\RegenixTPL
 */
    use framework\Core;
    use framework\exceptions\CoreException;
    use framework\lang\String;
    use framework\mvc\template\TemplateLoader;

/**
 *  ${var} - var
 *  {tag /} - tag
 *  {tag} #{/tag} - big tag
 *  &{message} - i18n
 *  {if} ... {/if}
 *  {else} {/else}
 *  {tag arg, name: value, name: value /}
 *  @{Application.index} - route
 */
class RegenixTemplate {

    const type = __CLASS__;

    /** @var string */
    protected $tmpDir;

    /** @var string[] */
    protected $tplDirs;

    /** @var RegenixTemplateTag[string] */
    protected $tags;

    /** @var string */
    protected $file;

    /** @var array */
    public $blocks;

    /** @var string */
    protected $compiledFile;

    public function __construct(){
        $this->registerTag(new RegenixGetTag());
        $this->registerTag(new RegenixSetTag());
    }

    public function setFile($file){
        $this->file = $file;
    }

    public function setTempDir($directory){
        $this->tmpDir = $directory;
    }

    public function setTplDirs(array $dirs){
        $this->tplDirs = $dirs;
    }

    public function registerTag(RegenixTemplateTag $tag){
        $this->tags[strtolower($tag->getName())] = $tag;
    }

    public function __clone(){
        $new = new RegenixTemplate();
        $new->setTplDirs($this->tplDirs);
        $new->setTempDir($this->tmpDir);
        $new->tags   = $this->tags;
        $new->blocks =& $this->blocks;

        return $new;
    }

    protected function _makeArgs($str){
        $tmp = self::explodeMagic(':', $str);
        if ( !$tmp[1] ){
            return 'array("_arg" => ' . $str . ')';
        } else {
            $args = self::explodeMagic(',', $str, 100);
            $result = 'array(';
            foreach($args as $arg){
                $tmp = self::explodeMagic(':', $arg);
                $key = trim($tmp[0]);
                $result .= "'" . $key . "' => (" . $tmp[1] . '), ';
            }
            return $result . ')';
        }
    }

    private static function explodeMagic($delimiter, $string){
        $result = array();

        $i     = 0;
        $sk    = 0;
        $skA   = 0;
        $quote = false;
        $quoteT = false;
        $str    = '';
        while($ch = $string[$i]){
            $i++;
            $str .= $ch;

            if ( $ch == '"' || $ch == "'" ){
                if($quote){
                    if ($string[$i-1] != '\\'){
                        $quote  = false;
                        $quoteT = false;
                    }
                } else {
                    $quote  = true;
                    $quoteT = $ch;
                }
                continue;
            }
            if ( $ch == '(') $sk += 1;
            if ( $ch == ')' ) $sk -= 1;
            if ( $ch == '[' ) $skA += 1;
            if ( $ch == ']' ) $skA -= 1;

            if ($quote || $sk || $skA)
                continue;

            if ( $ch === $delimiter ){
                $result[] = substr($str, 0, -1);
                $str      = '';
                continue;
            }

        }
        if ($str)
            $result[] = $str;

        return $result;
    }

    protected function _compile(){
        $source = file_get_contents($this->file);
        $result = '<?php $__extends = false; ?>';

        $p = -1;
        $lastE = -1;
        while(($p = strpos($source, '{', $p + 1)) !== false){

            $mod  = $source[$p - 1];
            $e    = strpos($source, '}', $p);
            $expr = substr($source, $p + 1, $e - $p - 1);

            $prevSource = $lastE === -2 ? '' : substr($source, $lastE + 1, $p - $lastE - 2);
            $lastE = $e;

            $result .= $prevSource;
            $extends = false;
            switch($mod){
                case '@': {
                    $result .= '<?php echo ' . $expr . '?>';
                } break;
                case '#': {
                    $tmp = explode(' ', $expr, 2);
                    $cmd = $tmp[0];
                    if ($cmd[0] == '/')
                        $result .= '<?php end'.substr($cmd,1).'?>';
                    else {
                        if ( $cmd === 'else' )
                            $result .= '<?php else:?>';
                        elseif ($cmd === 'extends'){
                            $result .= '<?php echo $_TPL->_renderBlock("doLayout", ' . $tmp[1] . '); $__extends = true;?>';
                        } elseif ($cmd === 'doLayout'){
                            $result .= '%__BLOCK_doLayout__%';
                        } elseif ($this->tags[$cmd]){
                            $result .= '<?php $_TPL->_renderTag("' . $cmd . '", '.$this->_makeArgs($tmp[1]).');?>';
                        } else
                            $result .= '<?php ' .$cmd. '(' . $tmp[1] . '):?>';
                    }
                } break;
                case '&': {
                    $result .= '<?php echo htmlspecialchars(\\framework\\libs\\I18n::get('. $expr .'))?>';
                } break;
                case '$': {
                    //$result .= $mod;
                    $append = '';
                    if (ctype_alpha($expr[0]))
                        $append = '$';

                    $data = self::explodeMagic('|', $expr);
                    if ( $data[1] ){
                        $mods = self::explodeMagic(',', $data[1]);
                        foreach($mods as &$mod){
                            $mod = trim($mod);
                            if (substr($mod,-1) != ')')
                                $mod .= '()';
                        }

                        $modsAppend = implode('->', $mods);
                        $result .= '<?php echo $_TPL->_makeObjectVar('. $append . $data[0] . ')->'
                            . $modsAppend . '?>';
                    } else {
                        $result .= '<?php echo htmlspecialchars((string)'. $append . $data[0] . ')?>';
                    }
                } break;
                default: {
                    $result .= $mod .'{'. $expr . '}';
                }
            }
        }
        $result .= substr($source, $lastE + 1);
        $result .= '<?php if($__extends){ $_TPL->_renderContent(); } ?>';

        $dir = dirname($this->compiledFile);
        if (!is_dir($dir))
            mkdir($dir, 0777, true);

        file_put_contents($this->compiledFile, $result);
    }

    public function compile($cached = true){
        $sha = sha1($this->file);
        $this->compiledFile = ($this->tmpDir . $sha . '.' . filemtime($this->file) . '.php');
        if ( IS_DEV ){
            foreach(glob($this->tmpDir . $sha . '.*.php') as $file){
                $file = realpath($file);
                if ( $file == $this->compiledFile ) continue;
                @unlink($file);
            }
        }

        if ( !is_file($this->compiledFile) || !$cached ){
            $this->_compile();
        }
    }

    public function render($__args, $__cached = true){
        $this->compile($__cached);
        $_tags = $this->tags;
        $_TPL = $this;
        if ($__args)
            extract($__args, EXTR_PREFIX_INVALID | EXTR_OVERWRITE, 'arg_');

        CoreException::setMirrorFile($this->compiledFile, $this->file);
        include $this->compiledFile;
    }

    public function _renderVar($var){
        if (is_object($var)){
            return (string)$var;
        } else
            return htmlspecialchars($var);
    }

    public function _makeObjectVar($var){
        if ( is_object($var) )
            return $var;
        else
            return RegenixVariable::current($var);
    }

    public function _renderTag($tag, array $args = array()){
        $this->tags[$tag]->call($args, $this);
    }

    public function _renderBlock($block, $file, array $args = null){
        $tpl = clone $this;
        $file = str_replace('\\', '/', $file);
        if (!String::endsWith($file, '.html'))
            $file .= '.html';

        $tpl->setFile( TemplateLoader::findFile($file) );
        ob_start();
            $tpl->render($args);
            $str = ob_get_contents();
        ob_end_clean();
        $this->blocks[ $block ] = $str;
        ob_start();
    }

    public function _renderContent(){
        $content = ob_get_contents();
        ob_end_clean();
        $content = str_replace('%__BLOCK_doLayout__%', $content, $this->blocks['doLayout']);
        foreach($this->blocks as $name => $block){
            if ($name != 'doLayout'){
                $content = str_replace('%__BLOCK_'.$name.'__%', $block, $content);
            }
        }

        echo $content;
    }
}

abstract class RegenixTemplateTag {

    abstract function getName();
    abstract public function call($args, RegenixTemplate $ctx);
}

class RegenixGetTag extends RegenixTemplateTag {

    function getName(){
        return 'get';
    }

    public function call($args, RegenixTemplate $ctx){
        echo '%__BLOCK_' . $args['_arg'] . '__%';
    }
}

class RegenixSetTag extends RegenixTemplateTag {

    function getName(){
        return 'set';
    }

    public function call($args, RegenixTemplate $ctx){
        list($key, $value) = each($args);
        $ctx->blocks[$key] = $value;
    }
}

class RegenixVariable {

    protected $var;
    protected static $instance;
    protected static $modifiers = array();

    protected function __construct($var){
        $this->var = $var;
    }

    public function raw(){
        return $this;
    }

    public function format($format){
        $this->var = date($format, $this->var);
        return $this;
    }

    public function lowerCase(){
        $this->var = strtolower($this->var);
        return $this;
    }

    public function upperCase(){
        $this->var = strtoupper($this->var);
        return $this;
    }

    public function nl2br(){
        $this->var = nl2br($this->var);
        return $this;
    }

    public static function current($var){
        if (self::$instance){
            self::$instance->var = $var;
            return self::$instance;
        }
        return self::$instance = new RegenixVariable($var);
    }

    public function __toString(){
        return (string)$this->var;
    }

    public function __call($name, $args){
        $name = strtolower($name);
        if ($callback = self::$modifiers[$name]){
            array_unshift($args, $this->var);
            $this->var = call_user_func_array($callback, $args);
            return $this->var;
        } else
            throw CoreException::formated('Template `%s()` modifier not found', $name);
    }
}
}

namespace {

    use framework\libs\I18n;

    function __($message, $args = ''){
        if (is_array($args))
            return I18n::get($message, $args);
        else
            return I18n::get($message, array_slice(func_get_args(), 1));
    }
}
<?php

namespace framework\mvc\template;

abstract class BaseTemplate {
    
    const TWIG = 'Twig';
    const SMARTY = 'Smarty';
    const PHP = 'PHP';

    protected $file;
    protected $name;
    protected $args = array();

    const ENGINE_NAME = 'abstract';
    const FILE_EXT    = '???';
    
    public function __construct($templateFile, $templateName) {
        $this->file = $templateFile;
        $this->name = $templateName;
    }
    
    public function getContent(){ return null; } 
    public function render(){}
    
 
    public function putArgs(array $args = array()){
        $this->args = $args;
    }
}

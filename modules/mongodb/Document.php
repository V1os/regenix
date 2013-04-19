<?php
namespace modules\mongodb;

use framework\exceptions\CoreException;
use framework\lang\IClassInitialization;
use framework\lang\String;
use framework\modules\AbstractModule;
use framework\mvc\AbstractModel;
use framework\mvc\AbstractService;
use framework\mvc\ActiveRecord;

abstract class Document extends ActiveRecord {

    const type = __CLASS__;

    public function __construct(){
        $service = static::getService();
        $meta    = $service->getMeta();

        foreach($meta['fields'] as $name => $info){
            $this->__data[ $name ] = $this->{$name};
            unset($this->{$name});
        }
    }

    /**
     * @param $id
     * @param mixed $id
     * @return Document|null
     */
    public static function findById($id){
        return static::getService()->findById($id);
    }

    /**
     * @param Query $filter
     * @param array $fields
     * @return ModelCursor
     */
    public static function find(Query $filter = null, array $fields = array()){
        return static::getService()->findByFilter($filter ? $filter->getData() : array(), $fields);
    }

    public static function findAndModify(Query $filter, array $update, array $fields = array()){
        return static::getService()->findByFilterAndModify($filter ? $filter->getData() : array(), $update, $fields);
    }

    /**
     * @return Service
     */
    public static function getService(){
        return Service::get(get_called_class());
    }

    // handle, call on first load class
    public static function initialize(){

        /** @var $module Module */
        $module = Module::getCurrent();
        $module->initConnection();

        parent::initialize();
    }

    /**
     * @return Query
     */
    public static function query(){
        return new Query(static::getService());
    }
}

class Query {

    /** @var AbstractService */
    private $service;
    private $meta;

    private $data;
    private $stackField;

    public function __construct(AbstractService $service){
        $this->data    = array();
        $this->service = $service;
        $this->meta    = $service->getMeta();
    }

    public function field($name){
        if ($this->stackField)
            throw CoreException::formated('Query build error: field `%s` set already', $this->stackField);

        if (!$this->meta['fields'][$name])
            throw CoreException::formated('Query build error: field `%s` not exists in `%s` document type', $name, $this->service->getModelClass());

        $this->stackField = $name;
        return $this;
    }

    protected function popField(){
        $name = $this->stackField;
        $this->stackField = '';
        if (!$name){
            throw CoreException::formated('Query build error: field is not set');
        }

        $column = $this->meta['fields'][$name]['column'];
        return $column;
    }

    protected function getValue($field, $value){
        $info = $this->meta['fields'][$field];
        return Service::typed($value, $info['type'], $info['ref']);
    }

    public function addOr(Query $query){
        $this->data['$or'][] = $query->getData();
        return $this;
    }

    protected function popValue($value, $prefix = false){
        $name = $this->popField();
        if ($prefix)
            $this->data[$name][$prefix] = $this->getValue($name, $value);
        else
            $this->data[$name] = $this->getValue($name, $value);

        return $this;
    }

    public function eq($value){
        return $this->popValue($value);
    }

    public function gt($value){
        return $this->popValue($value, '$gt');
    }

    public function gte($value){
        return $this->popValue($value, '$gte');
    }

    public function lt($value){
        return $this->popValue($value, '$lt');
    }

    public function lte($value){
        return $this->popValue($value, '$lte');
    }

    public function all(array $value){
        $this->data[$this->popField()]['$all'] = $value;
    }

    public function in(array $value){
        $this->data[$this->popField()]['$in'] = $value;
        return $this;
    }

    public function nin(array $value){
        $this->data[$this->popField()]['$nin'] = $value;
        return $this;
    }

    /**
     * @param bool $value
     * @return $this
     */
    public function exists($value){
        $this->data[$this->popField()]['$exists'] = $value;
        return $this;
    }

    /**
     * @param string $pattern
     * @param string $flags
     * @return $this
     */
    public function pattern($pattern, $flags = ''){
        $regex = \MongoRegex($pattern);
        if ($flags)
            $regex->flags = $flags;
        $this->data[$this->popField()]['$exists'] = $regex;
        return $this;
    }

    public function sort($value){
        $value = (strtolower($value) === 'asc' ? 1 : (strtolower($value) === 'desc' ? -1 : $value));
        $this->data['$sort'][$this->popField()] = $value;
        return $this;
    }

    public function getData(){
        return $this->data;
    }
}
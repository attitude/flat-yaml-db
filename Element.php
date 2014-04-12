<?php

namespace attitude;

use \Symfony\Component\Yaml\Yaml;
use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

class FlatYAMLDB_Element
{
    protected $filepath;
    protected $cache_filepath = null;

    protected $data = array();
    protected $indexes = array();

    public function __construct($filepath, $indexes = array(), $nocache = false)
    {
        if (!is_string($filepath) || strlen(trim($filepath))===0 || !realpath($filepath)) {
            throw new HTTPException(500, 'Path to YAML source does not exit or value is invalid.');
        }

        $this->filepath       = $filepath;
        $this->cache_filepath = $filepath.DependencyContainer::get('yamldb::cacheAdd', '.json');

        foreach ($this->indexes as $index) {
            if (is_string($index) && strlen(trim($index)) > 0) {
                $this->indexes[] = $index;
            }
        }

        if ($nocache || $this->cacheNeedsReload()) {
            header('X-Using-DB-Cache: false');

            $this->loadYAML();
        } else {
            header('X-Using-DB-Cache: true');

            $this->loadCache();
        }

        return $this;
    }

    protected function loadYAML()
    {
        $db = explode('\n...', trim(file_get_contents($this->filepath)));

        foreach ($db as $document) {
            $document = trim($document);

            if (strlen($document)===0) {
                continue;
            }

            $document = substr($document, 0, 3) === '---' ? $document."\n..." : "---\n".$document."\n...";
            $data = Yaml::parse($document);
            if (isset($data['_id']) && isset($data['_type'])) {
                $this->data[$data['_type'].'.'.$data['_id']] = $data;
            }
        }

        $this->createDBIndex();
        $this->storeCache();

        return $this;
    }

    protected function createDBIndex()
    {
        return $this;
    }

    protected function addIndex($index, $key, $value)
    {
        if (!isset($this->indexes[$index])
         || !isset($this->indexes[$index][$key])
         || !in_array($value, $this->indexes[$index][$key])
        ) {
            $this->indexes[$index][$key][] = $value;
        }

        return $this;
    }

    protected function searchIndex($index, $value)
    {
        if ($index==='_id' && isset($this->data[$value])) {
            return (array) $value;
        }

        if (isset($this->indexes[$index][$value])) {
            return $this->indexes[$index][$value];
        }

        return array();
    }

    protected function loadCache()
    {
        $cache = json_decode(file_get_contents($this->cache_filepath), true);

        if (!isset($cache['indexes']) || !isset($cache['data'])) {
            throw new Exception(500, 'Cache is damadged');
        }

        $this->indexes = $cache['indexes'];
        $this->data    = $cache['data'];

        return $this;
    }

    protected function storeCache()
    {
        file_put_contents($this->cache_filepath, json_encode(array(
            'indexes' => $this->indexes,
            'data'    => $this->data
        ), JSON_PRETTY_PRINT));

        return $this;
    }

    protected function cacheNeedsReload()
    {
        if (! file_exists($this->cache_filepath)) {
            return true;
        }

        if (filemtime($this->cache_filepath) < filemtime($this->filepath)) {
            return true;
        }

        return false;
    }
}

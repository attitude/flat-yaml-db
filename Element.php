<?php

namespace attitude;

use \Symfony\Component\Yaml\Yaml;
use \attitude\Elements\HTTPException;
use \attitude\Elements\Singleton_Prototype;
use \attitude\Elements\DependencyContainer;

class FlatYAMLDB_Element extends Singleton_Prototype
{
    private $data = array();
    private $indexes = array();

    protected function __construct()
    {
        if (! DependencyContainer::get('yamldb::cache') || $this->cacheNeedsReload()) {
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
        $db = explode('...', file_get_contents(DependencyContainer::get('yamldb::source')));

        foreach ($db as $document) {
            $document = trim($document);

            if (strlen($document)===0) {
                continue;
            }

            $document = $document."\n...";
            $data = Yaml::parse($document);
            if (isset($data['collection']['id'])) {
                $this->data[$data['collection']['id']] =$data;
            }
        }

        $this->createDBIndex();
        $this->storeCache();

        return $this;
    }

    protected function createDBIndex()
    {
        foreach ($this->data as $document) {
            foreach (DependencyContainer::get('yamldb::indexes', array()) as $meta => $indexes) {
                if (! isset($document[$meta])) {
                    continue;
                }

                foreach ((array) $indexes as $index) {
                    if ($index==='id') {
                        continue;
                    }

                    if (isset($document[$meta][$index])) {
                        $data =& $document[$meta][$index];
                        if(is_array($data)) {
                            foreach ($data as &$subdata) {
                                if (! is_array($subdata)) {
                                    $this->addIndex($meta.'::'.$index, $subdata, $document['collection']['id']);
                                }
                            }
                        } else {
                            $this->addIndex($meta.'::'.$index, $data, $document['collection']['id']);
                        }
                    }
                }
            }
        }

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

    protected function searchIndex($index, $value, $context = 'collection')
    {
        if ($index==='id' && isset($this->data[$value])) {
            return (array) $value;
        }

        if (isset($this->indexes[$context.'::'.$index][$value])) {
            return $this->indexes[$context.'::'.$index][$value];
        }

        return array();
    }

    public function query($query)
    {
        foreach($query as $search => $value) {
            $subsets[] = $this->searchIndex($search, $value);
        }

        $intersection = null;

        foreach ($subsets as $subset) {
            if (empty($subset)) {
                $intersection = array();

                break;
            }

            if ($intersection == null) {
                $intersection = $subset;

                continue;
            }

            $intersection = array_intersect($intersection, $subset);
        }

        $results = array();

        foreach (array($intersection) as $ids) {
            foreach ($ids as $id) {
                $results[] = $this->data[$id];
            }
        }

        if (empty($results)) {
            throw new HTTPException(404, 'Your query returned zero results');
        }

        return $results;
    }

    protected function loadCache()
    {
        return $this;
    }

    protected function storeCache()
    {
        file_put_contents(DependencyContainer::get('yamldb::cache.source'), json_encode(array(
            'indexes' => $this->indexes,
            'data'    => $this->data
        ), JSON_PRETTY_PRINT));

        return $this;
    }

    protected function cacheNeedsReload()
    {
        if (! file_exists(DependencyContainer::get('yamldb::cache.source'))) {
            return true;
        }

        if (filemtime(DependencyContainer::get('yamldb::cache.source')) < filemtime(DependencyContainer::get('yamldb::source'))) {
            return true;
        }

        return false;
    }

    public function getCollection($uri = '/')
    {
        return $this->query(array(
            "type" => "page",
            "route" => $uri
        ));
    }
}

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
    protected $index_keys = array();

    public function __construct($filepath, $index_keys = array(), $nocache = false)
    {
        if (!is_string($filepath) || strlen(trim($filepath))===0 || !realpath($filepath)) {
            throw new HTTPException(500, 'Path to YAML source does not exit or value is invalid.');
        }

        $this->filepath       = $filepath;

        // Store cache as hidden `/path/to/.db_name.yaml.json` file next to the `/path/to/db_name.yaml`
        $this->cache_filepath = dirname($filepath).'/.'.trim(basename($filepath), '.').DependencyContainer::get('yamldb::cacheAdd', '.json');

        foreach ($index_keys as $index_key) {
            if (is_string($index_key) && strlen(trim($index_key)) > 0) {
                $this->index_keys[] = $index_key;
            }
        }

        if ($nocache || $this->cacheNeedsReload()) {
            header('X-Using-DB-Cache: false');

            $this->loadYAML();
        } else {
            header('X-Using-DB-Cache: true');

            try {
                $this->loadCache();
            } catch (HTTPException $e) {
                header('X-Using-DB-Cache: false');
            }
        }


        // Something might get wrong last time
        if (empty($this->data)) {
            header('X-Using-DB-Cache: false');
            $this->loadYAML();
        }

        return $this;
    }

    protected function loadYAML()
    {
        $db = explode("\n...", trim(file_get_contents($this->filepath)));

        foreach ($db as $document) {
            $document = trim($document);

            if (strlen($document)===0) {
                continue;
            }

            $document = substr($document, 0, 3) === '---' ? $document."\n..." : "---\n".$document."\n...";

            try {
                $this->addData(Yaml::parse($document));
            } catch (\Exception $e) {
                trigger_error($e->getMessage()." in document:\n".$document);
            }
        }

        $this->createDBIndex();
        $this->storeCache();

        return $this;
    }

    protected function addData($data)
    {
        $this->data[] = $data;

        return $this;
    }

    protected function createDBIndex()
    {
        foreach ($this->data as $array_index => $document) {
            foreach ((array) $this->index_keys as $index_key) {
                if (isset($document[$index_key])) {
                    $data =& $document[$index_key];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex($index_key, $subdata, $array_index);
                            }
                        }
                    } else {
                        $this->addIndex($index_key, $data, $array_index);
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

    private function mergeRoutes($parent, $self) {
        // Do nothing when not needed
        if (is_string($self) && !strstr($self, './')) {
            return $self;
        }
        if (is_array($self)) {
            $found = false;
            foreach ($self as &$v) {
                if (strstr($v, './')) {
                    $found = true;
                }
            }
            if (!$found) {
                return $self;
            }
        }


        if (is_array($parent) && is_string($self)) {
            foreach ($parent as $k => &$v) {
                if (strstr($self, '../')) {
                    $v = str_replace('../', dirname(rtrim($v, '/')).'/', $self);
                } elseif (strstr($self, './')) {
                    $v = str_replace('./', rtrim($v, '/').'/', $self);
                }
            }

            return $parent;
        }

        if (is_string($parent) && is_array($self)) {
            foreach ($self as $k => &$v) {
                if (strstr($self, '../')) {
                    $v = str_replace('../', dirname(rtrim($parent, '/')).'/', $v);
                } elseif (strstr($self, './')) {
                    $v = str_replace('./', rtrim($parent, '/').'/', $v);
                }
            }

            return $self;
        }

        if (is_array($parent) && is_array($self)) {
            foreach ($parent as $k => &$v) {
                if (!array_key_exists($k, $self)) {
                    unset($parent[$k]);

                    continue;
                }

                if (strstr($self[$k], '../')) {
                    $v = str_replace('../', dirname(rtrim($v, '/')).'/', $self[$k]);
                } elseif (strstr($self[$k], './')) {
                    $v = str_replace('./', rtrim($v, '/').'/', $self[$k]);
                }
            }

            return $parent;
        }

        if (is_string($parent) && is_string($self)) {
            if (strstr($self, '../')) {
                return str_replace('../', dirname(rtrim($parent, '/')).'/', $self);
            } elseif (strstr($self, './')) {
                return str_replace('./', rtrim($parent, '/').'/', $self);
            }
        }

        throw new HTTPException(500, 'Expecting string or array of routes to merge.');
    }

    public function query($query, $keep_metadata = false)
    {
        $limit  = (isset($query['_limit']))  ? $query['_limit']  : 0;
        $offset = (isset($query['_offset'])) ? $query['_offset'] : 0;

        unset($query['_limit'], $query['_offset']);

        if (isset($query['_id'])) {
            if (!isset($query['_type'])) {
                throw new HTTPException(500, 'Querying by id requires passing type');
            }

            $query['_id'] = $query['_type'].'.'.$query['_id'];
        }

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
                $result = $this->data[$id];

                // Resolve relative paths
                $replacementNeeded = false;

                if (isset($result['_collection']) && isset($result['route'])) {
                    if (is_array($result['route'])) {
                        foreach ($result['route'] as &$resultRoute) {
                            if (!strstr('^@starts@^'.$resultRoute, '^@starts@^'.'/')) {
                                $replacementNeeded = true;
                            }
                        }
                    } elseif (is_string($result['route'])) {
                        if (!strstr('^@starts@^'.$result['route'], '^@starts@^'.'/')) {
                            $replacementNeeded = true;
                        }
                    }
                }

                if ($replacementNeeded) {
                    try {
                        $parent = $this->query(array('_type' => 'collection', '_id' => $result['_collection']), $keep_metadata);

                        if ($parent['route']) {
                            $result['route'] = $this->mergeRoutes($parent['route'], $result['route']);
                        }
                    } catch (HTTPException $e) {
                        trigger_error('Failed to find parent collection with `_id` '.$result['_collection']);
                    }
                }

                // Dynamic data
                foreach ($result as $k => &$v) {
                    if (is_string($v)) {
                        if (preg_match('/\{\{([^\}]+?)\}\}/', $v, $matches)) {
                            if (array_key_exists($matches[1], $result)) {
                                $v = str_replace($matches[0], $result[$matches[1]], $v);
                            }
                        }
                    } elseif (is_array($v)) {
                        foreach ($v as &$vv) {
                            if (is_string($vv)) {
                                if (preg_match('/\{\{([^\}]+?)\}\}/', $vv, $matches)) {
                                    if (array_key_exists($matches[1], $result)) {
                                        $vv = str_replace($matches[0], $result[$matches[1]], $vv);
                                    }
                                }
                            }
                        }
                    }
                }

                if (!isset($result['link']) && isset($result['route']) && isset($result['_type']) && isset($result['_id'])) {
                    $result['link'] = array('link()' => array('_type' => $result['_type'], '_id' => $result['_id']));
                }

                if ($keep_metadata) {
                    $results[] = $result;
                } else {
                    foreach ($result as $k => &$v) {
                        if ($k[0]==='_') {
                            unset($result[$k]);
                        }
                    }

                    $results[] = $result;
                }
            }
        }

        if (empty($results)) {
            trigger_error('404: '.json_encode($query));
            throw new HTTPException(404, 'Your query returned zero results');
        }

        if (isset($query['_id']) || $limit===1) {
            return $results[0];
        }

        return $results;
    }

    protected function loadCache()
    {
        $cache = json_decode(file_get_contents($this->cache_filepath), true);

        if (!isset($cache['indexes']) || !isset($cache['data'])) {
            throw new HTTPException(500, 'Cache is damadged');
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
        )));

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

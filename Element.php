<?php

namespace attitude;

use \Symfony\Component\Yaml\Yaml;
use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

class FlatYAMLDB_Element
{
    protected $filepath;
    protected $filepaths = array();
    /**
     * @var string $instanceType Please provide custom namespace for your class
     */
    protected $instanceType = 'default';

    protected $cache_filepath = null;

    protected $now = 0;

    protected $data = array();
    protected $indexes = array();
    protected $index_keys = array();

    protected $nocache;
    protected $cache;

    public function __construct($filepath, $index_keys = array(), $nocache = false)
    {
        $this->now = time();
        $this->nocache = !! $nocache;
        $this->cache   = !  $nocache;

        foreach ($index_keys as $index_key) {
            if (is_string($index_key) && strlen(trim($index_key)) > 0) {
                $this->index_keys[] = $index_key;
            }
        }

        if (!is_string($filepath) || strlen(trim($filepath))===0 || !realpath($filepath)) {
            throw new HTTPException(500, 'Path to YAML source does not exit or value is invalid.');
        }

        // @TODO: replace with index
        $this->filepath = $filepath;

        if ($nocache) {
            header('X-Using-DB-Cache: false');
        } else {
            header('X-Using-DB-Cache: true');
        }

        $this->connectDatabase((array) $filepath);

        return $this;
    }

    protected function fileStat($filepath)
    {
        $now = date('c', $this->now);

        $dates = array(
            'created' => $now,
            'updated' => $now,
        );

        $stat = stat($filepath);

        if (isset($stat['ctime'])) {
            $dates['created'] = date('c', $stat['ctime']);
        }

        if (isset($stat['mtime'])) {
            $dates['updated'] = date('c', $stat['mtime']);
        }

        return $dates;
    }

    protected function loadYAMLFile($filepath)
    {
        if (!is_string($filepath) || strlen(trim($filepath))===0 || !realpath($filepath)) {
            throw new HTTPException(500, 'Path to YAML source does not exit or value is invalid.');
        }

        $data = [];

        $dates = $this->fileStat($filepath);

        $db = preg_split("/^[ \t]*...[ \t]*\n/m", trim(file_get_contents($filepath)));

        foreach ($db as $document) {
            if (strlen($document)===0) {
                continue;
            }

            $padding = strlen($document) - strlen(ltrim($document));
            $document = trim(preg_replace('/^.{'.$padding.'}/m', '', $document));

            $document = substr($document, -3) === '...' ? "---\n".$document : "---\n".$document."\n...";

            try {
                $document = Yaml::parse($document);

                if (!isset($document['created'])) {
                    $document['created'] = $dates['created'];
                }

                if (!isset($document['updated'])) {
                    $document['updated'] = $dates['updated'];
                }

                $data[] = $document;
            } catch (\Exception $e) {
                trigger_error($e->getMessage()." in document:\n".$document);
            }
        }

        $this->storeCache($data, $filepath);

        return $data;
    }

    protected function connectDatabase(array $YAMLfiles)
    {
        foreach ($YAMLfiles as $filePath) {
            $data = null;

            // Remember the index
            $filePathIndex = sizeof($this->filePaths);

            // Add file source
            $this->filePaths[] = $filePath;

            if ($this->nocache || $this->cacheNeedsReload($filepath)) {
                try {
                    $data = $this->loadYAMLFile($filePath);
                } catch (HTTPException $e) {
                    trigger_error('Failed to load '.basename($filePath).'.');
                }
            } else {
                try {
                    $data = $this->loadCache($filepath);
                } catch (HTTPException $e) {
                    header('X-Using-DB-Cache: partial');
                }
            }

            // Something might get wrong last time, try once again
            if (empty($data)) {
                try {
                    $data = $this->loadYAMLFile($filePath);
                } catch (HTTPException $e) {
                    trigger_error('Failed to load '.basename($filePath).'.');
                }
            } else {
                foreach ($data as &$document) {
                    // Store any documents in cache...
                    $this->addData($document);
                    // ... and remember which document was in which file
                    $this->addIndex('$$filepaths$$', $filePath, $filePathIndex);
                }
            }
        }

        $this->createDBIndex();

        // Walk data and resolve any replacements
        foreach ($this->data as &$result) {
            // Resolve relative paths
            $replacementNeeded = false;

            if (isset($result['collection']) && isset($result['route'])) {
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
                    $parent = $this->query(array('type' => 'collections', 'id' => $result['collection']), true);

                    if ($parent['route']) {
                        $result['route'] = $this->expandRelativeRoutes($parent['route'], $result['route']);
                    }
                } catch (HTTPException $e) {
                    trigger_error('Failed to find parent collection with `id` '.$result['collection']);
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
        }

        // Flush indexes
        $this->indexes = array();

        // Recreate indexes and store cache
        $this->createDBIndex();

        // $indexFilePath = $this->cacheFilePath
        // $this->storeCache($filepath);

        return $this;
    }

    protected function addData($data)
    {
        $this->data[] = $data;

        return $this;
    }

    protected function createDBIndex()
    {
        $index_keys = array();

        foreach ((array) $this->index_keys as $k) {
            $index_keys[] = $k;           // Always public
            $index_keys[] = '_'.$k;       // Internal, maybe public (internal loops etc.)
            $index_keys[] = '__'.$k.'__'; // Internal, never public
        }

        foreach ($this->data as $array_index => $document) {
            foreach ((array) $index_keys as $index_key) {
                if (isset($document[$index_key])) {
                    $data =& $document[$index_key];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex(trim($index_key, '_'), $subdata, $array_index);
                            }
                        }
                    } else {
                        $this->addIndex(trim($index_key, '_'), $data, $array_index);
                    }
                }
            }
        }

        return $this;
    }

    /**
     * Indexes object's value under an index
     *
     * @param string $index        Attribute being indexed
     * @param string $indexedValue Attribute value (value usualy being searched for)
     * @param string $objectID     ID or any other object identificator
     *
     */
    protected function addIndex($index, $indexedValue, $objectID)
    {
        if (!isset($this->indexes[$index])
         || !isset($this->indexes[$index][$indexedValue])
         || !in_array($objectID, $this->indexes[$index][$indexedValue])
        ) {
            $this->indexes[$index][$indexedValue][] = $objectID;
        }

        return $this;
    }

    protected function searchIndex($index, $value)
    {
        if (isset($this->indexes[$index][$value])) {
            return $this->indexes[$index][$value];
        }

        return array();
    }

    private function expandRelativeRoutes($parent, $self)
    {
        // Fixes undesired pass by refference issue
        $self = str_replace('', '', $self);
        $parent = str_replace('', '', $parent);

        // Do nothing when not needed
        if (is_string($self) && !strstr($self, './')) {
            return $self;
        }

        // Check if replacement is needed
        if (is_array($self)) {
            $found = false;
            foreach ($self as &$v) {
                if (strstr($v, './')) {
                    $found = true;
                }
            }

            // Break
            if (!$found) {
                return $self;
            }
        }

        // When parent route is an array of items, self is a string
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

        // When parent route is a string of items, self is an array
        if (is_string($parent) && is_array($self)) {
            foreach ($self as $k => &$v) {
                if (strstr($v, '../')) {
                    $v = str_replace('../', dirname(rtrim($parent, '/')).'/', $v);
                } elseif (strstr($v, './')) {
                    $v = str_replace('./', rtrim($parent, '/').'/', $v);
                }
            }

            return $self;
        }

        // When parent route is an array of items, self is an array
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

        // When parent route is string of items, self is a string
        if (is_string($parent) && is_string($self)) {
            if (strstr($self, '../')) {
                return str_replace('../', dirname(rtrim($parent, '/')).'/', $self);
            } elseif (strstr($self, './')) {
                return str_replace('./', rtrim($parent, '/').'/', $self);
            }
        }

        throw new HTTPException(500, 'Expecting string or array of routes to merge.');
    }

    /**
    * Pluralizes English nouns.
    *
    * Source: http://www.akelos.com
    *
    * @access public
    * @static
    * @param  string $word English noun to pluralize
    * @return string       Plural noun
    */
    protected function pluralize($word)
    {
        $plural = array(
            '/(quiz)$/i' => '1zes',
            '/^(ox)$/i' => '1en',
            '/([m|l])ouse$/i' => '1ice',
            '/(matr|vert|ind)ix|ex$/i' => '1ices',
            '/(x|ch|ss|sh)$/i' => '1es',
            '/([^aeiouy]|qu)ies$/i' => '1y',
            '/([^aeiouy]|qu)y$/i' => '1ies',
            '/(hive)$/i' => '1s',
            '/(?:([^f])fe|([lr])f)$/i' => '12ves',
            '/sis$/i' => 'ses',
            '/([ti])um$/i' => '1a',
            '/(buffal|tomat)o$/i' => '1oes',
            '/(bu)s$/i' => '1ses',
            '/(alias|status)/i'=> '1es',
            '/(octop|vir)us$/i'=> '1i',
            '/(ax|test)is$/i'=> '1es',
            '/s$/i'=> 's',
            '/$/'=> 's'
        );

        $uncountable = array('equipment', 'information', 'rice', 'money', 'species', 'series', 'fish', 'sheep');
        $irregular = array('person' => 'people', 'man' => 'men', 'child' => 'children', 'sex' => 'sexes', 'move' => 'moves');
        $lowercased_word = strtolower($word);

        foreach ($uncountable as $_uncountable){
            if(substr($lowercased_word,(-1*strlen($_uncountable))) == $_uncountable){
                return $word;
            }
        }

        foreach ($irregular as $_plural=> $_singular){
            if (preg_match('/('.$_plural.')$/i', $word, $arr)) {
                return preg_replace('/('.$_plural.')$/i', substr($arr[0],0,1).substr($_singular,1), $word);
            }
        }

        foreach ($plural as $rule => $replacement) {
            if (preg_match($rule, $word)) {
                return preg_replace($rule, $replacement, $word);
            }
        }

        return false;
    }

    public function query($query, $keep_metadata = false)
    {
        $limit   = (isset($query['_limit']))   ? $query['_limit']   : 0;
        $offset  = (isset($query['_offset']))  ? $query['_offset']  : 0;
        $orderby = (isset($query['_orderby'])) ? explode(' ', $query['_orderby']) : array('order', 'ASC');

        if ($orderby && isset($orderby[1]) && !($orderby[1] === 'ASC' || $orderby[1] === 'DESC')) {
            throw new HTTPException(500, 'Query Error: If specified, ordering method must be either `ASC` or `DESC`.');
        }

        unset($query['_limit'], $query['_offset'], $query['_orderby']);

        if (isset($query['id'])) {
            if (!isset($query['type'])) {
                throw new HTTPException(500, 'Querying by id requires passing type');
            }

            if ($query['type'] !== $this->pluralize($query['type'])) {
                throw new HTTPException(500, 'Use plural form of type to keep your data <a href="http://jsonapi.org/format/#document-structure-compound-documents">JSON API compatible</a>');
            }
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

                if (isset($result['route']) && method_exists($this, 'hrefToItem')) {
                    try {
                        $result['href'] = $this->hrefToItem($result);
                    } catch (HTTPException $e) { /* Silence exceptions */}
                }

                if (isset($result['route']) && method_exists($this, 'linkToItem')) {
                    try {
                        $result['link'] = $this->linkToItem($result);
                    } catch (HTTPException $e) { /* Silence exceptions */}
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

        if (isset($query['id']) || $limit===1) {
            return $results[0];
        }

        self::orderby($results, $orderby);

        return $results;
    }

    /**
     * Modify results according to order parameters
     *
     * Method modifies parrameters passed by refference
     *
     * @param array $array
     * @param array|bool $order
     * @return bool Returs `false` when nothing changed
     *
     */
    public static function orderby(&$array, &$orderby)
    {
        if (!is_array($array)) {
            return false;
        }

        // Try to reorder if the `order` attribute exists
        usort($array, function ($a, $b) use ($orderby) {
            $attr = $orderby && isset($orderby[0]) && is_string($orderby[0]) && strlen(trim($orderby[0])) > 0 ? $orderby[0] : 'order';

            $orderA = isset($a[$attr]) ? $a[$attr] : null;
            $orderB = isset($b[$attr]) ? $b[$attr] : null;

            // For now
            if (is_array($orderA) || is_array($orderB)) {
                return 0;
            }

            // Handle undefined values: numbers
            if (is_numeric($orderA) && $orderB === null) {
                $orderB = $orderA + 1;
            }

            // Handle undefined values: numbers
            if (is_numeric($orderB) && $orderA === null) {
                $orderA = $orderB + 1;
            }

            // Handle undefined values: string
            if (is_string($orderA) && $orderB === null) {
                $orderB = $orderA;
                $orderB[0] = $orderB[0] + 1;
            }

            // Handle undefined values: string
            if (is_string($orderB) && $orderA === null) {
                $orderA = $orderB;
                $orderA[0] = $orderA[0] + 1;
            }

            if ($orderA == $orderB) {
                return 0;
            }

            return ($orderA < $orderB) ? -1 : 1;
        });

        if ($orderby && strtoupper($orderby[1]) === 'DESC') {
            $array = array_reverse($array);
        }

        return true;
    }

    /**
     * Returns generated JSON cache path for filepath
     *
     * @param string $filepaht
     * @return string
     *
     */
    protected function cacheFilePath($filepath, $prefix = '')
    {
        return dirname($filepath).'/.'.trim(basename($filepath), '.').$prefix.DependencyContainer::get('yamldb::cacheAdd', '.json');
    }

    protected function loadCache($filepath)
    {
        return json_decode(file_get_contents($this->cacheFilePath($filepath)), true);

        if (!isset($cache['indexes']) || !isset($cache['data'])) {
            throw new HTTPException(500, 'Cache is damadged');
        }

        return $this;
    }

    protected function storeCache($data, $filepath)
    {
        file_put_contents($this->cacheFilePath($filepath), json_encode($data));

        return $this;
    }

    protected function cacheNeedsReload($filepath)
    {
        // Store cache as hidden `/path/to/.db_name.yaml.json` file next to the `/path/to/db_name.yaml`
        $cache_filepath = $this->cacheFilePath($filepath);

        if (! file_exists($cache_filepath)) {
            return true;
        }

        if (filemtime($cache_filepath) < filemtime($filepath)) {
            return true;
        }

        return false;
    }
}

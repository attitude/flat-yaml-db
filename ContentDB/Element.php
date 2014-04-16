<?php

namespace attitude\FlatYAMLDB;

use \attitude\FlatYAMLDB_Element;

use \attitude\Elements\HTTPException;
use \attitude\Elements\DependencyContainer;

class ContentDB_Element extends FlatYAMLDB_Element
{
    protected function addData($data)
    {
        if (isset($data['_id']) && isset($data['_type'])) {
            $this->data[$data['_type'].'.'.$data['_id']] = $data;
        }

        return $this;
    }

    protected function createDBIndex()
    {
        foreach ($this->data as $document) {
            if (!isset($document['_id']) || !isset($document['_type'])) {
                continue;
            }

            foreach ((array) $this->index_keys as $index_key) {
                if ($index_key==='_id') {
                    continue;
                }

                if (isset($document[$index_key])) {
                    $data =& $document[$index_key];
                    if(is_array($data)) {
                        foreach ($data as &$subdata) {
                            if (! is_array($subdata)) {
                                $this->addIndex($index_key, $subdata, $document['_type'].'.'.$document['_id']);
                            }
                        }
                    } else {
                        $this->addIndex($index_key, $data, $document['_type'].'.'.$document['_id']);
                    }
                }
            }
        }

        return $this;
    }

    private function linkToData($data)
    {
        if (is_assoc_array($data)) {
            return $this->linkToItem($data);
        }

        $links = array();
        foreach ($data as $subdata) {
            $links[] = $this->linkToItem($subdata);
        }

        return $links;
    }

    private function linkToItem($data)
    {
        $result = array();

        if (isset($data['navigationTitle'])) {
            $result['text'] = $data['navigationTitle'];
        } elseif (isset($data['title'])) {
            $result['text'] = $data['title'];
        } else {
            $result['text'] = '';
        }

        $result['href'] = $this->hrefToItem($data);

        foreach ((array) $result['href'] as $url) {
            if ($url===$_SERVER['REQUEST_URI']) {
                $result['current'] = true;

                break;
            }
        }

        if (isset($data['title'])) {
            $result['title'] = $data['title'];
        }

        return $result;
    }

    public function hrefToItem($data)
    {
        if (isset($data['route'])) {
            $language = DependencyContainer::get('global::language');

            if ($language) {
                if (is_string($data['route'])) {
                    $data['route'] = "/{$language['code']}".$data['route'];
                } else {
                    foreach ($data['route'] as &$v) {
                        $v = "/{$language['code']}".$v;
                    }
                }
            }

            return $data['route'];
        }

        return '#missingroute';
    }

    public function expanderLink($args)
    {
        try {
            $data = $this->query($args);

            return $this->linkToData($data);
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand link().');
    }

    public function expanderQuery($args)
    {
        if (is_assoc_array($args)) {
            $args = array($args);
        }

        $results = array();

        try {
            foreach($args as $subargs) {
                $subresults = $this->query($subargs);
                $results = array_merge($results, $subresults);
            }
        } catch (HTTPException $e) {
            throw $e;
        }

        if (!empty($results)) {
            return $results;
        }

        throw new HTTPException(404, 'Failed to expand query().');
    }

    public function expanderHref($args)
    {
        try {
            $data = $this->query($args);

            return $this->hrefToItem($data);
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand href().');
    }

    public function expanderTitle($args)
    {
        try {
            $data = $this->query($args);

            if (isset($data['title'])) {
                return $data['title'];
            }

            if (isset($data['navigationTitle'])) {
                return $data['navigationTitle'];
            }

            return 'N/A';
        } catch (HTTPException $e) {
            throw $e;
        }

        throw new HTTPException(404, 'Failed to expand href().');
    }

    public function getCollection($uri = '/')
    {
        // In most cases we look for a collection: an archive or parent page,
        // listing of related items
        try {
            $data = $this->query(array(
                '_type' => "collection",
                'route' => $uri,
                '_limit' => 1
            ), true);
        } catch (HTTPException $e) {
            // But maybe we're about to displate one of the items
            $data = $this->query(array(
                '_type' => "item",
                'route' => $uri,
                '_limit' => 1
            ), true);
        }

        $result = array(
            'website'         => null,
            'websiteSettings' => null,
            'item'            => $data,
            'collection'      => null,
            'items'           => null,
            'template'        => null,
            'shoppingCart'    => null,
            'showCart'        => null,
            'calendarView'    => null,
            'pagination'      => null
        );

        // Walk item data
        foreach ($data as $k => &$v) {
            // Expect value to be on root:
            if (array_key_exists($k, $result)) {
                $result[$k] = $v;
            } else {
                // Ex: _type: blogpost, _collection: blog
                if ($data['_type']==='item') {
                    $result['item'][$k] = $v;
                } else {
                    // _type: collection
                    $result['item'][$k] = $v;
                    $result['collection'][$k] = $v;
                }
            }
        }

        if (!isset($result['items'])) {
            $result['items'] = array('query()' => array('_collection' => $data['_id']));
        }

        if (!isset($result['website'])) {
            $result['website'] = array('query()' => array('_limit' => 1, '_type' => 'collection', 'route' => '/'));
        }

        if (empty($result['collection']) && isset($result['item']['_collection'])) {
            try {
                $result['collection'] = $this->query(array('_type' => 'collection', '_id' => $result['item']['_collection']), true);
            } catch(HTTPException $e) {
                throw new HTTPException(404, 'Item has no collection defined');
            }
        }

        if (!isset($result['collection']['breadcrumbs'])) {
            if ($data['_type']==='item') {
                $result['collection']['breadcrumbs'] = $this->generateBreadcrumbs(array('_type' => $result['item']['_type'], '_id' => $result['item']['_id']));
            } else {
                $result['collection']['breadcrumbs'] = $this->generateBreadcrumbs(array('_type' => $result['collection']['_type'], '_id' => $result['collection']['_id']));
            }
        }

        return $result;
    }

    public function generateBreadcrumbs($args, $children = false, $levels = 0)
    {
        static $traverse = 0;

        $traverse++;

        $breadcrumbs = array();
        try {
            $item = $this->query($args, true);

            $breadcrumbs[] = $this->linkToData($item);

            if (isset($item['_collection'])) {
                $breadcrumbs = array_merge($breadcrumbs, $this->generateBreadcrumbs(array('_type' => 'collection', '_id' => $item['_collection'])));
            }
        } catch (HTTPException $e) {
        }
        $traverse--;

        if ($traverse==0) {
            $breadcrumbs[(sizeof($breadcrumbs)-1)]['home'] = true;
            $breadcrumbs[0]['current'] = true;

            return array_reverse($breadcrumbs);
        }

        return $breadcrumbs;
    }
}

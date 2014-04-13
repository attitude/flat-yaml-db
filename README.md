Flat JAML Database
==================

If you are in need of data-base for a simple, mostly content web project, near
static (pre-generated) webpage, this might be the solution for you.

This component is in development and operates in read-only mode.
Write functionality is on the roadmap.

Base Database Class: FlatYAMLDB_Element
---------------------------------------

- load of document file (one file can include many documents)
- caching as JSON in `.json` file next to the source
- default methods to set and search indexes

Extended database classes
-------------------------

### FlatYAMLDB\ContentDB_Element

- using keys `_id` and `_type` is required
- includes look-up method for web-page routes and its' collections and create
  page hierarchy breadcrumbs
- simple queries on defined indexes
- expanders for [Mustache Atomic Loader](https://github.com/attitude/mustache-atomic-loader):
- `link()`, which uses:
  - `title()`
  - `href()`
- `query()`
- using expanders can produce full representations needed to render HTML pages
  like [Squarespace does](developers.squarespace.com/view-json-data/)

### FlatYAMLDB\TranslationsDB

- similar translations experience to AngularJS
- possible reuse of same translations for front-end client one page apps
- works with [Mustache Atomic Loader][] and [Mustache Data Preprocessor][], see
  for more in depth use cases

[Mustache Atomic Loader]: https://github.com/attitude/mustache-atomic-loader
[Mustache Data Preprocessor]: https://github.com/attitude/mustache-data-preprocessor


Usage: A very simple web service
--------------------------------

> **HEADS UP:** Checking for `$_GET` variable to determine whether user is
> logged in is considered **a really bad practice** and is used only for
> purposes of giving a quick example.

### Pretty URLs: `.htaccess`

Required, use any [WordPress compatible `.htaccess`][WPhtaccess], like:

```
RewriteEngine On
RewriteRule ^index\.php$ - [L]
RewriteRule . /index.php [L]
```
[WPhtaccess]: https://codex.wordpress.org/htaccess

### The server app: `index.php`

```php
<?php

// Absolute path to this directory
define('ROOT_DIR', dirname(__FILE__));

// Example function for seeing protected content
function is_user_logged_in()
{
    return $_GET['password']==='nbusr123';
}

// Load Database
$db = new \attitude\FlatYAMLDB_Element(
    ROOT_DIR.'/testdb.yaml', // Path to the database file
    array('route'),          // One index on route attribute
    true                     // Forces YAML pasing, set FALSE or remove to use cache
);

// Query DB and show results
try {
    $results = $db->query(array('route'=> $_SERVER['REQUEST_URI'], '_limit' => 1));

    if (! $result['password'] || is_user_logged_in()) {
        echo $results['content'];
    }

    die('You need to be logged in to see this page.');
} catch (HTTPException $e) {
    $e->header();

    if ($e->isError()) {
        echo $e->getMessage();
        die();
    }

    // Handle extra exceptions (like redirect should occur, etc.)
    //
    // ...
}
```

### Content of the database `testdb.yaml`

Database allows to load only one file. This example contains two documents
divided by  `---` (begin document) and `...` (end document).

If you wish to have 1 file to 1 database object, simply omit the `---` and `...`.

```yaml
---
_id: 1
title: Some longer page title
navigationTitle: Some Page
password: false
route: /some-page
content: |
  Consectetur adipisicing Lorem ad incididunt aute aute ex voluptate eiusmod
  id sunt. Aute fugiat qui sint est exercitation mollit enim nisi Lorem eiusmod
  enim. Mollit aute duis in veniam minim enim est.

  Elit Lorem magna id dolore ad ex occaecat veniam ea elit.
  Reprehenderit non sit elit et quis duis enim pariatur culpa.
  Tempor nisi qui non nisi Lorem elit in non. Elit ad ut incididunt
  sunt in ex et esse.
...
---
_id: 2
title: Some other page title
navigationTitle: Other Page
password: true
route: /other-page
content: |
    Fugiat duis in sunt pariatur non consectetur pariatur est occaecat deserunt.
    Non dolore esse qui nisi eiusmod cupidatat ad consequat cillum fugiat sit
    eiusmod. Deserunt ut occaecat ad consequat id laboris enim ex tempor tempor
    duis eu. Irure sit in sint qui mollit nulla dolore tempor qui.

    Fugiat cupidatat incididunt anim occaecat est laborum nisi quis qui amet
    nulla in non nostrud. Mollit in do do enim do consequat et aliquip voluptate
    dolore mollit et irure. Do nulla ex minim cillum culpa magna duis duis sunt
    aute nulla. Elit nostrud aute nulla sunt proident velit in duis in sit
    occaecat.
...
```

---

Enjoy and let me know what you think.

[@martin_adamko](https://twitter.com/martin_adamko)

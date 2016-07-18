# db

[![Software License][ico-license]](LICENSE)
[![Build Status][ico-travis]][link-travis]

[ico-license]: https://img.shields.io/badge/license-MIT-brightgreen.svg?style=flat-square
[ico-travis]: https://img.shields.io/travis/damner/db/master.svg?style=flat-square
[link-travis]: https://travis-ci.org/damner/db

# Usage

```php

// Connection

$db = new Db\Db([
	'host'     => 'localhost',
	'user'     => 'root',
	'password' => '',
	'dnname'   => 'db1',
	'charset'  => 'utf8',
], new Db\QueryCompiler(), new Psr\Log\NullLogger());

// Data

$db->createTable('authors', [
	'id'   => array('type' => 'INT', 'auto_increment' => true, 'primary' => true),
	'name' => array('type' => 'VARCHAR', 'length' => 64),
	'age'  => array('type' => 'INT'),
], 'InnoDb', 'All authors');

$db->createTable('books', [
	'id'        => array('type' => 'INT', 'auto_increment' => true, 'primary' => true),
	'title'     => array('type' => 'VARCHAR', 'length' => 255),
	'author_id' => array('type' => 'INT', 'index' => true),
], 'InnoDb', 'All books');

$db->addForeignKey('books', 'author_id', 'authors', 'id', null, 'cascade', 'cascade');

$db->beginTransaction();

$db->insertRows('authors', [
	['id' => 1, 'name' => 'John', 'age' => 42],
	['id' => 2, 'name' => 'Alex', 'age' => 35],
]);

$db->insertRows('books', [
	['title' => 'Book 1', 'author_id' => 1],
	['title' => 'Book 2', 'author_id' => 1],
	['title' => 'Book 3', 'author_id' => 2],
]);

$db->commit();

// Queries

$db->getAll('SELECT * FROM authors WHERE age >= ?', 18);

$db->getAll('SELECT * FROM authors WHERE id IN(?@)', [1, 2]);

$db->getRow('SELECT * FROM books WHERE id = ?', 1);

$db->getCol('SELECT ?F FROM ?F WHERE ?F != ?', 'id', 'books', 'id', 3);

$db->query('UPDATE authors SET ?% WHERE id = ?', [
	'name' => 'John',
	'age'  => 43,
], 1);

$id = $db->insert('INSERT INTO authors SET ?%', [
	'name' => 'James',
	'age'  => 57,
]);

$id = $db->insert('INSERT INTO authors (?@F) VALUES (?@)', [
	'name',
	'age',
], [
	'Daniel',
	51,
]);

// Cleanup

$db->dropTable('books');

$db->dropTable('authors');

```
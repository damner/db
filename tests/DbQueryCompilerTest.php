<?php

namespace UnitTests;

use Db\QueryCompiler;
use stdClass;

class QueryCompilerTest extends \PHPUnit_Framework_TestCase {
	protected static $compiler;

	public static function setUpBeforeClass() {
		$compiler = new QueryCompiler();

		$compiler->setEscaperFunction(function($value) {
			return addslashes($value);
		});

		self::$compiler = $compiler;
	}

	public static function tearDownAfterClass() {
		self::$compiler = null;
	}

	/**
	 * @dataProvider providerSinglePlaceholder
	 */
	public function testSinglePlaceholder($query, $argument, $result) {
		$this->assertSame($result, self::$compiler->compile($query, [$argument]));
	}

	public function providerSinglePlaceholder() {
		return [
			['?', 0, '\'0\''],
			['?', 1, '\'1\''],
			['?', -1, '\'-1\''],
			['?', PHP_INT_MAX , '\''.PHP_INT_MAX .'\''],
			['?', 0.0, '\'0\''],
			['?', 1.1, '\'1.1\''],
			['?', -1.1, '\'-1.1\''],
			['?', true, '\'1\''],
			['?', false, '\'0\''],
			['?', null, 'NULL'],
			['?', 'NULL', 'NULL'],
			['?', '', '\'\''],
			['?', 'a?\'"', '\'a?\\\'\\"\''],
			['?', 'йё', '\'йё\''],

			['?N', 'NULL', 'NULL'],
			['?N', '', ''],
			['?N', 'a', 'a'],

			['?F', 'a` ', '`a```'],
			['?F', 'йё', '`йё`'],
			['?F', 1, '`1`'],

			['?L', '', ''],
			['?L', 'a%_\'"', 'a\\%\\_\\\'\\"'],

			['?@', [], 'NULL'],
			['?@', [1], '\'1\''],
			['?@', [1, 2], '\'1\',\'2\''],
			['?@', ['1', '2'], '\'1\',\'2\''],
			['?@', [null, 'NULL', true, false, 0, -1.1, '', '?'], 'NULL,NULL,\'1\',\'0\',\'0\',\'-1.1\',\'\',\'?\''],

			['?@F', [], ''],
			['?@F', [1], '`1`'],
			['?@F', ['a` '], '`a```'],
			['?@F', ['a', 'b'], '`a`,`b`'],

			['?%', [], ''],
			['?%', ['a' => '', 'b' => null, 'c' => true, 'd' => 1, 'e' => -1.1], '`a`=\'\',`b`=NULL,`c`=\'1\',`d`=\'1\',`e`=\'-1.1\''],
		];
	}

	/**
	 * @dataProvider providerMultiplePlaceholders
	 */
	public function testMultiplePlaceholders($query, array $arguments, $result) {
		$this->assertSame($result, self::$compiler->compile($query, $arguments));
	}

	public function providerMultiplePlaceholders() {
		return [
			['?', [null, 1, 2, 3, 4, 5], 'NULL'],
			['? ?N ?F ?L ?@ ?@F ?%', ['a', 'a', 'a', 'a', ['a'], ['a'], ['a' => 'a']], '\'a\' a `a` a \'a\' `a` `a`=\'a\''],
		];
	}

	/**
	 * @dataProvider providerSinglePlaceholderError
	 * @expectedException Db\Exceptions\QueryCompilerException
	 */
	public function testSinglePlaceholderError($query, $argument) {
		self::$compiler->compile($query, [$argument]);
	}

	public function providerSinglePlaceholderError() {
		return [
			['?', []],
			['?', new stdClass],

			['?N', []],
			['?N', new stdClass],
			['?N', null],
			['?N', true],
			['?N', 1],
			['?N', 1.1],

			['?F', []],
			['?F', new stdClass],
			['?F', null],
			['?F', true],
			['?F', 1.1],
			['?F', ''],
			['?F', ' '],
			['?F', '*'],

			['?L', []],
			['?L', new stdClass],
			['?L', null],
			['?L', true],
			['?L', 1],
			['?L', 1.1],

			['?@', new stdClass],
			['?@', null],
			['?@', true],
			['?@', 1],
			['?@', 1.1],
			['?@', ''],
			['?@', [[]]],
			['?@', [new stdClass]],

			['?@F', [[]]],
			['?@F', [new stdClass]],
			['?@F', [null]],
			['?@F', [true]],
			['?@F', [1.1]],
			['?@F', ['']],
			['?@F', [' ']],
			['?@F', ['*']],

			['?%', new stdClass],
			['?%', null],
			['?%', true],
			['?%', 1],
			['?%', 1.1],
			['?%', ''],
			['?%', [[]]],
			['?%', [new stdClass]],
			['?%', ['' => 1]],
			['?%', [' ' => 1]],
			['?%', ['*' => 1]],
			['?%', ['a' => new stdClass]],
			['?%', ['a' => []]],
		];
	}

	/**
	 * @dataProvider providerMultiplePlaceholdersError
	 * @expectedException Db\Exceptions\QueryCompilerException
	 */
	public function testMultiplePlaceholdersError($query, array $arguments) {
		self::$compiler->compile($query, $arguments);
	}

	public function providerMultiplePlaceholdersError() {
		return [
			['?', []],
			['?N', []],
			['?F', []],
			['?L', []],
			['?@', []],
			['?@F', []],
			['?%', []],

			['? ?N ?F ?L ?@ ?@F ?%', []],
		];
	}

}

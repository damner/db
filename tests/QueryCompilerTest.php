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
		$this->assertSame($result, self::$compiler->compile($query, array($argument)));
	}

	public function providerSinglePlaceholder() {
		return array(
			array('?', 0, '\'0\''),
			array('?', 1, '\'1\''),
			array('?', -1, '\'-1\''),
			array('?', PHP_INT_MAX , '\''.PHP_INT_MAX .'\''),
			array('?', 0.0, '\'0\''),
			array('?', 1.1, '\'1.1\''),
			array('?', -1.1, '\'-1.1\''),
			array('?', true, '\'1\''),
			array('?', false, '\'0\''),
			array('?', null, 'NULL'),
			array('?', 'NULL', 'NULL'),
			array('?', '', '\'\''),
			array('?', 'a?\'"', '\'a?\\\'\\"\''),
			array('?', 'йё', '\'йё\''),

			array('?N', 'NULL', 'NULL'),
			array('?N', '', ''),
			array('?N', 'a', 'a'),

			array('?F', 'a` ', '`a```'),
			array('?F', 'йё', '`йё`'),
			array('?F', 1, '`1`'),

			array('?L', '', ''),
			array('?L', 'a%_\'"', 'a\\%\\_\\\'\\"'),

			array('?@', array(), 'NULL'),
			array('?@', array(1), '\'1\''),
			array('?@', array(1, 2), '\'1\',\'2\''),
			array('?@', array('1', '2'), '\'1\',\'2\''),
			array('?@', array(null, 'NULL', true, false, 0, -1.1, '', '?'), 'NULL,NULL,\'1\',\'0\',\'0\',\'-1.1\',\'\',\'?\''),

			array('?@F', array(), ''),
			array('?@F', array(1), '`1`'),
			array('?@F', array('a` '), '`a```'),
			array('?@F', array('a', 'b'), '`a`,`b`'),

			array('?%', array(), ''),
			array('?%', array('a' => '', 'b' => null, 'c' => true, 'd' => 1, 'e' => -1.1), '`a`=\'\',`b`=NULL,`c`=\'1\',`d`=\'1\',`e`=\'-1.1\''),
		);
	}

	/**
	 * @dataProvider providerMultiplePlaceholders
	 */
	public function testMultiplePlaceholders($query, array $arguments, $result) {
		$this->assertSame($result, self::$compiler->compile($query, $arguments));
	}

	public function providerMultiplePlaceholders() {
		return array(
			array('?', array(null, 1, 2, 3, 4, 5), 'NULL'),
			array('? ?N ?F ?L ?@ ?@F ?%', array('a', 'a', 'a', 'a', array('a'), array('a'), array('a' => 'a')), '\'a\' a `a` a \'a\' `a` `a`=\'a\''),
		);
	}

	/**
	 * @dataProvider providerSinglePlaceholderError
	 * @expectedException Db\Exceptions\QueryCompilerException
	 */
	public function testSinglePlaceholderError($query, $argument) {
		self::$compiler->compile($query, array($argument));
	}

	public function providerSinglePlaceholderError() {
		return array(
			array('?', array()),
			array('?', new stdClass),

			array('?N', array()),
			array('?N', new stdClass),
			array('?N', null),
			array('?N', true),
			array('?N', 1),
			array('?N', 1.1),

			array('?F', array()),
			array('?F', new stdClass),
			array('?F', null),
			array('?F', true),
			array('?F', 1.1),
			array('?F', ''),
			array('?F', ' '),
			array('?F', '*'),

			array('?L', array()),
			array('?L', new stdClass),
			array('?L', null),
			array('?L', true),
			array('?L', 1),
			array('?L', 1.1),

			array('?@', new stdClass),
			array('?@', null),
			array('?@', true),
			array('?@', 1),
			array('?@', 1.1),
			array('?@', ''),
			array('?@', array(array())),
			array('?@', array(new stdClass)),

			array('?@F', array(array())),
			array('?@F', array(new stdClass)),
			array('?@F', array(null)),
			array('?@F', array(true)),
			array('?@F', array(1.1)),
			array('?@F', array('')),
			array('?@F', array(' ')),
			array('?@F', array('*')),

			array('?%', new stdClass),
			array('?%', null),
			array('?%', true),
			array('?%', 1),
			array('?%', 1.1),
			array('?%', ''),
			array('?%', array(array())),
			array('?%', array(new stdClass)),
			array('?%', array('' => 1)),
			array('?%', array(' ' => 1)),
			array('?%', array('*' => 1)),
			array('?%', array('a' => new stdClass)),
			array('?%', array('a' => array())),
		);
	}

	/**
	 * @dataProvider providerMultiplePlaceholdersError
	 * @expectedException Db\Exceptions\QueryCompilerException
	 */
	public function testMultiplePlaceholdersError($query, array $arguments) {
		self::$compiler->compile($query, $arguments);
	}

	public function providerMultiplePlaceholdersError() {
		return array(
			array('?', array()),
			array('?N', array()),
			array('?F', array()),
			array('?L', array()),
			array('?@', array()),
			array('?@F', array()),
			array('?%', array()),

			array('? ?N ?F ?L ?@ ?@F ?%', array()),
		);
	}

}

<?php

namespace Db;

use Serializable;
use InvalidArgumentException;
use Db\Exceptions\QueryCompilerException;

class QueryCompiler implements QueryCompilerInterface, Serializable {
	protected $escaper;

	/* Serializable interface */

	public function serialize() {
		return '';
    }

    public function unserialize($data) {
    }

	/* End of Serializable interface */

	protected function parse($query) {
		$placeholders = array();
		$pos          = 0;
		while (false !== ($pos = mb_strpos($query, '?', $pos))) {
			$start = $pos;
			$type  = '';
			$pos++;

			$c = mb_substr($query, $pos, 1);
			if (in_array($c, array('%', '@', 'F', 'N', 'L'), true)) {
				$type = $c;
				$pos++;

				$c = mb_substr($query, $pos, 1);
				if ($type === '@' && $c === 'F') {
					$type .= $c;
					$pos++;
				}
			}

			$placeholders[] = array($type, $start);
		}

		return $placeholders;
	}

	protected function escape($str) {
		return call_user_func($this->escaper, $str);
	}

	/* DbQueryCompilerInterface interface */

	public function setEscaperFunction($escaper) {
		if (!is_callable($escaper)) {
			throw new InvalidArgumentException('Bad escaper callback.');
		}

		$this->escaper = $escaper;
	}

	public function compile($query, array $arguments) {
		$pos = 0;
		$out = '';

		foreach ($this->parse($query) as $key => $placeholder) {
			list($type, $start) = $placeholder;

			$out .= mb_substr($query, $pos, $start - $pos);
			$pos = $start + mb_strlen($type) + 1;

			if (!array_key_exists($key, $arguments)) {
				throw new QueryCompilerException(sprintf('Плейсхолдер №%d не указан.', $key + 1));
			}

			$a = $arguments[$key];

			// Скалярный
			if ($type === '') {
				$out .= $this->compileScalarValue($a);

				continue;
			}

			// Просто подставляем
			if ($type === 'N') {
				if (!is_string($a)) {
					throw new QueryCompilerException(sprintf('Передано не строковое значение в плейсхолдер №%d.', $key + 1));
				}

				$out .= $a;

				continue;
			}

			// Поле
			if ($type === 'F') {
				$out .= $this->compileKey($a);

				continue;
			}

			// использовать так "field LIKE '%?L%'"
			if ($type === 'L') {
				if (!is_string($a)) {
					throw new QueryCompilerException(sprintf('Передано не строковое значение в плейсхолдер №%d.', $key + 1));
				}

				$out .= addcslashes($this->escape($a), '%_');

				continue;
			}

			// Список значений
			if ($type === '@') {
				if (!is_array($a)) {
					throw new QueryCompilerException(sprintf('Передан не массив в плейсхолдер №%d.', $key + 1));
				}

				if (count($a)) {
					foreach ($a as $k => $v) {
						$a[$k] = $this->compileScalarValue($v);
					}

					$out .= implode(',', $a);
				} else {
					$out .= 'NULL';
				}

				continue;
			}

			// Список полей
			if ($type === '@F') {
				if (!is_array($a)) {
					throw new QueryCompilerException(sprintf('Передан не массив в плейсхолдер №%d.', $key + 1));
				}

				foreach ($a as $k => $v) {
					$a[$k] = $this->compileKey($v);
				}

				$out .= implode(',', $a);

				continue;
			}

			// Ассоциативный массив значений
			if ($type === '%') {
				if (!is_array($a)) {
					throw new QueryCompilerException(sprintf('Передан не массив в плейсхолдер №%d.', $key + 1));
				}

				foreach ($a as $k => $v) {
					$a[$k] = $this->compileKey($k).'='.$this->compileScalarValue($v);
				}

				$out .= implode(',', $a);

				continue;
			}
		}

		$out .= mb_substr($query, $pos);

		return $out;
	}

	/* End of DbQueryCompilerInterface interface */

	protected function compileScalarValue($value) {
		if ($value === null || $value === 'NULL') {
			return 'NULL';
		}

		if (is_bool($value)) {
			$value = (string)(int)$value;
		}

		if (is_int($value) || is_float($value)) {
			$value = (string)$value;
		}

		if (!is_string($value)) {
			throw new QueryCompilerException(sprintf('Invalid variable type "%s". Allowed types: null, bool, int, float, string.', gettype($value)));
		}

		return "'".$this->escape($value)."'";
	}

	protected function compileKey($value) {
		if (!is_string($value) && !is_int($value)) {
			throw new QueryCompilerException(sprintf('Invalid variable type "%s". Allowed only integers and strings.', gettype($value)));
		}

		$value = trim($value);

		if ($value === '') {
			throw new QueryCompilerException('Value must be non empty.');
		}

		if ($value === '*') {
			throw new QueryCompilerException('Value must not be a star (*) character.');
		}

		return '`'.str_replace('`', '``', $value).'`';
	}

}

<?php

namespace Db;

interface CompilableQueryInterface {
	public function getQueryCompiler();
	public function getCompiledQuery($arguments);
}

<?php

namespace Db;

interface QueryCompilerInterface {
	public function setEscaperFunction($escaper);
	public function compile($query, array $arguments);
}

<?php

class StrictNewsModel extends \DB\Cortex {

	protected
		$fieldConf = [
			'title' => [
				'type' => \DB\SQL\Schema::DT_VARCHAR128
			],
			'author' => [
				'belongs-to-one' => '\AuthorModel',
				'nullable' => false,
			],
	],
		$table = 'strict_news',
		$db = 'DB';

}

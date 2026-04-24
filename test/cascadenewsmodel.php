<?php

class CascadeNewsModel extends \DB\Cortex {

	protected
		$fieldConf = [
			'title' => [
				'type' => \DB\SQL\Schema::DT_VARCHAR128
			],
			'author' => [
				'belongs-to-one' => '\AuthorModel',
				'nullable' => true,
				'onDelete' => 'CASCADE',
			],
	],
		$table = 'cascade_news',
		$db = 'DB';

}

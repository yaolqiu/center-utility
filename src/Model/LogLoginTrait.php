<?php


namespace Lyqiu\CenterUtility\Model;


use EasySwoole\Mysqli\QueryBuilder;

trait LogLoginTrait
{
	protected function setBaseTraitProptected()
	{
		$this->autoTimeStamp = true;
	}

	/**
	 * 关联
	 * @return array|mixed|null
	 * @throws \Throwable
	 */
	public function relation()
	{
		$callback = function (QueryBuilder $query) {
			$query->fields(['id', 'username', 'realname', 'avatar', 'status']);
			return $query;
		};
		return $this->hasOne(find_model('Account\Admin'), $callback, 'uid', 'id');
	}
}

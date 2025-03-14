<?php

namespace Lyqiu\CenterUtility\HttpController;

trait LogLoginTrait
{
	protected function __search()
	{
		$filter = $this->filter();
		$where = ['instime' => [[date('Y-m-d H:i:s', $filter['begintime']), date('Y-m-d H:i:s', $filter['endtime'])], 'between']];
        empty($this->get['uid']) or $where['concat(uid," ",name)'] = ["%{$this->get['uid']}%", 'like'];

        return $this->_search($where);
	}

	protected function __after_index($items, $total)
	{
		foreach ($items as &$value) {
			$value->relation = $value->relation ?? [];
		}

		return parent::__after_index($items, $total);
	}
}

<?php

namespace Lyqiu\CenterUtility\Model;

use EasySwoole\ORM\AbstractModel;
use EasySwoole\ORM\DbManager;
use EasySwoole\RedisPool\RedisPool;

/**
 * @extends AbstractModel
 */
trait BaseModelTrait
{
    protected $sort = ['id' => 'desc'];

	public function __construct($data = [], $tabname = '')
	{
		// $tabname > $this->tableName > $this->_getTable()
		$tabname && $this->tableName = $tabname;
		if ( ! $this->tableName) {
			$this->tableName = $this->_getTable();
		}

        //  $this->autoTimeStamp = false;
		$this->createTime = 'instime';
		$this->updateTime = false;
		$this->setBaseTraitProptected();

		parent::__construct($data);
	}

	protected function setBaseTraitProptected()
	{
	}

    public function getDeleteTime()
    {
        return $this->deleteTime ?? NULL;
    }

	/**
	 * 获取表名，并将将Java风格转换为C的风格
	 * @return string
	 */
	protected function _getTable()
	{
		$name = basename(str_replace('\\', '/', get_called_class()));
		return parse_name($name);
	}

	public function getPk()
	{
		return $this->schemaInfo()->getPkFiledName();
	}

	protected function getExtensionAttr($extension = '', $alldata = [])
	{
		return is_array($extension) ? $extension : json_decode($extension, true);
	}

	/**
	 * 数据写入前对extension字段的值进行处理
	 * @access protected
	 * @param array $extension 原数据
	 * @param bool $encode 是否强制编码
	 * @return string 处理后的值
	 */
	protected function setExtensionAttr($extension = [], $alldata = [])
	{
        // QueryBuilder::func 等结构
        if (is_array($extension) && in_array(array_key_first($extension), ['[I]', '[F]', '[N]'])) {
            return $extension;
        }
		if (is_string($extension)) {
			$extension = json_decode($extension, true);
			if ( ! $extension) {
				return json_encode(new \stdClass());
			}
		}
		return json_encode($extension);
	}

	protected function setInstimeAttr($instime, $all)
	{
		return is_numeric($instime) ? date('Y-m-d H:i:s', $instime) : $instime;
	}

	public function scopeIndex()
	{
		return $this;
	}

	public function setOrder(array $order = [])
	{
		$sort = $this->sort;
		// 'id desc'
		if (is_string($sort)) {
			list($sortField, $sortValue) = explode(' ', $sort);
			$order[$sortField] = $sortValue;
		} // ['sort' => 'desc'] || ['sort' => 'desc', 'id' => 'asc']
		else if (is_array($sort)) {
			// 保证传值的最高优先级
			foreach ($sort as $k => $v) {
				if ( ! isset($order[$k])) {
					$order[$k] = $v;
				}
			}
		}

		foreach ($order as $key => $value) {
			$this->order($key, $value);
		}
		return $this;
	}

	/**
	 * 不修改配置的情况下，all结果集转Collection，文档： http://www.easyswoole.com/Components/Orm/toArray.html
	 * @param bool $toArray
	 * @return array|bool|\EasySwoole\ORM\Collection\Collection|\EasySwoole\ORM\Db\Cursor|\EasySwoole\ORM\Db\CursorInterface
	 * @throws \EasySwoole\ORM\Exception\Exception
	 * @throws \Throwable
	 */
	public function ormToCollection($toArray = true)
	{
		$result = $this->all();
		if ( ! $result instanceof \EasySwoole\ORM\Collection\Collection) {
			$result = new \EasySwoole\ORM\Collection\Collection($result);
		}
		return $toArray ? $result->toArray() : $result;
	}

	/**
	 * 删除rediskey
	 * @param mixed ...$key
	 */
	public function delRedisKey(...$key)
	{
		$redis = RedisPool::defer();
		$redis->del($key);
	}

	// 开启事务
	public function startTrans()
	{
		DbManager::getInstance()->startTransaction($this->getQueryConnection());
	}

	public function commit()
	{
		DbManager::getInstance()->commit($this->getQueryConnection());

	}

	public function rollback()
	{
		DbManager::getInstance()->rollback($this->getQueryConnection());
	}


    /**
     * 重写 get 方法
     * @param $where
     * @return array|bool|AbstractModel|\EasySwoole\ORM\Db\Cursor|\EasySwoole\ORM\Db\CursorInterface|BaseModelTrait|null
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws \EasySwoole\ORM\Exception\Exception
     * @throws \Throwable
     */
    public function get($where  = null)
    {
        $deleteTime = $this->getDeleteTime();
        if($deleteTime && !isset($where[$deleteTime])) {
            $where[$deleteTime] = [NULL, 'IS'];
        }
        return parent::get($where);
    }

    /**
     * 原查询
     * @param $where
     * @return array|bool|AbstractModel|\EasySwoole\ORM\Db\Cursor|\EasySwoole\ORM\Db\CursorInterface|BaseModelTrait|null
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws \EasySwoole\ORM\Exception\Exception
     * @throws \Throwable
     */
    public function rawGet($where  = null)
    {
        return parent::get($where);
    }

    public function softAll($where = null)
    {
        $deleteTime = $this->getDeleteTime();
        if($deleteTime && !isset($where[$deleteTime])) {
            $where[$deleteTime] = [NULL, 'IS'];
        }
        return parent::all($where);
    }

    /**
     * 强制适配软件删除（不支持软件删除，就使用回硬删除）
     * @param $where
     * @param $allow
     * @return mixed
     */
    public function softDestory($where = null, $allow = false){
        $updateData = [];

        $deleteTime = $this->getDeleteTime();
        if($deleteTime && !isset($where[$deleteTime])) {
            $updateData[$deleteTime] = date('Y-m-d H:i:s', time());
        }

        $originData = $this->getOriginData();
        $pk = $this->getPk();

        if($originData) {
            $where[$pk] = $originData[$pk];
        }

        return $updateData ? $this->update($updateData, $where) : parent::destroy($where) ;
    }

}

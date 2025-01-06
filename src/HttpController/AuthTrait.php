<?php

namespace Lyqiu\CenterUtility\HttpController;

use App\HttpController\BaseController;
use EasySwoole\Component\Timer;
use EasySwoole\Http\Exception\FileException;
use EasySwoole\Mysqli\QueryBuilder;
use EasySwoole\ORM\AbstractModel;
use EasySwoole\Policy\Policy;
use EasySwoole\Policy\PolicyNode;
use EasySwoole\Utility\MimeType;
use Lyqiu\CenterUtility\Common\Classes\CtxRequest;
use Lyqiu\CenterUtility\Common\Classes\DateUtils;
use Lyqiu\CenterUtility\Common\Classes\LamJwt;
use Lyqiu\CenterUtility\Common\Classes\XlsWriter;
use Lyqiu\CenterUtility\Common\Exception\HttpParamException;
use Lyqiu\CenterUtility\Common\Http\Code;
use Lyqiu\CenterUtility\Common\Languages\Dictionary;

/**
 * @extends BaseController
 */
trait AuthTrait
{
    protected $operinfo = [];

    protected $uploadKey = 'file';

    /**********************************************************************
    * 权限认证相关属性                                                      *
    *     1. 子类无需担心重写覆盖，校验时会反射获取父类属性值，并做合并操作       *
    *     2. 对于特殊场景也可直接重写 setPolicy 方法操作Policy                *
    *     3. 大小写不敏感                                                   *
    ***********************************************************************/

    // 别名认证
    protected array $_authAlias = ['change' => 'edit', 'export' => 'index'];

    // 无需认证
    protected array $_authOmit = ['upload', 'options'];

    protected $isExport = false;

    /**
     * 当前登录的系统
     * @var string
     */
    protected $sub = '';
    protected $mid = 0;
    protected $uid = 0;

    protected function onRequest(?string $action): ?bool
    {
        $this->setAuthTraitProptected();

        $return = parent::onRequest($action);
        if ( ! $return) {
            return false;
        }

        $this->isExport = $action === 'export';

        return $this->checkAuthorization();
    }

    // 返回主体数据
    protected function _getEntityData($id = 0)
    {
        /** @var AbstractModel $Admin */
        $Admin = model_admin('account');
        // 当前用户信息
        return $Admin->where('id', $id)->get();
    }

    protected function _verifyToken($authorization = '')
    {
        return LamJwt::verifyToken($authorization, config('auth.jwtkey'));
    }

    protected function setAuthTraitProptected()
    {
    }

    // 其他系统，http发送给账号管理系统认证, todo 得上RPC
    protected function httpCheckAuth()
    {
        // 发送http前，判断无需认证操作和转换别名认证操作
        $query = [];

        $publicMethods = $this->getAllowMethods('strtolower');

        $currentAction = strtolower($this->getActionName());
        if ( ! in_array($currentAction, $publicMethods)) {
            $this->error(Code::CODE_FORBIDDEN);
            return false;
        }

        $currentClassName = strtolower($this->getStaticClassName());
        $appName = strtolower($this->getStaticAppNameSpace());
        $fullPath = strtolower("/$currentClassName/$currentAction");

        $selfRef = new \ReflectionClass(self::class);
        $selfDefaultProtected = $selfRef->getDefaultProperties();
        $selfOmitAction = $selfDefaultProtected['_authOmit'] ?? [];
        $selfAliasAction = $selfDefaultProtected['_authAlias'] ?? [];

        // 无需认证操作
        if ($omitAction = array_map('strtolower', array_merge($selfOmitAction, $this->_authOmit))) {
            if (in_array($currentAction, $omitAction)) {
                // 标识为无需认证，仅返回用户数据
                $query['omit'] = 1;
            }
        }

        // 别名认证操作
        $aliasAction = array_change_key_case(array_map('strtolower', array_merge($selfAliasAction, $this->_authAlias)));
        if ($aliasAction && isset($aliasAction[$currentAction])) {
            $alias = trim($aliasAction[$currentAction], '/');
            if (in_array($alias, $publicMethods)) {
                $fullPath = "/$currentClassName/$alias";
            }
        }

        $query['permCode'] =  '/' . $appName . $fullPath;

        $url = config('DOMAIN_URL.auth');
        if (empty($url)) {
            $this->error(Code::CODE_FORBIDDEN, '缺少配置： DOMAIN_URL.auth');
            return false;
        }

        $tokenKey = config('TOKEN_KEY');

        $HttpClient = new \EasySwoole\HttpClient\HttpClient($url);
        // 全站通用jwt密钥
        $HttpClient->setHeader($tokenKey, $this->getAuthorization(), false);
        $resp = $HttpClient->setQuery($query)->get();
        $result = $resp->json(true);

        // 失败 || 没权限
        if ($resp->getStatusCode() !== 200 || ! isset($result['code']) || $result['code'] !== 200) {
            $this->error($result['code'] ?? Code::CODE_FORBIDDEN, $result['msg']);
            return false;
        }
        
        $this->sub = $result['result']['sub'];
        $this->mid = $result['result']['operinfo']['mid'];
        $this->uid = $result['result']['operinfo']['id'];
        $this->operinfo = $result['result']['operinfo'];
        CtxRequest::getInstance()->withOperinfo($this->operinfo);

        return true;
    }

    // 账号管理系统，本站权限认证
    protected function checkAuthorization()
    {
        // 获取授权的 token
        $authorization = $this->getAuthorization();

        if ( ! $authorization) {
            $this->error(Code::CODE_UNAUTHORIZED, Dictionary::ADMIN_AUTHTRAIT_1);
            return false;
        }

        // jwt验证
        $jwt = $this->_verifyToken($authorization);

        $id = $jwt['data']['id'] ?? '';
        if ($jwt['status'] != 1 || empty($id)) {
            $this->error(Code::CODE_UNAUTHORIZED, Dictionary::ADMIN_AUTHTRAIT_2);
            return false;
        }

        $this->sub = $jwt['data']['sub'];
        if ( ! in_array($this->sub, config('SUB_SYSTEM') ?: [])) {
            $this->error(Code::ERROR_OTHER, Dictionary::CANT_FIND_USER);
            return false;
        }

        // 当前用户信息
        $data = $this->_getEntityData($id);

        if (empty($data)) {
            $this->error(Code::CODE_UNAUTHORIZED, Dictionary::ADMIN_AUTHTRAIT_3);
            return false;
        }

        // 判断是帐号是否禁用
        if (empty($data['status'])) {
            $this->error(Code::ERROR_OTHER, Dictionary::ADMIN_AUTHTRAIT_4);
            return false;
        }

        // 关联的分组信息
        $this->operinfo = $this->_operinfo($data);
        $this->mid = $this->operinfo['mid'];
        $this->uid =  $this->operinfo['id'];

        // 将管理员信息挂载到Request
        CtxRequest::getInstance()->withOperinfo($this->operinfo);

        return $this->checkAuth();
    }

    protected function _operinfo($data)
    {
        $relation = $data->relation ? $data->relation->toArray() : [];
        // 获取主体信息
        $mainInfo = $data->mainInfo ? $data->mainInfo->toArray() : [];

        $data = $data->toArray();
        $data['role'] = $relation;
        $data['mainInfo'] = $mainInfo;
        return $data;
    }

    /**
     * 权限
     * @return bool
     * @throws \EasySwoole\ORM\Exception\Exception
     * @throws \Throwable
     */
    protected function checkAuth()
    {
        // 如果是超级管理员，则不用测试
        // is_super 使用的是不是 composer 中的配置

        if ($this->isSuper()) {
            return true;
        }

        // 获取 类下的 plublic 方法
        $publicMethods = $this->getAllowMethods('strtolower');

        $currentAction = strtolower($this->getActionName());

        if ( ! in_array($currentAction, $publicMethods)) {
            $this->error(Code::CODE_FORBIDDEN);
            return false;
        }

        $currentClassName = strtolower($this->getStaticClassName());
        $fullPath = "/$currentClassName/$currentAction";

        /** @var AbstractModel $Menu */
        $Menu = model_admin('Menu');

        // 设置用户权限
        $userMenu = $this->getUserMenus();

        if ( ! is_null($userMenu)) {
            if (empty($userMenu)) {
                $this->error(Code::CODE_FORBIDDEN);
                return false;
            }
            $Menu->where('id', $userMenu, 'IN');
        }

        $priv = $Menu->where(['permission' => ['', '<>'], 'status' => 1])
            ->where("FIND_IN_SET('{$this->sub}', sub) > 0")
            ->column('permission');

        if (empty($priv)) {
            return true;
        }

        $policy = new Policy();
        foreach ($priv as $path) {
            $policy->addPath('/' . trim(strtolower($path), '/'));
        }

        $selfRef = new \ReflectionClass(self::class);
        $selfDefaultProtected = $selfRef->getDefaultProperties();
        $selfOmitAction = $selfDefaultProtected['_authOmit'] ?? [];
        $selfAliasAction = $selfDefaultProtected['_authAlias'] ?? [];

        // 无需认证操作
        if ($omitAction = array_map('strtolower', array_merge($selfOmitAction, $this->_authOmit))) {
            foreach ($omitAction as $omit) {
                in_array($omit, $publicMethods) && $policy->addPath("/$currentClassName/$omit");
            }
        }

        // 别名认证操作
        $aliasAction = array_change_key_case(array_map('strtolower', array_merge($selfAliasAction, $this->_authAlias)));
        if ($aliasAction && isset($aliasAction[$currentAction])) {
            $alias = trim($aliasAction[$currentAction], '/');
            if (strpos($alias, '/') === false) {
                if (in_array($alias, $publicMethods)) {
                    $fullPath = "/$currentClassName/$alias";
                }
            } else {
                // 支持引用跨菜单的已有权限
                $fullPath = '/' . $alias;
            }
        }

        // 自定义认证操作
        $this->setPolicy($policy);
        $ok = $policy->check($fullPath) === PolicyNode::EFFECT_ALLOW;
        if ( ! $ok) {
            $this->error(Code::CODE_FORBIDDEN);
        }
        return $ok;
    }

    // 对于复杂场景允许自定义认证，优先级最高
    protected function setPolicy(Policy $policy)
    {

    }

    protected function isSuper($rid = null)
    {
        return is_super(is_null($rid) ? $this->operinfo['rid'] : $rid);
    }

    protected function getUserMenus()
    {
        if (empty($this->operinfo['role']['chk_menu'])) {
            return null;
        }
        return $this->operinfo['role']['menu'] ?? [];
    }

    protected function ifRunBeforeAction()
    {
        foreach (['__before__common', '__before_' . $this->getActionName()] as $beforeAction) {
            if (method_exists(static::class, $beforeAction)) {
                $this->$beforeAction();
            }
        }
    }

    protected function __getModel(): AbstractModel
    {
        $request = array_merge($this->get, $this->post);

        if ( ! $this->Model instanceof AbstractModel) {
            throw new HttpParamException('Model Not instanceof AbstractModel !');
        }

        $pk = $this->Model->getPk();
        // 不排除id为0的情况
        if ( ! isset($request[$pk]) || $request[$pk] === '') {
            throw new HttpParamException(lang(Dictionary::ADMIN_AUTHTRAIT_10));
        }

        // 强制适配 > 1000 加 mid条件
        if($this->mid > 1000) {
            $this->Model->where('mid', $this->mid);
        }
        $model = $this->Model->where($pk, $request[$pk])->get();

        if (empty($model)) {
            throw new HttpParamException(lang(Dictionary::ADMIN_AUTHTRAIT_11));
        }

        return $model;
    }

    public function _add($return = false)
    {
        if ($this->isHttpPost()) {
            $result = $this->Model->data($this->post)->save();
            if ($return) {
                return $result;
            } else {
                $result ? $this->success() : $this->error(Code::ERROR_OTHER, Dictionary::ADMIN_AUTHTRAIT_6);
            }
        }
    }

    public function _edit($return = false)
    {
        $pk = $this->Model->getPk();

        $model = $this->__getModel();
        $request = array_merge($this->get, $this->post);

        if ($this->isHttpPost()) {

            $where = null;
            // 单独处理id为0值的情况，因为update传where后，data不会取差集，会每次update所有字段, 而不传$where时会走进preSetWhereFromExistModel用empty判断主键，0值会报错
            if (intval($request[$pk]) === 0) {
                $where = [$pk => $request[$pk]];
            }

            /*
             * update返回的是执行语句是否成功,只有mysql语句出错时才会返回false,否则都为true
             * 所以需要getAffectedRows来判断是否更新成功
             * 只要SQL没错误就认为成功
             */
            $upd = $model->update($request, $where);
            if ($upd === false) {
                trace('edit update失败: ' . $model->lastQueryResult()->getLastError());
                throw new HttpParamException(lang(Dictionary::ADMIN_AUTHTRAIT_9));
            }
        }

        return $return ? $model->toArray() : $this->success($model->toArray());
    }

    protected function __fields()
    {
        return "*";
    }

    public function _read($return = false)
    {
        $pk = $this->Model->getPk();
        $this->__with();
        $model = $this->__getModel();
        $result = $this->__after_read($model);
        return $return ? $result : $this->success($result);
    }

    protected function __after_read($data)
    {
        return $data->toArray();
    }

    public function _del($return = false)
    {
        try {
            $model = $this->__getModel();

            $deleteTime = $this->Model->getDeleteTime();

            if($deleteTime) {
                $result = $model->update([
                    $deleteTime=> date('Y-m-d H:i:s')
                ]) === false ? false : true;
            } else {
                $result = $model->destroy();
            }
            if ($return) {
                return $model->toArray();
            } else {
                $result ? $this->success() : $this->error(Code::ERROR_OTHER, Dictionary::ADMIN_AUTHTRAIT_14);
            }
        } catch (\Exception $e) {
            $this->success();
        }
    }

    public function _change($return = false)
    {
        $post = $this->post;
        $pk = $this->Model->getPk();
        if ( ! isset($post[$pk]) || ( ! isset($post[$post['column']]) && ! isset($post['value']))) {
            return $this->error(Code::ERROR_OTHER, Dictionary::ADMIN_AUTHTRAIT_15);
        }

        $column = $post['column'];

        $model = $this->__getModel();

        $where = null;
        // 单独处理id为0值的情况，因为update传where后，data不会取差集，会每次update所有字段, 而不传$where时会走进preSetWhereFromExistModel用empty判断主键，0值会报错
        if (intval($post[$pk]) === 0) {
            $where = [$pk => $post[$pk]];
        }

        $value = $post[$column] ?? $post['value'];
        if (strpos($column, '.') === false) {
            // 普通字段
            $upd = $model->update([$column => $value], $where);
        } else {
            // json字段
            list($one, $two) = explode('.', $column);
            $upd = $model->update([$one => QueryBuilder::func(sprintf("json_set($one, '$.%s','%s')", $two, $value))], $where);
        }

//        $rowCount = $model->lastQueryResult()->getAffectedRows();
        if ($upd === false) {
            throw new HttpParamException(lang(Dictionary::ADMIN_AUTHTRAIT_18));
        }
        return $return ? $model->toArray() : $this->success();
    }

    // index在父类已经预定义了，不能使用actionNotFound模式
    public function index()
    {
        return $this->_index();
    }

    public function _index($return = false)
    {
        if ( ! $this->Model instanceof AbstractModel) {
            throw new HttpParamException(lang(Dictionary::PARAMS_ERROR));
        }

        $page = $this->get[config('fetchSetting.pageField')] ?? 1;          // 当前页码
        $limit = $this->get[config('fetchSetting.sizeField')] ?? 20;    // 每页多少条数据

        $this->__with();
        $where = $this->__search();

        // 强制处理，软删除，只支持 ＤＡＴＡＴＩＭＥ
        $deletTime = $this->Model->getDeleteTime();

        if( !empty($deletTime) && !isset($where[$deletTime])) {
            $where[$deletTime] = [NULL, 'IS'];
        }

        // 处理排序
        $this->__order();
        $fields = $this->__fields();
        $this->Model->scopeIndex();
        $this->Model->limit($limit * ($page - 1), $limit)->withTotalCount();
        $items = $this->Model->field($fields)->all($where);
        $result = $this->Model->lastQueryResult();
        $total = $result->getTotalCount();

        $data = $this->__after_index($items, $total);
        return $return ? $data : $this->success($data);
    }

    protected function __after_index($items, $total)
    {
        return [config('fetchSetting.listField') => $items, config('fetchSetting.totalField') => $total];
    }

    protected function __with($column = 'relation')
    {
        $origin = $this->Model->getWith();
        $exist = is_array($origin) && in_array($column, $origin);
        if ( ! $exist && method_exists($this->Model, $column)) {
            $with = is_array($origin) ? array_merge($origin, [$column]) : [$column];
            $this->Model->with($with);
        }
        return $this;
    }

    protected function __order()
    {
        $sortField = $this->get['_sortField'] ?? ''; // 排序字段
        $sortValue = $this->get['_sortValue'] ?? ''; // 'ascend' | 'descend'

        $order = [];
        if ($sortField && $sortValue) {
            // 去掉前端的end后缀
//            $sortValue = substr($sortValue, 0, -3);
            $sortValue = str_replace('end', '', $sortValue);
            $order[$sortField] = $sortValue;
        }

        $this->Model->setOrder($order);
        return $order;
    }

    /**
     * 因为有超级深级的JSON存在，如果需要导出全部，那么数据必须在后端处理，字段与前端一一对应
     * 不允许客户端如extension.user.sid这样取值 或者 customRender 或者 插槽渲染, 否则导出全部时无法处理
     * @throws \EasySwoole\Mysqli\Exception\Exception
     * @throws \EasySwoole\ORM\Exception\Exception
     * @throws \Throwable
     */
    public function export()
    {
        // 处理表头，客户端应统一处理表头
        $th = [];
        if ($thStr = $this->get[config('fetchSetting.exportThField')]) {
            // _th=ymd=日期|reg=注册|login=登录

            $thArray = explode('|', urldecode($thStr));
            foreach ($thArray as $value) {
                list ($thKey, $thValue) = explode('=', $value);
                // 以表头key表准
                if ($thKey) {
                    $th[$thKey] = $thValue ?? '';
                }
            }
        }

        $where = $this->__with()->__search();

        // 处理排序
        $this->__order();

        // todo 希望优化为fetch模式
        $items = $this->Model->all($where);
        $data = $this->__after_index($items, 0)[config('fetchSetting.listField')];

        // 是否需要合并合计行，如需合并，data为索引数组，为空字段需要占位

        // xlsWriter固定内存模式导出
        $excel = new XlsWriter();

        // 客户端response响应头获取不到Content-Disposition，用参数传文件名
        $fileName = $this->get[config('fetchSetting.exprotFilename')] ?? '';
        if (empty($fileName)) {
            $fileName = sprintf('export-%d-%s.xlsx', date(DateUtils::YmdHis), substr(uniqid(), -5));
        }

        $excel->ouputFileByCursor($fileName, $th, $data);
        $fullFilePath = $excel->getConfig('path') . $fileName;

        $this->response()->sendFile($fullFilePath);
//        $this->response()->withHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->response()->withHeader('Content-Type', MimeType::getMimeTypeByExt('xlsx'));
//        $this->response()->withHeader('Content-Type', 'application/octet-stream');
        // 客户端获取不到这个header,待调试
//        $this->response()->withHeader('Content-Disposition', 'attachment; filename=' . $fileName);
        $this->response()->withHeader('Cache-Control', 'max-age=0');
        $this->response()->end();

        // 下载完成就没有用了，延时删除掉
        Timer::getInstance()->after(1000, function () use ($fullFilePath) {
            @unlink($fullFilePath);
        });
    }

    public function upload()
    {
        try {
            /** @var \EasySwoole\Http\Message\UploadFile $file */
            $file = $this->request()->getUploadedFile($this->uploadKey);

            // todo 文件校验
            $fileType = $file->getClientMediaType();

            $clientFileName = $file->getClientFilename();
            $arr = explode('.', $clientFileName);
            $suffix = end($arr);

            $ymd = date(DateUtils::YMD);
            $join = "/{$ymd}/";

            $dir = rtrim(config('UPLOAD.dir'), '/') . $join;
            // 当前控制器名做前缀
            $arr = explode('\\', static::class);
            $prefix = end($arr);
            $fileName = uniqid($prefix . '_', true) . '.' . $suffix;

            $fullPath = $dir . $fileName;
            $file->moveTo($fullPath);
//            chmod($fullPath, 0777);

            $assertUrl = config('DOMAIN_URL.assert');
            $url = $assertUrl . $join . $fileName;
            $this->writeUpload($url);
        } catch (FileException $e) {
            $this->writeUpload('', Code::ERROR_OTHER, $e->getMessage());
        }
    }

    /**
     * 统一读取文件内容
     * @return array
     * @throws HttpParamException
     */
    public function _readExcel()
    {
        try {
            /** @var \EasySwoole\Http\Message\UploadFile $file */
            $file = $this->request()->getUploadedFile($this->uploadKey);
            if(empty($file)) {
                throw new HttpParamException( Dictionary::ADMIN_CARBON_2, Code::ERROR_OTHER);
            }

            $tempName = $file->getTempName();
            $path = dirname($tempName);
            $filename = basename($tempName);
            $config = [
                'path' => $path,
            ];

            $excel = new \Vtiful\Kernel\Excel($config);

            return $excel->openFile($filename)
                ->openSheet()
                ->getSheetData();
        } catch (\Exception $e) {
            throw new HttpParamException($e->getMessage(),Code::ERROR_OTHER);
        }
    }


    public function unlink()
    {
//        $suffix = pathinfo($this->post['url'], PATHINFO_EXTENSION);
//        $info = pathinfo($this->post['url']);
//        $filename = $info['basename'];
//        // todo 文件校验, 比如子类为哪个控制器，只允许删除此前缀的
//        $suffix = $info['extension'];
//
//        // 指定目录
//        $dir = rtrim(config('UPLOAD.dir'), '/') . '/images/';
//
//        $file = $dir . $filename;
//        if (is_file($file))
//        {
//            @unlink($file);
//        }
        $this->success();
    }

    /**
     * 构造查询数据
     * 可在具体的控制器的【基本组件里(即：use xxxTrait 的 xxxTrait里)】重写此方法以实现如有个性化的搜索条件
     * @return array
     */
    protected function __search()
    {
        // 。。。。这里一般是基本组件的构造where数组的代码
        return $this->_search([]);
    }

    /**
     * 构造查询数据
     * 可在具体的控制器【内部】重写此方法以实现如有个性化的搜索条件
     * @return array
     */
    protected function _search($where = [])
    {
        // 。。。。这里一般是控制器的构造where数组的代码
        return $where;
    }

    /**
     * 公共参数,配合where使用
     * 考虑到有时会有大数据量的搜索条件，特意使用$this->input 而不是 $this->get
     * @return array
     */
    protected function filter()
    {
        $filter = $this->input;

        if (isset($filter['begintime'])) {
            if ((strpos($filter['begintime'], ':') === false)) {
                $filter['begintime'] .= ' 00:00:00';
            }

            $filter['begintime'] = strtotime($filter['begintime']);
            $filter['beginday'] = date(DateUtils::YMD, $filter['begintime']);
        }

        if (isset($filter['endtime'])) {
            if (strpos($filter['endtime'], ':') === false) {
                $filter['endtime'] .= ' 23:59:59';
            }

            $filter['endtime'] = strtotime($filter['endtime']);
            $filter['endday'] = date(DateUtils::YMD, $filter['endtime']);
        }

        return $filter;
    }

    // 生成OptionsItem[]结构
    protected function __options($where = null, $label = 'name', $value = 'id', $return = false)
    {
        $options = $this->Model->field([$label, $value])->all($where);
        $result = [];
        foreach ($options as $option) {
            $result[] = [
                'label' => $option->getAttr($label),
                'value' => $option->getAttr($value),
            ];
        }
        return $return ? $result : $this->success($result);
    }

    /**
     * 获取检验器
     * @return void
     */
    protected function __getValidate()
    {
        list($name, $type, $app, $controller) = explode('\\', static::class);
        $className = implode('\\', [$name, 'Validate', $app, ucfirst($controller) . 'Validate']);
        if(class_exists($className)) {
            $v =  new $className();
            // 方法名即可为场景，当场景值存在才会操作
            $scene = trim($this->getActionName(), '_');
            if($v->hasScene($scene)) {
                return $v->scene($scene);
            }
            return false;
        }
        return false;
    }

    /**
     * 统一检测所有传入的数据
     * @param $data
     * @return void
     */
    protected function __checkValidate($data = [])
    {
        $validate = $this->__getValidate();
        if(empty($data)) {
            $data = array_merge($this->get, $this->post);
        }
        if($data && $validate && !$validate->check($data)) {
            throw new HttpParamException($validate->getError(),Code::ERROR_OTHER);
        }
    }
}

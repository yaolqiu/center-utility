<?php


namespace Lyqiu\CenterUtility\HttpController;

use Lyqiu\CenterUtility\Common\Classes\CtxRequest;
use Lyqiu\CenterUtility\Common\Exception\HttpParamException;
use Lyqiu\CenterUtility\Common\Languages\Dictionary;

/**
 * @property \App\Model\Admin\Admin $Model
 */
trait PubTrait
{
    protected function instanceModel()
    {
        $this->Model = model_admin('account');
        return true;
    }


    public function index()
	{
		return $this->_login();
	}

    public function _login($return = false)
	{
		$array = $this->post;
		if ( ! isset($array['username'])) {
			throw new HttpParamException(lang(Dictionary::ADMIN_PUBTRAIT_1));
		}

		// 查询记录
		$data = $this->Model->where('username', $array['username'])->get();
        if (empty($data) || ! password_verify($array['password'], $data['password'])) {
            throw new HttpParamException(lang(Dictionary::ADMIN_PUBTRAIT_4));
		}
        $data = $data->toArray();

        // 被锁定
		if (empty($data['status']) && ( ! is_super($data['rid']))) {
			throw new HttpParamException(lang(Dictionary::ADMIN_PUBTRAIT_2));
		}

        // 是否此系统账号
        $sub = $this->input['sub'];
        if ( ! empty($data['sub']) && (empty($sub) || ! in_array($sub, $data['sub']))) {
            throw new HttpParamException(lang(Dictionary::ADMIN_PUBTRAIT_5));
        }

		$request = CtxRequest::getInstance()->request;
		$this->Model->signInLog([
			'aid' => $data['id'],
			'name' => $data['realname'] ?: $data['username'],
            'updtime'=> date('Y-m-d H:i:s', time()),
			'ip' => ip($request),
		]);

		$token = get_login_token(['id' => $data['id'], 'sub' => $sub]);
        $result = ['token' => $token];
        return $return ? $result + ['data' => $data] : $this->success($result, Dictionary::ADMIN_PUBTRAIT_3);
	}

	public function logout()
	{
		$this->success('success');
	}
}

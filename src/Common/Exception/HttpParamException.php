<?php

namespace Lyqiu\CenterUtility\Common\Exception;

use Lyqiu\CenterUtility\Common\Http\Code;

class HttpParamException extends \Exception
{
    public function __construct($message = "", $code = Code::ERROR_OTHER, Throwable $previous = null)
    {
        parent::__construct($message, $code, $previous);
    }
}

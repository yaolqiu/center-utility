<?php

namespace Lyqiu\CenterUtility\Consumer;

trait HttpTrackerTrait
{
    protected function consume($data = '')
    {
        try {

            $data = json_decode_ext($data);

            if (empty($data['pointId'])) {
                return;
            }

            $ip = $data['startArg']['ip'] ?? ($data['startArg']['post']['ip'] ?? '');

            $request = [];
            $startArg = $data['startArg'] ?? [];
            foreach ($startArg as $rqKey => $rkValue) {
                if ( ! in_array($rqKey, ['ip', 'url', 'server_name', 'repeated'])) {
                    if (is_string($rkValue) && ($rkJson = json_decode($rkValue, true))) {
                        $rkValue = $rkJson;
                    }
                    $request[$rqKey] = $rkValue;
                }
            }

            $request = json_encode($request, JSON_UNESCAPED_UNICODE);
            $response = json_encode($data['endArg'] ?? [], JSON_UNESCAPED_UNICODE);

            $startTime = $data['startTime'] ?? '';
            $endTime = $data['endTime'] ?? '';
            $runtime = 0;
            if ($startTime && $endTime) {
                $t = 10000;
                $runtime = intval((($endTime * $t) - ($startTime * $t)) / 10);
            }

            $insert = [
                'point_id' => $data['pointId'],
                'parent_id' => $data['parentId'] ?? '',
                'point_name' => $data['pointName'],
                'is_next' => intval($data['isNext']),
                'depth' => $data['depth'],
                'status' => $data['status'],
                'repeated' => $data['startArg']['repeated'] ?? 0,
                'ip' => $ip,
                'url' => $data['startArg']['url'] ?? '',
                'request' => $request,
                'response' => $response,
                'server_name' => $data['startArg']['server_name'] ?? '',
                'start_time' => intval($startTime),
                'end_time' => intval($endTime),
                'runtime' => $runtime
            ];

            $this->_getModel()->data($insert)->save();
        } catch (\Throwable|\Exception $e) {
            trace($e->__toString(), 'error');
        }
    }

    protected function _getModel()
    {
        return model('HttpTracker');
    }
}

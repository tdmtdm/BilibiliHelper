<?php

/*!
 * metowolf BilibiliHelper
 * https://i-meto.com/
 *
 * Copyright 2018, metowolf
 * Released under the MIT license
 */

namespace BilibiliHelper\Plugin;

use BilibiliHelper\Lib\Log;
use BilibiliHelper\Lib\Curl;

class SmallTV extends Base
{
    const PLUGIN_NAME = 'smalltv';

    protected static function init()
    {
        if (!static::data('smallTV')) {
            static::data('smallTV', []);
        }
    }

    protected static function work()
    {
        foreach (static::$config['data']['websocket']['smalltv'] as $tvid => $roomid) {
            static::$config['data'][static::PLUGIN_NAME]['smallTV'][$tvid] = [
                'roomid' => $roomid,
                'tvid' => $tvid,
                'status' => 0,
                'lock' => time() + mt_rand(5, 30),
            ];
            Log::notice("直播间 {$roomid} 加入队列");
        }

        foreach (static::data('smallTV') as $vo) {
            if ($vo['status'] == 0 && $vo['lock'] <= time()) {
                if (static::check($vo)) {
                    static::join($vo);
                } else {
                    static::unset($vo);
                }
            }
            if ($vo['status'] == 1 && $vo['lock'] <= time()) {
                static::notice($vo);
            }
        }
    }

    protected static function unset($value)
    {
        unset(static::$config['data'][static::PLUGIN_NAME]['smallTV'][$value['tvid']]);
    }

    protected static function check($value)
    {
        if (!in_array(intval(date('H')), static::config('SMALLTV_HOURS'))) {
            Log::info('当前为休息时段，放弃小电视抽奖');
            return false;
        }

        if (mt_rand(0, 100) >= static::config('SMALLTV_RATE')) {
            Log::info('根据抽奖比率设置 (' . static::config('SMALLTV_RATE') . '%)，放弃小电视抽奖');
            return false;
        }

        Log::info('检查直播间 ' . $value['roomid']);

        $payload = [
            'id' => $value['roomid'],
        ];
        $data = Curl::get('https://api.live.bilibili.com/room/v1/Room/room_init', static::sign($payload));
        $data = json_decode($data, true);

        if (isset($data['code']) && $data['code']) {
            Log::error("获取直播间 {$value['roomid']} 信息失败");
            return false;
        }

        if ($data['data']['is_hidden'] || $data['data']['is_locked'] || $data['data']['encrypted']) {
            Log::warning("直播间 {$value['roomid']} 可能存在问题，放弃小电视抽奖");
            return false;
        }

        static::entryAction($value['roomid']);

        return true;
    }

    protected static function entryAction($value)
    {
        Log::info("进入直播间 $value");
        $payload = [
            'room_id' => $value,
        ];
        Curl::post('https://api.live.bilibili.com/room/v1/Room/room_entry_action', static::sign($payload));
        Heart::web($value);
    }

    protected static function join($value)
    {
        $payload = [
            'roomid' => $value['roomid'],
            'raffleId' => $value['tvid'],
        ];
        $data = Curl::post('https://api.live.bilibili.com/gift/v2/smalltv/join', static::sign($payload));
        $data = json_decode($data, true);

        if (isset($data['code']) && $data['code']) {
            Log::error("小电视 #{$value['tvid']} 抽奖加入失败");
            static::unset($value);
            return;
        }

        static::$config['data'][static::PLUGIN_NAME]['smallTV'][$value['tvid']]['status'] = 1;
        static::$config['data'][static::PLUGIN_NAME]['smallTV'][$value['tvid']]['lock'] = time() + $data['data']['time'] + rand(5, 60);
    }

    protected static function notice($value)
    {
        $payload = [
            'roomid' => $value['roomid'],
            'raffleId' => $value['tvid'],
        ];
        $data = Curl::get('https://api.live.bilibili.com/gift/v2/smalltv/notice', static::sign($payload));
        $data = json_decode($data, true);

        if (isset($data['code']) && $data['code']) {
            Log::error("小电视 #{$value['tvid']} 抽奖失败");
            static::unset($value);
        }

        if ($data['data']['status'] == 3) {
            return;
        }

        Log::notice("在直播间 {$value['roomid']} 获得 {$data['data']['gift_num']} 个{$data['data']['gift_name']}");
        static::unset($value);
    }


}

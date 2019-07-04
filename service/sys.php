<?php
namespace XDApp\ServiceReg;

return new class {
    /**
     * 注册服务，在连接到 console 微服务系统后，会收到一个 sys_reg() 的rpc回调
     *
     * @param int $time
     * @param string $rand
     * @param string $hash
     * @return array
     */
    public function reg($time, $rand, $hash) {
        $context = Service::getCurrentContext();
        if (!$context) {
            return [
                'status' => false,
            ];
        }

        /**
         * @var Service $service
         */
        $service = $context->service;
        if ($service->isRegSuccess()) {
            return [
                'status' => false,
            ];
        }
        if ($hash !== sha1("$time.$rand.xdapp.com")) {
            # 验证失败
            return [
                'status' => false,
            ];
        }

        if (abs(time() - $time) > 180) {
            # 超时
            return [
                'status' => false,
            ];
        }
        $time = time();

        return [
            'status'  => true,
            'app'     => $service->appName,
            'name'    => $service->serviceName,
            'time'    => $time,
            'rand'    => $rand,
            'version' => $service->getVersion(),
            'hash'    => $service->getRegHash($time, $rand),
        ];
    }

    /**
     * 重启服务器
     */
    public function reload() {
        $context = Service::getCurrentContext();
        if (!$context)return false;
        if (!$context->service->isRegSuccess()) {
            return false;
        }

        $server = $context->service->getSwooleServer();
        if (!$server)return false;

        \Swoole\Timer::after(100, function() use ($server) {
            $server->reload(true);
        });

        return true;
    }

    public function regErr($msg, $data = null) {
        # 已经注册了
        $context = Service::getCurrentContext();
        if (!$context)return;

        if (!$context->service->isRegSuccess()) {
            return;
        }

        $context->service->setRegErr($msg, $data);
    }

    /**
     * 注册成功回调
     *
     * @param array $data 服务器返回的数据
     * @param string $time 时间戳
     * @param string $rand 随机字符串
     * @param string $hash 验证hash
     */
    public function regOk($data, $time, $rand, $hash) {
        $context = Service::getCurrentContext();
        if (!$context)return;

        if (strlen($rand) < 16) {
            return;
        }
        $service = $context->service;

        // 已经注册成功
        if ($service->isRegSuccess())return;

        if ($service->getRegHash($time, $rand) !== $hash) {
            // 断开连接
            $service->client->close();
            $service->warn("RPC服务注册失败，返回验证错误");

            return;
        }

        # 注册成功
        $service->setRegSuccess($data);
    }

    /**
     * 输出日志的RPC调用方法
     *
     * @param string $type
     * @param string $log
     * @param null|array $data
     */
    public function log($type, $log, $data = null) {
        $context = Service::getCurrentContext();
        if (!$context)return;
        $service = $context->service;
        if (!$service->isRegSuccess()) {
            return;
        }

        switch ($type) {
            case 'debug':
                $service->debug($log, $data);
                break;

            case 'warn':
                $service->warn($log, $data);
                break;

            case 'info':
                $service->info($log, $data);
                break;

            default:
                $service->log($log, $data);
                break;
        }
    }

    /**
     * 返回所有注册的名称
     *
     * @return array
     */
    public function getFunctions() {
        $context = Service::getCurrentContext();
        if (!$context)return [];
        $service = $context->service;

        if (!$service->isRegSuccess()) {
            return [];
        }
        return $service->getNames();
    }
};
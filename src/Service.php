<?php

namespace XDApp\ServiceReg;

class Service extends \Hprose\Service {
    /**
     * @var \Swoole\Coroutine\Client
     */
    public $client;

    public $appName;

    public $serviceName;

    public $version = '1.0';

    /**
     * 注册成功后服务器数据
     *
     * @var array
     */
    public $serviceData = [];

    protected $regSuccess = false;

    protected $isRegError;

    protected $key;

    protected $host;

    protected $port;

    protected $option = [];

    /**
     * @var \Swoole\Server
     */
    protected $swooleServer;

    protected $buffers = [];

    /**
     * @var callable|null
     */
    protected static $logger;

    protected static $isDebug = false;

    protected static $ProductionServer = [
        'host' => 'service-prod.xdapp.com',
        'port' => 8900,
    ];

    protected static $DevServer = [
        'host' => 'service-dev.xdapp.com',
        'port' => 8100,
    ];

    protected static $AsiaServer = [
        'host' => 'service-asia.xdapp.com',
        'port' => 8900,
    ];

    protected static $EuropeServer = [
        'host' => 'service-eu.xdapp.com',
        'port' => 8900,
    ];

    protected static $coList = [];
    protected static $coCurrentResult = false;
    protected static $lastError;
    protected static $receiveBuffer = [];

    const FLAG_SYS_MSG     = 1;                                            # 来自系统调用的消息请求
    const FLAG_RESULT_MODE = 2;                                            # 请求返回模式，表明这是一个RPC结果返回
    const FLAG_FINISH      = 4;                                            # 是否消息完成，用在消息返回模式里，表明RPC返回内容结束
    const FLAG_TRANSPORT   = 8;                                            # 转发浏览器RPC请求，表明这是一个来自浏览器的请求
    const FLAG_COMPRESS    = 16;                                           # 数据包body部分开启了压缩
    const PREFIX_LENGTH    = 6;                                            # Flag 1字节、 Ver 1字节、 Length 4字节、HeaderLength 1字节
    const HEADER_LENGTH    = 17;                                           # 默认消息头长度, 不包括 PREFIX_LENGTH
    const CONTEXT_OFFSET   = self::PREFIX_LENGTH + self::HEADER_LENGTH;    # 自定义上下文内容所在位置，23

    /**
     * 创建实例化对象
     *
     * @param $appName
     * @param $serviceName
     * @param $key
     * @return Service
     */
    public static function factory($appName, $serviceName, $key) {
        $service              = new static();
        $service->appName     = $appName;
        $service->serviceName = $serviceName;
        $service->key         = $key;
        $service->addServiceByDir(realpath(__DIR__ . '/../service'), false);

        return $service;
    }

    public function __construct() {
        if (!function_exists('snappy_uncompress')) {
            $this->info("您的php未安装snappy扩展，不支持数据压缩，你可以继续使用，但是不支持接受超过2MB的数据包，安装扩展 see https://github.com/kjdev/php-ext-snappy");
        }

        # 清理过期的协程
        \Swoole\Timer::tick(1000, function() {
            $t = microtime(true);
            foreach (self::$coList as $id => $item) {
                if ($t > $item['time']) {
                    self::$coCurrentResult = false;
                    \Swoole\Coroutine::resume($id);
                    unset(self::$coList[$id]);
                    self::$lastError = 'Rpc response timeout';
                    $this->warn(new \Exception("Coroutine timeout, auto remove it"), ['coId' => self::$coList[$id]] + $item);
                }
            }
            foreach (self::$receiveBuffer as $rpcId => $item) {
                if ($t > $item['time']) {
                    unset(self::$receiveBuffer[$rpcId]);
                }
            }
        });

        parent::__construct();
    }

    /**
     * 添加一个http代理
     *
     * 使用场景：
     * 当服务器里提供一个内部Http接口，但是它没有暴露给外网，也没有权限验证处理
     * 此时可以使用此方法，将它暴露成为一个XDApp的RPC服务，在网页里直接通过RPC请求将数据转发到SDK请求后返回，不仅可以实现内网穿透功能还可以在Console后台设置访问权限。
     *
     * 每个Http请求都会带以下头信息:
     *
     * * X-Xdapp-Proxy: True
     * * X-Xdapp-App-Id: 1
     * * X-Xdapp-Service: demo
     * * X-Xdapp-Request-Id: 1
     * * X-Xdapp-Admin-Id: 1
     *
     * ```php
     * $this->addHttpApiProxy('http://127.0.0.1:9999', 'myApi', ['get', 'post', 'delete', 'put'])
     * ```
     *
     * Vue页面使用
     *
     * 方法接受3个参数，$uri, $data, $timeout，其中 $data 只有在 post 和 put 有效，$timeout 默认 30 秒
     *
     * ```javascript
     * // 其中gm为注册的服务名
     * this.$service.gm.myApi.get('/uri?a=arg1&b=arg2');
     * // 最终将会请求 http://127.0.0.1:9999/uri?a=arg1&b=arg2
     * // 返回对象 {code: 200, headers: {...}, body: '...'}
     *
     * // 使用post, 第2个参数接受string或字符串, 第3个参数可设置超时
     * this.$service.gm.myApi.post('/uri?a=1', {a:'arg1', b:'arg2'}, 15);
     * ```
     *
     * @param string $url API根路径
     * @param string $alias 别名
     * @param array|string $methods 支持的模式，默认 get，可以是 delete, put 等
     * @param array $httpHeaders 默认会添加的Http头
     */
    public function addHttpApiProxy($url, $alias = 'api', $methods = ['get'], array $httpHeaders = []) {
        foreach ((array)$methods as $method) {
            $method = strtoupper($method);
            $this->addFunction(function($uri = '', $data = null, $timeout = 30) use ($url, $method, $httpHeaders) {
                $context = self::getCurrentContext();
                $apiMeta = parse_url($url . $uri);

                # 协程客户端
                $client = new \Swoole\Coroutine\Http\Client($apiMeta['host'], $apiMeta['port'], $apiMeta['scheme'] === 'https');
                $client->setHeaders(array_merge([
                    'Host'               => $apiMeta['host'],
                    'User-Agent'         => 'Chrome/49.0.2587.3',
                    'X-Xdapp-Proxy'      => 'True',
                    'X-Xdapp-App-Id'     => $context->appId,
                    'X-Xdapp-Service'    => $context->service->serviceName,
                    'X-Xdapp-Request-Id' => $context->requestId,
                    'X-Xdapp-Admin-Id'   => $context->adminId,
                ], $httpHeaders));
                $client->set([
                    'timeout' => $timeout,
                ]);

                switch ($method) {
                    case 'GET':
                        $client->get($apiMeta['path']);
                        break;

                    case 'POST':
                        $client->post($apiMeta['path'], $data);
                        break;

                    case 'DELETE':
                        $client->setMethod($method);
                        $client->execute($apiMeta['path']);
                        break;

                    case 'PUT':
                        $client->setMethod($method);
                        $client->setData($data);
                        $client->execute($apiMeta['path']);
                        break;
                }

                $rs = [
                    'code'    => $client->getStatusCode(),      # int
                    'headers' => $client->getHeaders(),         # array
                    'body'    => $client->getBody(),            # string
                ];
                $client->close();

                $this->debug(sprintf("[Curl] Http Proxy, method: %s, code: %s, url: %s, headers: %s, data: %s, body: %s", $method, $rs['code'], $url . $uri, json_encode($rs['headers']), substr(json_encode($data, JSON_UNESCAPED_UNICODE), 0, 1000), substr($rs['body'], 0, 1000)));

                return $rs;
            }, "{$this->serviceName}_{$alias}_" . strtolower($method));
        }
    }

    /**
     * 获取XDApp服务RPC调用对象
     *
     * @return mixed|\Hprose\Proxy
     */
    public function xdAppService() {
        return $this->service()->xdapp;
    }

    /**
     * 获取服务RPC调用对象
     *
     * @return ServiceClient
     */
    public function service() {

    }

    /**
     * 添加一个可以给Web访问的RPC方法
     * 例如:
     *
     * ```php
     * $this->addWebFunction(function($hi) {
     *     return $hi . ' at ' . time();
     * }, 'test')
     * ```
     *
     * 则可以在网页的vue中使用 `this.$service.myServiceName.test('hello')` （其中 myServiceName 为你注册的服务名）
     *
     * @param $func
     * @param string $alias
     * @param array $options
     * @return Service
     */
    public function addWebFunction($func, $alias = '', array $options = []) {
        if (is_array($alias) && empty($options)) {
            $options = $alias;
            $alias   = '';
        }
        if (empty($alias)) {
            if (is_string($func)) {
                $alias = $func;
            }
            elseif (is_array($func)) {
                $alias = $func[1];
            }
        }

        // 增加服务前缀
        $alias = $this->serviceName . '_' . $alias;

        return $this->addFunction($func, $alias, $options);
    }

    /**
     * addWebFunction的别称
     *
     * @param $func
     * @param string $alias
     * @param array $options
     * @return Service
     * @deprecated
     */
    public function register($func, $alias = '', array $options = []) {
        return $this->addWebFunction($func, $alias, $options);
    }

    /**
     * 连接到本地测试环境
     */
    public function connectToLocalDev($host = '127.0.0.1', $port = 8061) {

        return $this->connectTo($host, $port, [
            'tls'      => false,
            'localDev' => true,
            'dev'      => true,
        ]);
    }

    /**
     * 连接到测试环境
     */
    public function connectToDev() {
        return $this->connectTo(null, null, [
            'tls'      => true,
            'localDev' => false,
            'dev'      => true,
        ]);
    }

    /**
     * 连接到国内生产环境
     */
    public function connectToProduce() {
        return $this->connectTo(null, null, [
            'tls'      => true,
            'localDev' => false,
            'dev'      => false,
        ]);
    }

    /**
     * 连接到东南亚生产环境
     */
    public function connectToProduceAsia() {
        return $this->connectTo(self::$AsiaServer['host'], self::$AsiaServer['port'], [
            'tls'      => true,
            'localDev' => false,
            'dev'      => false,
        ]);
    }

    /**
     * 请使用 connectToAsia()
     *
     * @deprecated
     */
    public function connectToGlobal() {
        return $this->connectToProduceAsia();
    }

    /**
     * 连接到欧洲生产环境
     */
    public function connectToProduceEurope() {
        return $this->connectTo(self::$EuropeServer['host'], self::$EuropeServer['port'], [
            'tls'      => true,
            'localDev' => false,
            'dev'      => false,
        ]);
    }

    /**
     * 从一个目录里加载所有服务
     *
     * @param string $dir
     * @param bool $autoAddServiceNamePrefix 自动添加服务名前缀，默认true
     * @return array
     */
    public function addServiceByDir($dir, $autoAddServiceNamePrefix = true) {
        $list = [];
        $this->loadServiceFileByPath($list, rtrim($dir, '/\\'));

        if (true === $autoAddServiceNamePrefix) {
            $prefixDefault = $this->serviceName . '_';
        }
        else if (is_string($autoAddServiceNamePrefix)) {
            $prefixDefault = rtrim($autoAddServiceNamePrefix, '_') . '_';
        }
        else {
            $prefixDefault = '';
        }

        $success = [];
        foreach ($list as $name => $file) {
            $fun = $this->loadFromFile($file);
            try {
                if (!$fun) {
                    $this->info("RPC服务不可用, $fun, " . $file);
                }

                $isSysCall = strtolower($name) === 'sys' || strtolower(substr($name, 0, 4)) === 'sys_';
                $prefix    = $prefixDefault;
                //$prefix    = $isSysCall ? '' : $this->serviceApp . '_' . $this->serviceName . '_';

                if (is_callable($fun)) {
                    $myName = strtolower($prefix . $name);
                    if (isset($success[$myName])) {
                        $this->debug("RPC服务已经存在 " . $name . ", 已忽略, File: " . $file);
                        continue;
                    }

                    $this->addFunction($fun, $myName);
                    $success[$myName] = $isSysCall ? "{sys}" . substr($name, 4) . "()" : "$name()";
                }
                elseif (is_object($fun)) {
                    # 支持返回一个对象
                    $ref = new \ReflectionClass($fun);
                    foreach ($ref->getMethods() as $item) {
                        if ($item->isPublic() && !$item->isAbstract() && !$item->isStatic() && substr($item->name, 0, 1) !== '_') {
                            $myName = strtolower("{$prefix}{$name}_{$item->name}");
                            if (isset($success[$myName])) {
                                $this->debug("RPC服务已经存在 {$name}->{$item->name}, 已忽略, File: " . $file);
                                continue;
                            }

                            $this->addFunction([$fun, $item->name], $myName);
                            $success[$myName] = ($isSysCall ? "{sys}" . substr($name, 4) : "{$name}->") . "{$item->name}()";
                        }
                    }
                }
                elseif (is_array($fun)) {
                    # 支持返回一个数组
                    foreach ($fun as $k => $v) {
                        if (!is_callable($v)) {
                            continue;
                        }

                        $myName = strtolower("{$prefix}{$name}_{$k}");
                        if (isset($success[$myName])) {
                            $this->debug("RPC服务已经存在 {$name}->{$k}, 已忽略, File: " . $file);
                            continue;
                        }

                        $this->addFunction($v, $myName);
                        $success[$myName] = ($isSysCall ? "{sys}" . substr($name, 4) : "{$name}->") . "{$k}()";
                    }
                }
                else {
                    $this->info("RPC服务不可用, $fun, " . $file);
                }
            }
            catch (\Exception $e) {
                $this->warn($e);
            }
        }

        return $success;
    }

    protected function loadServiceFileByPath(& $list, $path, $prefix = '') {
        foreach (glob($path . '/*') as $file) {
            $name = strtolower(basename($file));
            if (substr($name, -4) !== '.php') {
                continue;
            }

            $name = substr($name, 0, -4);

            if (is_dir($file)) {
                $this->loadServiceFileByPath($file, $list, "{$name}_");
            }
            else {
                $list[$prefix . $name] = $file;
            }
        }
    }

    protected function loadFromFile($__file__) {
        return include($__file__);
    }

    /**
     * 连接服务器
     *
     * @param $host
     * @param $port
     * @param array $option
     * @return false|\Swoole\Coroutine\Client
     */
    public function connectTo($host, $port, $option = []) {
        if ($this->client) {
            return false;
        }

        $optionDefault = [
            'tls'        => true,
            'localDev'   => false,
            'dev'        => false,
            'serviceKey' => null,
        ];

        $option = array_merge($optionDefault, $option);
        $host   = $host ?? ($option['dev'] ? self::$DevServer['host'] : self::$ProductionServer['host']);
        $port   = $port ?? ($option['dev'] ? self::$DevServer['port'] : self::$ProductionServer['port']);

        $type = SWOOLE_SOCK_TCP;
        if ($option['tls']) {
            if (!defined('SWOOLE_SSL')) {
                $this->log('你的Swoole扩展不支持SSL，请重新安装开启OpenSSL支持');
                exit;
            }
            $type |= SWOOLE_SSL;
        }

        \Swoole\Runtime::enableCoroutine(true);
        $client = new \Swoole\Coroutine\Client($type);
        $client->set($this->getServiceClientConfig($option['tls'] ? $host : null));

        \Swoole\Coroutine::create(function() use ($client, $host, $port, $option) {
            $pingTick = 0;
            connect:
            while (true) {
                if (!$client->connect($host, $port, 1)) {
                    if ($this->isClosedByServer()) {
                        // 重新连接
                        // 4.2.0版本增加了对sleep 函数的Hook, 不会阻塞进程 see https://wiki.swoole.com/wiki/page/992.html
                        $this->warn('RPC 连接失败, 1秒后自动重连. errCode: ' . $client->errCode);
                        $client->close();
                        sleep(1);
                    }
                    else {
                        $this->warn('RPC 连接断开, 请重启服务. errCode: ' . $client->errCode);
                        return;
                    }
                }
                else {
                    $this->log("连接到服务器 " . ($option['tls'] ? 'tls' : 'tcp') . "://{$host}:{$port}");

                    # 增加ping
                    if ($pingTick > 0) {
                        \Swoole\Timer::clear($pingTick);
                    }
                    $pingTick = $this->createPingTick($client);
                    break;
                }
            }

            while (true) {
                $rs = $client->recv(-1);
                if ($rs === false || $rs === '') {
                    // 连接断开
                    if ($this->isClosedByServer()) {
                        // 重新连接
                        $this->warn('RPC 连接断开, 1秒后自动重连. errCode: ' . $client->errCode);
                        $client->close();
                        sleep(1);
                        goto connect;
                    }
                    else {
                        $this->warn('RPC 连接断开, 请重启服务. errCode: ' . $client->errCode);
                    }
                    break;
                }
                else {
                    $this->onReceive($client, $rs);
                }
            }
        });
        return $client;
    }

    protected function createPingTick($client) {
        return \Swoole\Timer::tick(5000, function() use ($client) {
            if ($this->regSuccess && $client->isConnected()) {
                $rs = $this->callXDAppService($client, 'ping', [], 0, 0, 5);
                if (!$rs) {
                    $this->log("Ping server timeout");
                    $client->close();
                }
            }
        });
    }

    /**
     * 请求RPC调用，会使用协程隐式切换
     *
     * 如果返回 false 需要获取错误，可以通过 `$this->getLastError()` 获取
     *
     * @param \Swoole\Client $client
     * @param string $fun
     * @param array $args
     * @param int $adminId 管理员ID
     * @param int $timeout 超时时间，秒
     * @return mixed|false 失败返回 false
     */
    public function callXDAppService($client, $fun, $args = [], $adminId = 0, $timeout = 30) {
        $id = \Swoole\Coroutine::getCid();

        self::$coList[$id] = [
            'time'     => $timeout + microtime(true),
            'function' => $fun,
            'adminId'  => $adminId,
        ];

        # AppId     | 服务ID      | rpc请求序号  | 管理员ID      | 自定义信息长度
        # ----------|------------|------------|-------------|-----------------
        # AppId     | ServiceId  | RequestId  | AdminId     | ContextLength
        # 4         | 4          | 4          | 4           | 1
        # N         | N          | N          | N           | C

        $headerAndBody = pack('NNNNC', 0, 0, $id, $adminId, 0);

        $body = \Hprose\Tags::TagCall . hprose_serialize($fun) . hprose_serialize($args) . \Hprose\Tags::TagEnd;
        $rs   = $this->socketSend($client, self::FLAG_SYS_MSG, $headerAndBody, $body);
        if ($rs) {
            # 协程挂起
            \Swoole\Coroutine::yield();
        }
        else {
            self::$lastError       = 'Send rpc request fail';
            self::$coCurrentResult = false;
        }
        unset(self::$coList[$id]);
        return self::$coCurrentResult;
    }

    /**
     * 当关闭时
     *
     * @return bool true: 可以尝试重新连接，不需要再尝试重新连接
     */
    protected function isClosedByServer() {
        $this->client     = null;
        $this->regSuccess = false;

        if (!$this->isRegError) {
            return true;
        }
        else {
            return false;
        }
    }

    protected function onReceive($cli, $data) {
        # 标识   | 版本    | 长度    | 头信息       | 自定义内容    |  正文
        # ------|--------|---------|------------|-------------|-------------
        # Flag  | Ver    | Length  | Header     | Context      | Body
        # 1     | 1      | 4       | 17         | 默认0，不定   | 不定
        # C     | C      | N       |            |             |
        #
        #
        # 其中 Header 部分包括
        #
        # AppId     | 服务ID      | rpc请求序号  | 管理员ID      | 自定义信息长度
        # ----------|------------|------------|-------------|-----------------
        # AppId     | ServiceId  | RequestId  | AdminId     | ContextLength
        # 4         | 4          | 4          | 4           | 1
        # N         | N          | N          | N           | C

        $dataArr = unpack('CFlag/CVer', substr($data, 0, 2));
        $flag    = $dataArr['Flag'];
        $ver     = $dataArr['Ver'];
        if ($ver === 1) {
            $this->debug('Receive data length: ' . strlen($data));

            if (self::FLAG_RESULT_MODE === ($flag & self::FLAG_RESULT_MODE)) {
                # 返回数据的模式
                // todo 双向功能请求支持
                if (!$this->regSuccess) {
                    # 在还没注册成功之前对服务器的返回数据不可信任
                    return;
                }

                $this->onRpcMessage($data);

                return;
            }

            $dataArr          = unpack('CFlag/CVer/NLength/NAppId/NServiceId/NRequestId/NAdminId/CContextLength', substr($data, 0, self::CONTEXT_OFFSET));
            $headerAndContext = substr($data, self::PREFIX_LENGTH, self::HEADER_LENGTH + $dataArr['ContextLength']);
            $rpcData          = substr($data, self::CONTEXT_OFFSET + $dataArr['ContextLength']);

            if (!$this->regSuccess) {
                # 在还没注册成功之前，只允许 sys_reg，sys_regErr，sys_regOk 方法执行
                try {
                    $fun = self::parseRequest($rpcData);
                    if (count($fun) > 1) {
                        # 还没有注册完成前不允许并行调用
                        return;
                    }
                    [$funName] = $fun[0];
                    if (!in_array($funName, ['sys_reg', 'sys_regErr', 'sys_regOk'])) {
                        $this->warn('在还没有注册完成时非法调用了Rpc方法:' . $funName . '(), 请求数据: ' . $rpcData);

                        return;
                    }
                }
                catch (\Exception $e) {
                    $this->warn('解析rpc数据失败, ' . $rpcData);
                    return;
                }
            }

            $context                   = new Context();
            $context->userdata         = new \stdClass();
            $context->headerAndContext = $headerAndContext;
            $context->requestId        = $requestId = $dataArr['RequestId'];
            $context->adminId          = $dataArr['AdminId'];
            $context->appId            = $dataArr['AppId'];
            $context->serviceId        = $dataArr['ServiceId'];

            if (self::FLAG_FINISH !== ($flag & self::FLAG_FINISH)) {
                # 未完毕
                $this->debug('Receive not finish data length: ' . strlen($data));
                if (isset($this->buffers[$requestId])) {
                    $this->buffers[$requestId]['rpcData'] .= $rpcData;
                    # 更换一个定时器
                    \Swoole\Timer::clear($this->buffers[$requestId]['timeTick']);
                    \Swoole\Timer::after(60 * 1000, function() use ($requestId) {
                        unset($this->buffers[$requestId]);
                        $this->debug('Clear timeout buffer, request id: '. $requestId);
                    });
                }
                else {
                    $this->buffers[$requestId] = [
                        'context' => $context,
                        'rpcData' => $rpcData,
                        'timeTick' => \Swoole\Timer::after(60 * 1000, function() use ($requestId) {
                            unset($this->buffers[$requestId]);
                            $this->debug('Clear timeout buffer');
                        }),
                    ];
                }
                return;
            }

            # 有分包数据，从buffer中获取
            if (isset($this->buffers[$requestId])) {
                $context = $this->buffers[$requestId]['context'];
                $rpcData = $this->buffers[$requestId]['rpcData'] . $rpcData;
                \Swoole\Timer::clear($this->buffers[$requestId]['timeTick']);
                unset($this->buffers[$requestId]);
            }

            $rsFlag = $flag | self::FLAG_RESULT_MODE;
            if (self::FLAG_COMPRESS === ($flag & self::FLAG_COMPRESS)) {
                # 压缩的数据包
                $rpcData = snappy_uncompress($rpcData);
                # 移除标签
                $rsFlag = $rsFlag ^ self::FLAG_COMPRESS;
            }

            $context->service = $this;

            //$this->userFatalErrorHandler = function($error) use ($cli, $context) {
            //    $this->socketSend($cli, $this->endError($error, $context), $context);
            //};

            try {
                $this->defaultHandle($rpcData, $context)->then(function($data) use ($cli, $headerAndContext, $rsFlag) {
                    $this->socketSend($cli, $rsFlag, $headerAndContext, $data);
                });
            }
            catch (\Exception $e) {
                $this->warn($e);
                $this->socketSend($cli, $rsFlag, $headerAndContext, $this->endError($e->getMessage(), $context));
            }
            catch (\Throwable $t) {
                $this->warn($t);
                $this->socketSend($cli, $rsFlag, $headerAndContext, $this->endError($t->getMessage(), $context));
            }
        }
        else {
            $this->warn('消息版本错误, ' . $ver);
        }
    }

    protected function onRpcMessage($message) {
        # 标识   | 版本    | 长度    | 头信息       | 自定义内容    |  正文
        # ------|--------|---------|------------|-------------|-------------
        # Flag  | Ver    | Length  | Header     | Context      | Body
        # 1     | 1      | 4       | 17         | 默认0，不定   | 不定
        # C     | C      | N       |            |             |
        # 0     | 1      | 2       | 6          | 23          | 23 or 23+ContextLength
        #
        #
        # 其中 Header 部分包括
        #
        # AppId     | 服务ID      | rpc请求序号  | 管理员ID      | 自定义信息长度
        # ----------|------------|------------|-------------|-----------------
        # AppId     | ServiceId  | RequestId  | AdminId     | ContextLength
        # 4         | 4          | 4          | 4           | 1
        # N         | N          | N          | N           | C
        # 6         | 10         | 14         | 18          | 22

        # CFlag/CVer/NLength/NAppId/NServiceId/NRequestId/NAdminId/CContextLength/nWorkerId/a*reqBody

        # c 1字节，N 4字节

        $dataArr = unpack('CFlag/CVer', substr($message, 0, 2));
        $flag    = $dataArr['Flag'];
        $ver     = $dataArr['Ver'];

        if ($ver === 1 && ($flag & self::FLAG_SYS_MSG) === self::FLAG_SYS_MSG) {
            # 14 为 RequestId 所在位置
            # Body位置第一个是Hprose的Tag
            $id = current(unpack('N', substr($message, 14, 4)));
            if (!$id) {
                return;
            }
            $bodyPos = self::CONTEXT_OFFSET;
            $finish  = ($flag & self::FLAG_FINISH) === self::FLAG_FINISH ? true : false;
            $tag     = substr($message, $bodyPos, 1);
            $rsBody  = substr($message, $bodyPos + 1);

            if (false === $finish) {
                # 消息未完成，加入到 buffer 里
                self::$receiveBuffer[$id] = [
                    'time' => microtime(true) + 30,        # 30秒后清理数据
                    'data' => $rsBody,
                ];

                return;
            }
            elseif (isset(self::$receiveBuffer[$id])) {
                # 将buffer加入
                $rsBody = self::$receiveBuffer[$id]['data'] . $rsBody;
                unset(self::$receiveBuffer[$id]);
            }

            switch ($tag) {
                case \Hprose\Tags::TagError:
                    $dataArr   = unpack('CFlag/CVer/NLength/NAppId/NServiceId/NRequestId/NAdminId/CContextLength', substr($message, 0, self::CONTEXT_OFFSET));
                    $errorData = [
                        'appId'     => $dataArr['AppId'],
                        'adminId'   => $dataArr['AdminId'],
                        'serviceId' => $dataArr['ServiceId'],
                    ];
                    try {
                        self::$lastError = hprose_unserialize($rsBody);
                        $this->warn(new \Exception(self::$lastError), $errorData);
                    }
                    catch (\Exception $e) {
                        self::$lastError = $e->getMessage();
                        $this->warn(new \Exception('解析RPC返回的错误信息失败: ' . self::$lastError), $errorData);
                    }
                    self::$coCurrentResult = false;
                    \Swoole\Coroutine::resume($id);
                    break;

                case \Hprose\Tags::TagResult:
                    self::$coCurrentResult = hprose_unserialize($rsBody);
                    self::$lastError       = null;

                    # 协程切换
                    \Swoole\Coroutine::resume($id);
                    break;

                default:
                    self::$coCurrentResult = false;
                    self::$lastError       = "RPC 系统调用收到一个未定义的方法返回: {$tag}{$rsBody}";
                    $this->warn(self::$lastError);
                    \Swoole\Coroutine::resume($id);
                    break;
            }
        }
    }


    function sendError($error, \stdClass $context) {
        self::warn($error);
        return parent::sendError($error, $context);
    }

    /**
     * 是否注册成功了
     *
     * @return bool
     */
    public function isRegSuccess() {
        return $this->regSuccess;
    }

    /**
     * 获取版本
     *
     * @return string
     */
    public function getVersion() {
        return $this->version;
    }

    /**
     * 设置版本
     *
     * @param $ver
     * @return $this
     */
    public function setVersion($ver) {
        $this->version = $ver;
        return $this;
    }

    public function getRegHash($time, $rand) {
        return sha1("{$this->appName}.{$this->serviceName}.$time.$rand.{$this->key}.xdapp.com");
    }

    public function setSwooleServer(\Swoole\Server $server) {
        $this->swooleServer = $server;
        return $this;
    }

    /**
     * @return \Swoole\Server|null
     */
    public function getSwooleServer() {
        return $this->swooleServer;
    }

    public function closeByServer($msg, $data) {
        $this->regSuccess  = false;
        $this->isRegError  = true;
        $this->serviceData = [];
        $this->info($msg, $data);
    }

    public function setRegSuccess(array $data) {
        if ($this->regSuccess) {
            $this->warn(new \Exception('服务器已注册, 但是又重复调用了setRegSuccess()方法'));
            return;
        }
        $this->regSuccess  = true;
        $this->isRegError  = false;
        $this->serviceData = $data;
        $this->info("RPC服务注册成功，服务名: {$this->appName}->{$this->serviceName}");

        $allService = [
            'sys'     => [],
            'service' => [],
            'other'   => [],
        ];
        foreach ($this->getNames() as $item) {
            if ($item === '#') {
                continue;
            }
            $pos = strpos($item, '_');
            if (false === $pos) {
                $allService['other'][] = $item;
                continue;
            }
            [$type, $func] = explode('_', $item, 2);
            $name = str_replace('_', '.', $func) . '()';
            switch ($type) {
                case 'sys':
                    $allService['sys'][] = $name;
                    break;
                case $this->serviceName:
                    $allService['service'][] = $name;
                    break;
                default:
                    $allService['other'][] = $name;
                    break;
            }
        }
        $this->info('系统RPC: ' . implode(', ', $allService['sys']));
        $this->info('已暴露Web RPC: ' . implode(', ', $allService['service']));
        if (count($allService['other']) > 0) {
            $this->info('已暴露但Web不会调用的RPC：' . implode(', ', $allService['other']));
            $this->info("若需要暴露给Web使用，请加: {$this->serviceName} 前缀");
        }

        # 增加 sentry
        if (isset($data['sentry']) && null === self::$logger) {
            self::sentryInit($data['sentry']);
        }
    }

    /**
     * 使用Sentry汇报错误，如果没有安装Sentry则返回false
     *
     * @see https://docs.sentry.io/error-reporting/configuration/?platform=php
     * @param array|string $dnsOrConfig
     * @param string $userName 推送到Sentry的用户名
     * @return bool
     */
    public static function sentryInit($dnsOrConfig, $userName = 'PhpSDK') {
        if (is_string($dnsOrConfig)) {
            $config = ['dsn' => $dnsOrConfig];
        }
        else {
            $config = $dnsOrConfig;
        }
        if (!function_exists('\\Sentry\\init')) {
            return false;
        }

        $option  = new \Sentry\Options($config);
        $client  = self::createSentryClient($option);
        $channel = new \Swoole\Coroutine\Channel(100);
        $uri     = sprintf('/api/%d/store/', $option->getProjectId());

        \Swoole\Runtime::enableCoroutine(true);

        set_error_handler(function($errno, $errstr, $errfile, $errline) {
            $error = new \ErrorException($errstr, $errno, 1, $errfile, $errline);
            self::warn($error);
            return true;
        });

        register_shutdown_function(function() {
            $e = error_get_last();
            if ($e == null) {
                return;
            }
            switch ($e['type']) {
                case E_ERROR:
                case E_PARSE:
                case E_USER_ERROR:
                case E_CORE_ERROR:
                case E_COMPILE_ERROR:
                    $error = new \ErrorException($e['message'], $e['type'], 1, $e['file'], $e['line']);
                    self::warn($error);
                    break;
            }
        });

        \Swoole\Coroutine::create(function() use ($client, $channel, $uri) {
            $closeTick = 0;
            $last      = null;
            $tickTime  = 0;
            while (true) {
                $data = $channel->pop(-1);
                if ($data === false) {
                    return;
                }
                for ($i = 0; $i < 2; $i++) {
                    $rs = $client->post($uri, $data);
                    if (false === $rs) {
                        echo '[warn] - ' . date('Y-m-d H:i:s') . " - Send data to sentry fail, host: {$client->host}, port: {$client->port}, errCode: {$client->errCode}, errMsg: {$client->errMsg}\n";
                        usleep(10000);
                        continue;
                    }
                    else if ($client->statusCode !== 200) {
                        echo '[warn] - ' . date('Y-m-d H:i:s') . " - Send data to sentry fail, host: {$client->host}, port: {$client->port}, result: {$client->body}\n";
                    }
                    $client->getBody();
                    break;
                }

                $now = time();
                if ($closeTick > 0 && $now - $tickTime > 5) {
                    // 超过5秒，移除后重新加
                    \Swoole\Timer::clear($closeTick);
                    $closeTick = 0;
                }
                if (!$closeTick) {
                    $closeTick = \Swoole\Timer::after(10000, function() use (&$closeTick, $client) {
                        // 10秒后关闭连接
                        $client->close();
                        $closeTick = 0;
                    });

                    $tickTime = $now;
                }
            }
        });

        self::setLogger(function($type, $msg, $data = []) use ($channel, $option, $userName) {
            if ($type !== 'warn' && $type !== 'error') {
                # 只有 warn 和 error 的会推送
                return;
            }
            $event = new \Sentry\Event();
            if (is_object($msg) && $msg instanceof \Throwable) {
                \Sentry\captureException($msg);
                self::addSentryEventException($event, $option, $msg);
            }
            else {
                $event->setMessage((string)$msg);
            }
            if ($data) {
                $event->setContext('data', $data);
            }
            $event->getUserContext()->setUsername($userName);
            $event->getTagsContext()->setData(['swoole' => SWOOLE_VERSION]);
            $data = gzencode(json_encode($event->toArray(), JSON_UNESCAPED_UNICODE), 9);
            $cid  = \Swoole\Coroutine::getCid();
            if ($cid > 0) {
                $channel->push($data);
            }
            else {
                \Swoole\Coroutine::create(function() use ($channel, $data) {
                    $channel->push($data);
                });
            }
        });

        return true;
    }

    /**
     * 获取swoole的客户端
     *
     * @param \Sentry\Options $option
     * @return \Swoole\Coroutine\Http\Client
     */
    protected static function createSentryClient(\Sentry\Options $option) {
        $dsn    = $option->getDsn();
        $parsed = parse_url($dsn);
        $ssl    = $parsed['scheme'] === 'https';
        $host   = $parsed['host'];
        $port   = $parsed['port'] ?? ($ssl ? 443 : 80);
        $header = [
            'Content-Type'     => 'application/json',
            'User-Agent'       => 'sentry.php/2.3.2',
            'X-Sentry-Auth'    => 'Sentry sentry_version=7, sentry_client=sentry.php/2.3.2, sentry_key=' . $option->getPublicKey(),
            'Content-Encoding' => 'gzip',
            'Accept-Encoding'  => 'gzip',
        ];

        $client = new \Swoole\Coroutine\Http\Client($host, $port, $ssl);
        $client->setHeaders($header);
        $client->set(['timeout' => 5.0]);
        return $client;
    }

    protected static function addSentryEventException(\Sentry\Event $event, \Sentry\Options $option, \Throwable $exception) {
        $exceptions       = [];
        $currentException = $exception;

        $serializer               = new \Sentry\Serializer\Serializer($option);
        $representationSerializer = new \Sentry\Serializer\RepresentationSerializer($option);
        do {
            $exceptions[] = [
                'type'       => \get_class($currentException),
                'value'      => $currentException->getMessage(),
                'stacktrace' => \Sentry\Stacktrace::createFromBacktrace($option, $serializer, $representationSerializer, $currentException->getTrace(), $currentException->getFile(), $currentException->getLine()),
            ];
        }
        while ($currentException = $currentException->getPrevious());
        $event->setExceptions($exceptions);
    }

    /**
     * 设置一个log处理方法
     *
     * 方法接受3个参数，分别是：
     *
     * * `$type` - 类型，包括：log, info, debug, warn
     * * `$msg`  - Log内容，字符串或Exception对象
     * * `$data` - 数组数据，可能是 null
     *
     * @param callable $logger
     */
    public static function setLogger(callable $logger) {
        self::$logger = $logger;
    }

    public static function log($msg, $data = null) {
        if (self::$logger) {
            $logger = self::$logger;
            $logger(__FUNCTION__, $msg, $data);
        }
        if (is_object($msg) && $msg instanceof \Exception) {
            $msg = $msg->getMessage();
        }
        echo '[log] - ' . date('Y-m-d H:i:s') . ' - ' . $msg . ($data ? ', ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }

    public static function info($msg, $data = null) {
        if (self::$logger) {
            $logger = self::$logger;
            $logger(__FUNCTION__, $msg, $data);
        }
        if (is_object($msg) && $msg instanceof \Exception) {
            $msg = $msg->getMessage();
        }
        echo '[info] - ' . date('Y-m-d H:i:s') . ' - ' . $msg . ($data ? ', ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }

    public static function warn($msg, $data = null) {
        if (self::$logger) {
            $logger = self::$logger;
            $logger(__FUNCTION__, $msg, $data);
        }
        if (is_object($msg) && $msg instanceof \Exception) {
            $msg = $msg->getMessage();
        }
        echo '[warn] - ' . date('Y-m-d H:i:s') . ' - ' . $msg . ($data ? ', ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }

    public static function openDebug($open = true) {
        self::$isDebug = $open;
    }

    public static function debug($msg, $data = null) {
        if (!self::$isDebug)return;
        if (self::$logger) {
            $logger = self::$logger;
            $logger(__FUNCTION__, $msg, $data);
        }
        if (is_object($msg) && $msg instanceof \Exception) {
            $msg = $msg->getTraceAsString();
        }
        echo '[debug] - ' . date('Y-m-d H:i:s') . ' - ' . $msg . ($data ? ', ' . json_encode($data, JSON_UNESCAPED_UNICODE) : '') . "\n";
    }

    /**
     * @param \Swoole\Client $cli
     * @param int $flag
     * @param string $headerAndContext
     * @param string $body
     * @return bool
     */
    protected function socketSend($cli, $flag, $headerAndContext, $body) {
        // Flag/CVer/NLength/NAppId/NServiceId/NRequestId/NAdminId/CContextLength
        $ver        = 1;
        $bodyLength = strlen($body);

        $headerAndContextLen = strlen($headerAndContext);

        if ($bodyLength <= 0x200000) {
            $package = pack('CCN', $flag | self::FLAG_FINISH, $ver, $headerAndContextLen + strlen($body)) . $headerAndContext . $body;
            $rs      = $cli->send($package);
            if (false === $rs) {
                $this->warn("发送RPC数据失败，长度" . strlen($package));
                return false;
            }
            $this->debug("发送RPC数据长度 $rs, 实际长度：" . strlen($package));
        }
        else {
            # 分包压缩
            $body = snappy_compress($body);
            $flag = $flag | self::FLAG_COMPRESS;
            for ($i = 0; $i < $bodyLength; $i += 0x200000) {
                $chunkLength = min($bodyLength - $i, 0x200000);
                $chunk       = substr($body, $i, $chunkLength);
                $currentFlag = ($bodyLength - $i === $chunkLength) ? $flag | self::FLAG_FINISH : $flag;
                $package     = pack('CNN', $currentFlag, $ver, $headerAndContextLen + $chunkLength) . $headerAndContext . $chunk;

                $rs = $cli->send($package);
                if (false === $rs) {
                    $this->warn("发送RPC数据失败，长度" . strlen($package));
                    return false;
                }
                $this->debug("发送RPC数据长度 $rs, 实际长度：" . strlen($package));
            }
        }
        return true;
    }

    /**
     * 客户端连接配置
     *
     * @param null|string $sslHost
     * @return array
     */
    protected function getServiceClientConfig($sslHost = null) {
        # 标识   | 版本    | 长度    | 头信息       | 自定义上下文  |  正文
        # ------|--------|---------|------------|-------------|-------------
        # Flag  | Ver    | Length  | Header     | Context     | Body
        # 1     | 1      | 4       | 13         | 默认0，不定   | 不定
        # C     | C      | N       |            |             |

        # length 包括 Header + Context + Body 的长度

        $rs = [
            'open_eof_check'        => false,
            'open_eof_split'        => false,
            'open_length_check'     => true,
            'package_length_type'   => 'N',
            'package_length_offset' => 2,
            'package_body_offset'   => 6,
            'package_max_length'    => 0x210000,
            'output_buffer_size'    => 0x10000000,
        ];

        // ssl 连接
        if ($sslHost) {
            $rs['ssl_verify_peer'] = true;
            $rs['ssl_host_name']   = $sslHost;
        }

        return $rs;
    }

    /**
     * 解析数据
     *
     * @param $reqBody
     * @return array
     * @throws \Exception
     */
    public static function parseRequest($reqBody) {
        $stream    = new \Hprose\BytesIO($reqBody);
        $functions = [];
        switch ($stream->getc()) {
            case \Hprose\Tags::TagCall:
                $functions = self::getFunc($stream);
                break;

            case \Hprose\Tags::TagEnd:
                break;

            default:
                break;
        }
        $stream->close();
        unset($stream);

        return $functions;
    }

    /**
     * 获取上下文对象
     *
     * !! 注意，只可在当前请求rpc方法内调用，若发生协程切换后调用则会导致数据出错
     *
     * ```php
     *
     * $service->addFunction(function() {
     *     $context = Service::getCurrentContext();
     *     // your code...
     * });
     *
     * ```
     *
     * @return Context
     */
    public static function getCurrentContext() {
        return self::$currentContext;
    }

    /**
     * 解析数据
     *
     * @param \Hprose\BytesIO $stream
     * @return array
     */
    protected static function getFunc(\Hprose\BytesIO $stream) {
        $fun    = [];
        $reader = new \Hprose\Reader($stream);
        do {
            $reader->reset();
            $name = $reader->readString();
            $args = [];
            $tag  = $stream->getc();
            if ($tag === \Hprose\Tags::TagList) {
                $reader->reset();
                $args = $reader->readListWithoutTag();
                $tag  = $stream->getc();
                if ($tag === \Hprose\Tags::TagTrue) {
                    $arguments = [];
                    foreach ($args as &$value) {
                        $arguments[] = &$value;
                    }
                    $args = $arguments;
                    $tag  = $stream->getc();
                }
            }

            if ($tag !== \Hprose\Tags::TagEnd && $tag !== \Hprose\Tags::TagCall) {
                throw new \Exception("Unknown tag: $tag\r\nwith following data:" . $stream->toString());
            }

            $fun[] = [$name, $args];
        }
        while ($tag === \Hprose\Tags::TagCall);

        return $fun;
    }
}
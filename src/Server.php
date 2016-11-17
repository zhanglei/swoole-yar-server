<?php
namespace Easth\Server;

use Exception;
use swoole_server as TCPServer;

class Server
{
    const VERSION = 'Swoole Yar Server 0.1.0';

    protected $app;

    protected $host = '0.0.0.0';

    protected $port = 8083;

    protected $pidFile = '';

    protected $tcpServer;

    protected $options = [];

    protected $appSnapshot = null;

    public static $validServerOptions = [
        'reactor_num',
        'worker_num',
        'max_request',
        'max_conn',
        'task_worker_num',
        'task_ipc_mode',
        'task_max_request',
        'task_tmpdir',
        'dispatch_mode',
        'message_queue_key',
        'daemonize',
        'backlog',
        'log_file',
        'log_level',
        'heartbeat_check_interval',
        'heartbeat_idle_time',
        'open_eof_check',
        'open_eof_split',
        'package_eof',
        'open_length_check',
        'package_length_type',
        'package_max_length',
        'open_cpu_affinity',
        'cpu_affinity_ignore',
        'open_tcp_nodelay',
        'tcp_defer_accept',
        'ssl_cert_file',
        'ssl_method',
        'user',
        'group',
        'chroot',
        'pipe_buffer_size',
        'buffer_output_size',
        'socket_buffer_size',
        'enable_unsafe_event',
        'discard_timeout_request',
        'enable_reuse_port',
        'ssl_ciphers',
        'enable_delay_receive',
    ];

    protected $shutdownFunctionRegistered = false;

    public static $buffer = [];

    public function __construct($host = '0.0.0.0', $port = 8083)
    {
        $this->host = $host;
        $this->port = $port;
    }

    public function initTCPServer()
    {
        if ($this->tcpServer) {
            return $this;
        }

        $this->tcpServer = new TCPServer($this->host, $this->port);

        $this->tcpServer->on('Start', [$this, 'onStart']);
        $this->tcpServer->on('Connect', [$this, 'onConnect']);
        $this->tcpServer->on('Receive', [$this, 'onReceive']);
        $this->tcpServer->on('Task', [$this, 'onTask']);
        $this->tcpServer->on('Close', [$this, 'onClose']);
        $this->tcpServer->on('WorkerError', [$this, 'onWorkerError']);
        $this->tcpServer->on('Finish', [$this, 'onFinish']);
        $this->tcpServer->on('WorkerStart', [$this, 'onWorkerStart']);
        $this->tcpServer->on('WorkerStop', [$this, 'onWorkerStop']);

        return $this;
    }

    public function setApplication($app)
    {
        $this->app = $app;

        return $this;
    }

    protected function resolveApplication()
    {
        if (!$this->app) {
            $this->app = require $this->basePath('bootstrap/app.php');
        }

        $this->snapshotApplication();
    }

    protected function snapshotApplication()
    {
        if (!$this->appSnapshot) {
            $this->appSnapshot = clone Application::getInstance();
        }
    }

    public function basePath($path = null)
    {
        return getcwd().($path ? '/'.$path : $path);
    }

    public function run()
    {
        $this->initTCPServer();

        $this->resolveApplication();

        $this->pidFile = $this->app->storagePath('swoole-yar-server.pid');

        if ($this->isRunning()) {
            throw new Exception('The server is already running.');
        }

        if (!empty($this->options)) {
            $this->tcpServer->set($this->options);
        }

        $this->tcpServer->start();
    }

    public function isRunning()
    {
        if (!file_exists($this->pidFile)) {
            return false;
        }

        $pid = file_get_contents($this->pidFile);

        return (bool) posix_getpgid($pid);
    }

    public function options($options = [])
    {
        $this->options = array_intersect_key($options, array_flip((array) static::$validServerOptions));

        return $this;
    }

    public function onStart($tcpServer)
    {
        echo "Start on {$this->host}:{$this->port}\n";
        file_put_contents($this->pidFile, $tcpServer->master_pid);
    }

    public function onConnect($tcpServer, $fd, $fromId)
    {
        //echo "Client: Connect --- {$fd}\n";
    }

    public function onReceive(TCPServer $tcpServer, $fd, $fromId, $data)
    {
        if (!isset(static::$buffer[$fd])) {
            static::$buffer[$fd] = [
                'step' => 0,
                'buff' => [],
                'ext'  => [],
            ];
        }

        //append
        static::$buffer[$fd]['buff'][] = $data;

        //header
        if (0 == static::$buffer[$fd]['step']) {
            $buffer     = implode('', static::$buffer[$fd]['buff']);
            $bufferLen = strlen($buffer);

            if ($bufferLen < 90) {
                return;
            }

            $header = new Header(
                unpack(
                    'Nid/nversion/Nmagic_num/Nreserved/a32provider/a32token/Nbody_len/a8package_name',
                    substr($buffer, 0, 90)
                )
            );

            //check magic_num
            if ($header['magic_num'] != Header::MAGIC_NUM) {
                unset(static::$buffer[$fd]);
                $tcpServer->close($fd);
                return;
            }

            //print_r($header);

            static::$buffer[$fd]['step'] = 1;
            static::$buffer[$fd]['buff'] = [substr($buffer, 90)];
            static::$buffer[$fd]['header']  = $header;
        }

        if (1 == static::$buffer[$fd]['step']) {
            $buffer     = implode('', static::$buffer[$fd]['buff']);
            $bufferLen = strlen($buffer);

            if ($bufferLen < static::$buffer[$fd]['header']['body_len'] - 8) {
                return;
            }

            $requestLen  = static::$buffer[$fd]['header']['body_len'] - 8;
            $request = new Request(
                msgpack_unpack(substr($buffer, 0, $requestLen))
            );

            //check version
            if ($request['i'] != static::$buffer[$fd]['header']['id']) {
                unset(static::$buffer[$fd]);
                $tcpServer->close($fd);
                return;
            }

            static::$buffer[$fd]['step'] = 0;
            static::$buffer[$fd]['buff'] = [substr($buffer, $requestLen)];
            static::$buffer[$fd]['header']  = [];

            //print_r($request);

            $tcpServer->task([$fd, $request]);
        }
    }

    public function onTask(TCPServer $tcpServer, $taskId, $fromId, $data)
    {
        list($fd, $request) = $data;

        $response = $this->app->dispatch($request);
        $this->tcpServer->send($fd, $response->send());

        return 'ok';
    }

    public function onClose($tcpServer, $fd, $fromId)
    {
        unset(static::$buffer[$fd]);
        echo "Client {$fd} close connection \n";
    }

    public function onFinish(tcpServer $tcpServer, $taskId, $data)
    {
        echo "AsyncTask ${taskId} Finish: result={$data}. PID=".$tcpServer->worker_pid.PHP_EOL;
    }

    function onWorkerError(tcpServer $tcpServer, $workerId, $workerPid, $exitCode)
    {
        echo "worker abnormal exit. WorkerId=$workerId|Pid=$workerPid|ExitCode=$exitCode\n";
    }

    public function onShutdown()
    {
        echo "Server: onShutdown\n";
    }

    public function onWorkerStart($tcpServer, $workerId)
    {

    }

    public function onWorkerStop($tcpServer, $workerId)
    {

    }
}

<?php
/*
 WSS逻辑

 1) 有三种类型的客户端，分别对应三种不同的处理类，连接进来后，第一个包必须是login包，login包就决定了这个连接是哪种连接，对应哪种处理类。
    login成功后，在WssGateway::instance()::theConnections数组里，以connection->id为KEY，保存一个结构体，结构体里保存的是连接的connection，该连接的处理类的实例
    a) web  => CWssWebCmd
    b) server  => CWssServerCmd
    c) client   => CWssClientCmd

 2) 有新的消息进来后，直接通过connection->id在theConnections数组里找，直接使用对应的处理类的实例的process()处理消息。

 3) 在WSS SERVER上还有另外一个数组 WssGateWay::instance()::theNames，这个数组的KEY是客户端名字，数组的值是connection->id
   KEY值是：
    a) web => [icafe_id]-web
    b) server => [icafe_id]-server
    c) client =>  [icafe_id]-client-[pcname]

    这个数组用来处理转发，连接login成功后，直接生成自己的KEY，将connection->id加入这个数组（如果有值，直接覆盖）

  4) 当连接断开时：
    a) 判断theNames数组里，自己的KEY对应的connection->id是不是自己的connection->id，如果是unset这个值，之所以这样处理，是因为客户端连接断开后，这边可能要等一段时间才会收到onclose消息，此时theNames数组可能已经是新的连接了。
    b) unset theConnections数组中对应的值

  5) 转发逻辑：
    a) 直接在包里加入target值，如果是转发给client，再加上pc_name值，例如：
       { target: web, action: update_pc_status }   转发给WEB的
       { target: server, action: update_pc_status }   转发给server的
       { target: client, pc_name: pc01, action: update_pc_status }  转发给client, 名字为pc01的。
    b) 处理带有target属性的包时，直接根据规则生成名字KEY，然后在theNames数组里找这个KEY，没有这个KEY的值，返回client_not_found。如果有，得到这个KEY对应的connection->id值，然后在theConnections数组里找，如果没有，返回client_connection_not_found，
       找到，用theConnections数组中的connection发送包
 */

require_once __DIR__ . "/../libs/Workerman/Autoloader.php";

use Workerman\Lib\Timer;
use Workerman\Worker;

$theKeyToConnectionId = array();			# $key => $connectionid
$theConnectionIdToConnection = array(); 	# $connectionid => $connection
$theKeyToLastActiveTime = array(); 			# $key => last_active_time

$theClientTimeout = 480;
$theClientPingTimeout = 300;
$theHostName = gethostname();
$theLicenseServerCode = gethostname();
$theSuperCafeId = -1;

$theSkipLogActions = array(
	'query_update_status',
	'main_refresh_client_status',
	'update_status',
	'query_icafemenuserver_status',
	'icafemenuserver_status',
	'ping',
	'seeding_status',
	'query_seeding_status',
	'pc_status',
	'pcs_status'
);

Worker::$logFile = '/www/log/wss/workerman.log';
Worker::$pidFile = '/var/run/workerman.pid';

$theWorker = new Worker("websocket://0.0.0.0:2346", array(
	'socket' => array('backlog' => 512400)
));
// 只能够有1个workerman进程，否则每个进程里都会有不同的连接数据，造成转发失败
$theWorker->count = 1;

# WorkerMan平滑重启的原理
#	只有在onXXXX() 函数中加载的PHP文件才会被reload
#
# 远程重启步骤：
#   1. 使用license, password, type=server方式登录（只支持008612345678, 00868888）
#   2. 发送reload_wss_server包
#   3. 工作进程会立即关掉所有连接，重启工作进程
#   4. onWorkerStop
#   5. onWorkerStart  (所有全局变量都会重置最初状态，等于全部重新来过一次）
#   6. Timer也会在工作进程重启时unset

$theWorker->onWorkerStart = function($worker)
{
	require_once __DIR__ . "/../config.php";
	require_once __DIR__ . "/../shared/function.php";
	require_once __DIR__ . "/../shared/billing.php";
	require_once __DIR__ . "/wsclassclient.php";
	require_once __DIR__ . "/wsclassweb.php";
	require_once __DIR__ . "/wsclassserver.php";
	require_once __DIR__ . "/inc.php";

	wss_log(0, WSS_LOG_LEVEL_INFO, 0, null, "onWorkerStart");

	global $theClientTimeout, $theClientPingTimeout, $theLicenseServerCode, $theSuperCafeId;

	{
		$mysql_remote_idc = new MySQL(MYSQL_HOST_REMOTE, MYSQL_DB_IDC);
		$theClientTimeout = $mysql_remote_idc->execScalar("SELECT config_value FROM table_configure WHERE config_key = 'wss_lock_timeout' AND config_region = 86", array(), 480);
		$theClientPingTimeout = $mysql_remote_idc->execScalar("SELECT config_value FROM table_configure WHERE config_key = 'wss_ping_timeout' AND config_region = 86", array(), 300);

		$mysql_remote_license = new MySQL(MYSQL_HOST_REMOTE, MYSQL_DB_LICENSE);
		$row = $mysql_remote_license->queryOne("SELECT * FROM table_license WHERE license_name = ?", array('000000'));
		if (!is_null($row))
		{
			$theLicenseServerCode = $row['license_server_code'];
			$theSuperCafeId = $row['object_id'];
		}
		wss_log(0, WSS_LOG_LEVEL_INFO, 0, null, "license_server_code=" . $theLicenseServerCode);
	}

	// 由于Workerman是两层进程结构，上层是守护进程，下层是工作进程，守护进程因为include config.php的关系时区是UTC，而工作进程就是当前时区，需要强制设置为UTC
	date_default_timezone_set('UTC');
	Timer::add(60, 'monitor_wss_timeout_but_not_onclose_connections');
};

$theWorker->onWorkerStop = function()
{
	wss_log(0, WSS_LOG_LEVEL_INFO, 0, null, "onWorkerStop");
};

$theWorker->onConnect = function($connection)
{
	$connection->onWebSocketPing = function($conn)
	{
		$conn->send(pack('H*', '8a00'), true);

		global $theConnectionIdToConnection, $theKeyToLastActiveTime;
		$id = $conn->id;

		if (isset($theConnectionIdToConnection[$id]))
		{
			$cmd = $theConnectionIdToConnection[$id]['cmd'];
			$theKeyToLastActiveTime[$cmd->theKey] = time();
		}
	};

	wss_log(0, WSS_LOG_LEVEL_INFO, $connection->id, null, 'onConnect');
};

$theWorker->onError = function($connection, $code, $msg)
{
	wss_log(0, WSS_LOG_LEVEL_INFO, $connection->id, null, "onError: $code $msg");
};

$theWorker->onClose = function($connection)
{
	unregister($connection->id);
	wss_log(0, WSS_LOG_LEVEL_INFO, $connection->id, null, "onClose");
};

$theWorker->onMessage = function($connection, $data)
{
	global $theSkipLogActions, $theConnectionIdToConnection, $theKeyToLastActiveTime, $theHostName, $theLicenseServerCode, $theSuperCafeId;

	$action = 'ERR';
	$data_text = $data;
	$icafe_id = 0;
	try
	{
		$cmd = null;
		if (isset($theConnectionIdToConnection[$connection->id]))
			$cmd = $theConnectionIdToConnection[$connection->id]['cmd'];

		// 第一个包必须是登录包
		$data = json_decode($data, true);
		if (!isset($data['action']))
			throw new Exception('Invalid action');
		$action = $data['action'];

		if (is_null($cmd) && isset($data['icafe_id']))
			$icafe_id = $data['icafe_id'];

		if (!is_null($cmd))
		{
			$icafe_id = $cmd->theCafeId;
			$theKeyToLastActiveTime[$cmd->theKey] = time();
		}

		# 为防止log太大，隐掉大量的垃圾包
		if (!in_array($action, $theSkipLogActions))
			wss_log($icafe_id, WSS_LOG_LEVEL_INFO, $connection->id, $data_text, null);

		if (!is_null($cmd) && strcasecmp($action, 'login') == 0)
			return;

		if (is_null($cmd))
		{
			if (!isset($data['type']))
				throw new Exception("Invalid type");

			list($action, $type) = array($data['action'], $data['type']);
			if ($action != 'login')
				throw new Exception('Need login first');

			$cmd = null;
			if ($type == 'client')
				$cmd = new WsClientCmd();
			if ($type == 'server')
				$cmd = new WsServerCmd();
			if ($type == 'web')
				$cmd = new WsWebCmd();

			if ($cmd == null)
				throw new Exception('Invalid type');
		}

		if (strcasecmp($data['action'], 'login') != 0 && strcasecmp($theHostName, $theLicenseServerCode) != 0 && $icafe_id != $theSuperCafeId)
		{
			wss_log($icafe_id, WSS_LOG_LEVEL_ERROR, $connection->id, $data['action'], 'wss server changed');

			// if wss from web and wss server already changed, send kick_out to web, let web redirect to login.php
			if ($cmd instanceof WsWebCmd)
			{
				$data = array('action' => 'kick_out');
				$connection->send(json_encode($data) . " \n");
			}
			return;
		}
		
		if($cmd instanceof WsClientCmd)
			$data['from'] = 'client';
		if($cmd instanceof WsServerCmd)
			$data['from'] = 'server';
		if($cmd instanceof WsWebCmd)
			$data['from'] = 'web';

		$ret = $cmd->process($data);
		if ($ret === false)
		{
			$data['result'] = '-ERR';
			$data['message'] = $cmd->theLastError;
			if (!in_array($action, $theSkipLogActions))
				wss_log($icafe_id, WSS_LOG_LEVEL_ERROR, $connection->id, $data, null);
			$connection->send(json_encode($data) . " \n");
			return;
		}

		$body = json_encode(array('action' => $action, 'result' => '+OK', 'from' => 'wss-server')) . " \n";
		if (is_array($ret))
		{
			$ret['action'] = $action;
			$ret['result'] = '+OK';
			$body = json_encode($ret) . " \n";
		}
		$connection->send($body);

		if ($action == 'login')
			register($connection, $cmd);
	}
	catch (Exception $e)
	{
		if (!in_array($action, $theSkipLogActions))
			wss_log($icafe_id, WSS_LOG_LEVEL_ERROR, $connection->id, $data_text, $e->getMessage());

		// If the message processing class needs to close the connection, the close_me will be popped from the inside. 
		// For example, when the client is connected, it finds that the WSS SERVER connected to the SERVER is inconsistent. 
		// In this case, the connection should be closed directly.
		if (strcasecmp($e->getMessage(), 'close_me') == 0)
		{
			$connection->close();
			return;
		}

		$connection->send(json_encode(array('action' => $action, 'result' => '-ERR', 'message' => $e->getMessage()))." \n");
	}
};


Worker::runAll();
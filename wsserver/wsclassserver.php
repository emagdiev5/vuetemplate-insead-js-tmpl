<?php

use Workerman\Connection\ConnectionInterface;

class WsServerCmd
{
	public $theCafeId = 0;
	public $theKey = '';

	private $theLicenseName = '';
	public $theLastError = '';
	public $theIp = '';

	private function login($data)
	{
		$license = isset($data['license']) ? $data['license'] : '';
		$password = isset($data['password']) ? $data['password'] : '';

		if (strlen($license) == 0 || strlen($password) == 0)
			throw new Exception('Invalid request');

		$mysql_remote_license = new MySQL(MYSQL_HOST_REMOTE, MYSQL_DB_LICENSE);
		$row = $mysql_remote_license->queryOne("select * from table_license where license_name = ? and license_password = ?", array($license, $password));
		if (is_null($row))
			throw new Exception("license not found");

		$this->theLicenseName = $row['license_name'];
		$this->theCafeId = $row['object_id'];

		return true;
	}

	function onRegistered()
	{
		// if server connected, notify web
		send_icafecloud_server_status_to_web($this->theCafeId);
	}

	function onUnregistered()
	{
		// if server disconnected, notify web
		send_icafecloud_server_status_to_web($this->theCafeId);
	}

	private function reload_wss_server($data)
	{
		# 000000 / youngzsoft888
		if (in_array($this->theLicenseName, array('000000')))
		{
			posix_kill(posix_getppid(), SIGUSR1);   # 断开所有连接，工作进程立即重启
			#posix_kill(posix_getppid(), SIGQUIT);   # 不断开现有连接，等所有连接自己退出后，再重启
			$dir = dirname(dirname(__FILE__)).'/s_compile';
			$files = array_diff(scandir($dir), array('.','..'));
			foreach ($files as $file) 
			{
				if(!is_dir($dir.'/'.$file))
					unlink($dir.'/'.$file); 
			}
		}
	}

	private function query_cp_server_status()
	{
		if (!in_array($this->theLicenseName, array('000000')))
			return false;

		$data = array();

		$fp = popen('top -b -n 2 | grep -E "^(Cpu|Mem|Tasks)"',"r");
		$rs = "";
		while(!feof($fp))
		{
			$rs .= fread($fp,1024);
		}
		pclose($fp);
		$sys_info = explode("\n",$rs);

		$cpu_info = explode(",",$sys_info[4]);
		$mem_info = explode(",",$sys_info[5]);

		// CPU
		$cpu_usage = trim(trim($cpu_info[0],'Cpu(s): '),'%us');
		$data['CPU usage'] = $cpu_usage . '%';

		// MEM
		$mem_total = trim(trim($mem_info[0],'Mem: '),'k total');
		$mem_used = trim($mem_info[1],'k used');
		$mem_usage = round(100*intval($mem_used)/intval($mem_total),2);
		$data['Mem total'] = number_format($mem_total / 1024.0, 2) . 'M';
		$data['Mem used'] = number_format($mem_used / 1024.0,2) . 'M';
		$data['Mem usage'] = $mem_usage . '%';

		$fp = popen('df -lh | grep -E "^(/)"',"r");
		$rs = fread($fp,1024);
		pclose($fp);

		$rs = preg_replace("/\s{2,}/",' ',$rs);
		$hd = explode(" ",$rs);
		$hd_avail = trim($hd[3],'G');
		$hd_usage = trim($hd[4],'%');
		$data['HDD avail'] = $hd_avail . 'G';
		$data['HDD usage'] = $hd_usage . '%';

		$data['wss connections'] = ConnectionInterface::$statistics['connection_count'];
		$data['wss send fail'] = ConnectionInterface::$statistics['send_fail'];
		$data['wss total request'] = ConnectionInterface::$statistics['total_request'];

		return array('server' => $data);
	}

	private function update_to_idc_succeed($data)
	{
		wss_send_to_web($this->theCafeId, array('action' => 'game_list_changed'));
		return true;
	}

	private function delete_idc_succeed($data)
	{
		wss_send_to_web($this->theCafeId, array('action' => 'game_list_changed'));
		return true;
	}

	function process($data)
	{
		if (isset($data['target']))
		{
			// 如果是server发往web的message，记录在log中
			if (strcasecmp($data['target'], 'web') == 0)
			{
				if (strcasecmp($data['action'], 'message') == 0)
				{
					$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
					cafe_game_log('Server', 'Message', $data['message'], $this->theCafeId, '', $this->theIp, $mysql);
				}
			}

			$ret = wss_forward($this->theCafeId, $data);
			if (!$ret)
				$this->theLastError = $data['target']."_not_online";
			return $ret;
		}

		$action = $data['action'];
		if (!method_exists($this, $action))
			throw new Exception('method not found: ' . $action);

		return $this->$action($data);
	}
}

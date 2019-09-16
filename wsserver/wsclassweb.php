<?php

class WsWebCmd
{
	public $theCafeId = 0;
	public $theKey = '';

	public $theStaffName = '';
	public $theLastError = '';
	public $theIp = '';
	private $theStaffRole = 'admin';
	private $theUsingBilling = 1;
	private $theTimezone = '+00:00';
	private $theLicenseType = null; // 当需要执行IDC类型的操作时，查询一次LICENSE TYPE
	private $theAdminUserActions = array('game_download', 'game_delete', 'clear_local_hot', 'query_folder_files', 'extract_icon');
	private $theIdcUserActions = array('seed_start', 'seed_stop', 'check_update_idc', 'update_idc', 'delete_idc', 'seed_start_all', 'seed_stop_all', 'run_game');

	// 原理：网吧登录后，网吧员工登录，在table_staff表中会记录员工最后一次登录的IP
	// wss连通后，要用网吧ID，员工的用户名和token来登录，会验证wss和员工网页登录的IP是否相同
	private function login($data)
	{
		$icafe_id = isset($data['icafe_id']) ? $data['icafe_id'] : 0;
		$staff_name = isset($data['user_name']) ? $data['user_name'] : '';
		$token = isset($data['token']) ? $data['token'] : '';

		if ($icafe_id == 0 || empty($staff_name) || empty($token))
			throw new Exception('Invalid request');

		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		$role = 'admin';
		if (strcasecmp($token, 'youngzsoft_test_token_KDSH#^(KLDSH') != 0)
		{
			$row = $mysql->queryOne("select * from table_staff where staff_icafe_id = ? and staff_name = ? and staff_last_token = ?", array($icafe_id, $staff_name, $token));
			if (is_null($row))
				throw new Exception('login failed');

			$role = ($row['staff_role'] == LOGIN_STAFF_ROLE_ADMIN ? 'admin' : 'staff');
		}

		$this->theCafeId = $icafe_id;
		$this->theStaffName = $staff_name;
		$this->theStaffRole = $role;
		$this->theUsingBilling = getConfigValue($icafe_id, 'license_using_billing', 1, $mysql);
		$this->theTimezone = getConfigValue($icafe_id, 'timezone', '+00:00', $mysql);

		return true;
	}

	// WEB的badge更新原理
	// 1. 登录成功发送一次badge
	// 2. shop有待处理的订单时推送shop_badge
	// 3. 有push_checkout的情况时，推送
	// 4. 当处理完pending checkout的记录时，会立即刷新pending checkout pc列表，包里面有count，直接用此count更新badge
	function onRegistered()
	{
		// 登录成功发送一次badge
		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		$data = array(
			'action' => 'badge',
			'shop' => $mysql->execScalar("SELECT COUNT(DISTINCT order_no) FROM table_order WHERE icafe_id = ? AND order_status = ?", array($this->theCafeId, ORDER_STATUS_PENDING))
		);
		wss_send_to_web($this->theCafeId, $data);
	}

	function onUnregistered()
	{
	}

	private function ping($data)
	{
		return true;
	}

	# send admin message from web to client pc
	private function admin_message($data)
	{
		wss_send_to_client($this->theCafeId, $data['pc_name'], $data);
	}

	# CP查询某个PC的状态
	# CP的PC页，在初始化PC列表后，会要求wss server把在wss server中有连接的PC的状态发过来
	private function query_online_clients_status($data)
	{
		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		notify_client_status($this->theCafeId, isset($data['pc_name']) ? $data['pc_name'] : null, NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
		return true;
	}

	# 从CP，客户端等发来，要求向客户端发client_status包
	# 支持按pc_name, 按member_id
	private function push_clientstatus($data)
	{
		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		if (isset($data['member_id']))
			$data['pc_name'] = $mysql->execScalar("SELECT status_pc_name FROM table_pc_status WHERE icafe_id = ? AND status_member_id = ?", array($this->theCafeId, $data['member_id']), '');

		if (!isset($data['pc_name']))
			return;
		
		notify_client_status($this->theCafeId, $data['pc_name'], NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
	}

	// 查询icafecloud server的在线状态
	private function icafecloud_server_status($data)
	{
		send_icafecloud_server_status_to_web($this->theCafeId);
	}

	# Report update problem for IDC games to IDC license and REGION licenses
	private function report_idc_game_update_problem($data)
	{
		if (empty($data['games']) || !is_array($data['games']) || count($data['games']) == 0)
			return false;

		foreach ($data['games'] as $game)
		{
			foreach ($game['targets'] as $target)
			{
				$wss_data = array('action' => 'remind', 'message' => 'assist', 'detail' => $game['detail'], 'target' => 'web', 'icafe_id' => $target['icafe_id'], 'pc' => '', 'level' => 'error', 'timeout' => 0, 'billing_log_id' => $target['billing_log_id']);
				wss_forward($target['icafe_id'], $wss_data);
			}
		}
		return true;
	}

	private function getPkgName($pkg_id)
	{
		if (is_array($pkg_id))
			$pkg_id = join(",", $pkg_id);

		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		$row = $mysql->queryOne("SELECT GROUP_CONCAT(pkg_id) as pkg_id, GROUP_CONCAT(pkg_name) as pkg_name FROM table_icafe_package WHERE pkg_icafe_id = :icafe_id AND pkg_id IN($pkg_id)", array(':icafe_id' => $this->theCafeId));
		return is_null($row) ? sprintf('[%s]', $pkg_id) : sprintf("[%s] %s", $row['pkg_id'], $row['pkg_name']);
	}


	function process($data)
	{
		if (isset($data['target']) && $data['target'] != 'web')
		{
			$target = $data['target'];
			$action = $data['action'];

			// 检查用户执行的action是否有权限
			if (in_array($action, $this->theIdcUserActions))
			{
				if (is_null($this->theLicenseType))
				{
					$mysql_remote_license = new MySQL(MYSQL_HOST_REMOTE, MYSQL_DB_LICENSE);
					$this->theLicenseType = $mysql_remote_license->execScalar("SELECT license_type FROM table_license WHERE object_id = ?", array($this->theCafeId), LICENSE_TYPE_NORMAL);
				}

				if ($this->theLicenseType != LICENSE_TYPE_IDC && $this->theLicenseType != LICENSE_TYPE_REGION)
					throw new Exception('Illegal operation');
			}

			if (in_array($action, $this->theAdminUserActions))
			{
				if ($this->theStaffRole != 'admin')
					throw new Exception('Illegal operation');
			}

			$ret = wss_forward($this->theCafeId, $data);
			if (!$ret)
				$this->theLastError = $data['target']."_not_online";

			// 如果是web发往server的操作game的命令，记录在log中
			if (strcasecmp($target, 'server') == 0)
			{
				$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
				if (in_array($data['action'], array('game_download')))
				{
					$extra = sprintf(', local_path = %s, auto_update=%d, priority=%d', $data['local_path'], $data['auto_update'], $data['priority']);
					cafe_game_log('Web', $data['action'], $this->getPkgName($data['pkg_id']) . $extra, $this->theCafeId, $this->theStaffName, $this->theIp, $mysql);
				}
				else if (in_array($data['action'], array('update_start', 'update_stop', 'update_remove', 'game_info_changed', 'seed_start', 'seed_stop', 'clear_local_hot', 'check_update_idc', 'update_idc')))
				{
					cafe_game_log('Web', $data['action'], $this->getPkgName($data['pkg_id']), $this->theCafeId, $this->theStaffName, $this->theIp, $mysql);
				}
				else if (in_array($data['action'], array('game_repair')))
				{
					cafe_game_log('Web', $data['action'], $this->getPkgName($data['pkg_id']) . ', repair_type=' . $data['repair_type'], $this->theCafeId, $this->theStaffName, $this->theIp, $mysql);
				}
				else if (in_array($data['action'], array('delete_idc', 'game_delete')))
				{
					// already delete in cp first
				}
				else if (in_array($data['action'], array('seed_start_all', 'seed_stop_all')))
				{
					cafe_game_log('Web', $data['action'], '', $this->theCafeId, $this->theStaffName, $this->theIp, $mysql);
				}
				else if (strcasecmp($data['action'], 'execute') == 0)
				{
					cafe_game_log('Web', $data['action'], $data['cmd_line'], $this->theCafeId, $this->theStaffName, $this->theIp, $mysql);
				}
			}


			return $ret;
		}

		$action = $data['action'];
		if (!method_exists($this, $action))
			throw new Exception('method not found: ' . $action);

		return $this->$action($data);
	}
}

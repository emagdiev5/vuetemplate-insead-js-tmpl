<?php
/*
 Client login
 1. The client connect to wss server.
 2. The client sends the login package (WssClientCmd::login()), the wss server replies the settings to the client.
 3. After login() succeeds, the wss server registers the WssClientCmd instance in the connections array.
 4. The server sends the client_status package to the client, (the current status of the PC) (WssClientCmd::onRegistered())
 5. If client_status is no session, the client stops at the Login page. If client_status is session state, the client will jump to the Games page.

 CP operations
 1. CP will update the database first when you do checkout, add balance or other operations effect the client's state.
 2. Send push_clientstatus to the wss server via js, trigger the wss server to send the client_staus package to the client.

 Client operation
 1. Once received the client_status package, the client will start a countdown() for the remaining time.
 2. The remaining time is based on the session. If it's offer session, that means how many time to left for the session. Not the whole left time for the member.
 3. When countdown is over, the client will send auto_checkout to wss server to call WssClientCmd::auto_checkout().
    if the user have other offers or balance, auto_checkout will help the client switch session auto and send another client_status to client.
 4. for member or offer group users, you can send request_checkout to server to call WssClientCmd::request_checkout(). It's really checkout from client.
 5. for prepaid and postpaid group users, the server is just notify the cashier if get the request_checkout. this two group may need to pay or refund to cashier.
*/

class WsClientCmd
{
	public $theCafeId = 0;
	public $theKey = '';
	public $thePcName = '';
	public $theLastError = '';

	private $theUsingBilling = 1;
	private $theTimezone = '+00:00';
	private $thePriceRows = null;

	// Here, the cafe_id is used as the KEY, and the configuration parameter set that needs to be returned to the client is saved, and is valid for 5 minutes.
	// If you generate a configuration table every time the client connects in, it will be very resource intensive.
	private function login($data)
	{
		global $theLicenseServerCode;
		// 此处无法使用$theCafeConfig这种全局变量, 非常奇怪
		if (!isset($data['icafe_id']) || !isset($data['ip']) || !isset($data['name']) || !isset($data['mac']))
			throw new Exception('Operation failure');

		$icafe_id = $data['icafe_id'];
		$ip = $data['ip'];
		$name = $data['name'];
		$mac = $data['mac'];

		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		$exists = $mysql->execScalar("SELECT count(*) as tp_count FROM table_pc WHERE icafe_id = ? AND pc_name = ?", array($icafe_id, $name), 0);

		if ($exists)
		{
			$mysql->execute("UPDATE table_pc SET pc_ip = ?, pc_mac = ? WHERE icafe_id = ? AND pc_name = ?",
				array($ip, $mac, $icafe_id, $name));
		}
		else
		{
			$pcs = $mysql->execScalar("SELECT count(*) as tp_count FROM table_pc WHERE icafe_id = ?", array($icafe_id), 0);
			if ($pcs >= 3)
			{
				$mysql_remote_license = new MySQL(MYSQL_HOST_REMOTE, MYSQL_DB_LICENSE);
				$license_pcs = $mysql_remote_license->execScalar("select license_pcs from table_license where object_id = ?", array($icafe_id), 3);
				if ($pcs >= $license_pcs)
				{
					$detail = 'Your PC number has exceeded the license limit';
					billingLogAddNew($icafe_id, '', $name, 'CLIENTREQUEST', 0, $detail, '', 0, 0, '', $mysql);
					$billing_log_id = $mysql->execScalar("SELECT log_id FROM table_billing_log WHERE icafe_id = ? AND log_event = 'CLIENTREQUEST' AND log_date >= DATE_ADD(NOW(), INTERVAL -5 MINUTE) ORDER BY log_date DESC LIMIT 0,1", array($icafe_id), 0);
					$wss_data = array('action' => 'remind', 'message' => 'assist', 'detail' => $detail, 'target' => 'web', 'icafe_id' => $icafe_id, 'pc' => '', 'level' => 'error', 'timeout' => 0, 'billing_log_id' => $billing_log_id);
					wss_forward($icafe_id, $wss_data);

					throw new Exception('License limit exceeded');
				}
			}

			$mysql->execute("INSERT INTO table_pc (icafe_id, pc_mac, pc_ip, pc_name, pc_group_id, pc_comment) VALUES (?,?,?,?,0,'')",
				array($icafe_id, $mac, $ip, $name));
		}

		$this->theCafeId = $icafe_id;
		$this->thePcName = $name;
		$this->thePriceRows = $mysql->query("SELECT * FROM table_billing_price WHERE price_icafe_id = ?", array($icafe_id));

		$settings = array();
		$rows = $mysql->query("SELECT config_key, config_value FROM table_icafe_config WHERE icafe_id = ?", array($icafe_id));
		foreach ($rows as $row)
		{
			$key = strtolower($row['config_key']);
			$value = $row['config_value'];

			if ($key == 'timezone')
				$this->theTimezone = $value;

			if ($key == 'license_using_billing')
				$this->theUsingBilling = $value;

			if (preg_match("/^[0-9]{1,100}$/i", $value))
				$value = intval($value);
			if (preg_match("/^[0-9]{1,100}\.[0-9]{1,100}$/i", $value))
				$value = floatval($value);
			$settings[$key] = $value;
		}

		$hostname = gethostname();
		$settings["url_api"] = "https://$hostname.icafecloud.com/api";
		$settings["url_shop"] = "https://$hostname.icafecloud.com/shop-client.php";
		$settings['license_server_code'] = $theLicenseServerCode;

		return array('settings' => $settings);
	}

	# 不使用billing功能的网吧，允许客户端创建会员，同时给会员一个较大的余额
	# 如果指定了account, password，表示是register页面过来的
	# 如果没有指定account, password，表示是点了guest直接登录的
	function member_register($data)
	{
		try
		{
			if ($this->theUsingBilling == 1)
				throw new Exception('Invalid request');

			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
			$member_account = isset($data['account']) ? $data['account'] : (isset($data['member_account']) ? $data['member_account'] : '');
			$passwordmd5 = isset($data['passwordmd5']) ? $data['passwordmd5'] : '';

			$balance = 0;
			$birthday = '0000-00-00';
			$first_name = '';
			$last_name = '';
			$phone = '';
			$email = '';
			$id_card = '';
			$member_group_id = MEMBER_GROUP_DEFAULT;
			$member_expire_time = '0000-00-00';

			# guest login
			if (!isset($data['account']) && !isset($data['member_account']) && !isset($data['passwordmd5']))
			{
				$member_account = '';
				$exists = true;
				for($i = 0; $i < 1000; $i ++)
				{
					// 排除0,O,o,I,l,1 作为登录名
					$randstr = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
					$member_account = $randstr[mt_rand(0,strlen($randstr) - 1)] . substr(strtoupper(md5(time())), 0, 5);
					$member_account = str_replace('0', '2', $member_account);
					$member_account = str_replace('1', '3', $member_account);

					$exists = $mysql->execScalar("select count(member_id) from table_member where member_account = :member_account and icafe_id = :icafe_id",
						array(':member_account' => $member_account, ':icafe_id' => $this->theCafeId));
					if (!$exists)
						break;
				}

				if ($exists)
					throw new Exception('Create guest failed');

				$passwordmd5 = md5('123456');
				$member_group_id = MEMBER_GROUP_GUEST;
				$member_expire_time = date('Y-m-d', strtotime('+30 day'));
			}
			# normal member login
			else
			{
				if (strlen($member_account) == 0)
					throw new Exception('Account is empty');

				if (strlen($passwordmd5) == 0)
					throw new Exception('Password is empty');

				$exists = $mysql->execScalar("select count(member_id) from table_member where member_account = ? and icafe_id = ?", array($member_account, $this->theCafeId));
				if ($exists)
					throw new Exception('Account already exists');
			}

			$mysql->execute("insert into table_member (icafe_id, member_account, member_password, member_balance, member_group_id,
						member_first_name, member_last_name, member_birthday, member_expire_time, member_phone, member_email, member_id_card,
						member_points, member_is_active)
					values (?, ?, ?, ?, ?,   ?, ?, ?, ?, ?, ?, ?, 0, 1)",
				array($this->theCafeId, $member_account, $passwordmd5, $balance, $member_group_id,
					$first_name, $last_name, $birthday, $member_expire_time, $phone, $email, $id_card));

			billingLogAddNew($this->theCafeId,
				$member_account,
				'',
				'ACCOUNTADD',
				0,
				'add account from client',
				'Cashier: ',
				0,
				0,
				'',
				$mysql);

			return $this->member_login(array('username' => $member_account, 'passwordmd5' => $passwordmd5));
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	# member用户 用户在客户端输入user,password登录
	# { action: member_login, username, password}
	function member_login($data)
	{
		try
		{
			$username = strval(isset($data['username'])? $data['username'] : '');
			$password = strval(isset($data['password']) ? $data['password'] : '');
			$passwordmd5 = strval(isset($data['passwordmd5']) ? $data['passwordmd5'] : '');
			if (strlen($username) == 0 || (strlen($password) == 0 && strlen($passwordmd5) == 0))
				throw new Exception("Invalid parameter");

			if (strlen($password) > 0)
				$passwordmd5 = md5($password);

			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
			$pc = $mysql->queryOne("SELECT * FROM table_pc WHERE icafe_id = ? AND pc_name = ?", array($this->theCafeId, $this->thePcName));
			if ($pc['pc_enabled']==0)
				throw new Exception('The PC is in maintenance');

			$member = $mysql->queryOne("SELECT * FROM table_member WHERE member_account = ? AND icafe_id = ? AND (member_group_id >= 0 OR member_group_id = ?)", array($username, $this->theCafeId, MEMBER_GROUP_GUEST));
			if (is_null($member))
				throw new Exception("Member not exists");

			if ($member['member_password'] != $passwordmd5)
				throw new Exception('Wrong password');

			$member_id = $member['member_id'];
			$status_row = $mysql->queryOne("SELECT * FROM table_pc_status WHERE icafe_id = ? AND status_member_id = ?", array($this->theCafeId, $member_id));
			if (!is_null($status_row))
			{
				// if the member login on the same pc, return status directly
				if (strcasecmp($status_row['status_pc_name'], $this->thePcName) == 0)
				{
					notify_client_status($this->theCafeId, $status_row['status_pc_name'], NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
					return true;
				}

				// if the member login on the other pc, checkout from other pc first.
				$status_row['pc_group_id'] = $mysql->execScalar("select pc_group_id from table_pc where icafe_id = ? and pc_name = ?", array($this->theCafeId, $status_row['status_pc_name']), 0);
				billing_session_checkout($this->theCafeId, $status_row['status_pc_name'], $status_row['pc_group_id'], $member['member_account'], $member['member_group_id'], 'auto', 0, $this->theTimezone, '', $mysql);
				notify_client_status($this->theCafeId, $status_row['status_pc_name'], NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
			}

			$pc = $mysql->queryOne("SELECT * FROM table_pc WHERE icafe_id = ? AND pc_name = ?", array($this->theCafeId, $this->thePcName));
			billing_session_start($this->theCafeId, $pc['pc_name'], $pc['pc_group_id'], $member_id, $this->theUsingBilling, $this->theTimezone, '', $mysql);

			notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
			return true;
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	function member_change_password($data)
	{
		try
		{
			$member_account = isset($data['member_account']) ? strval(@$data['member_account']) : strval(@$data['account']);
			$old_password_md5 = strval(@$data['old_password_md5']);
			$new_password_md5 = strval(@$data['new_password_md5']);
			if (strlen($member_account) == 0 || (strlen($old_password_md5) == 0 && strlen($new_password_md5) == 0))
				throw new Exception("Invalid parameter");

			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
			$exists = $mysql->execScalar("SELECT count(member_id) FROM table_member where member_account = ? and member_password = ? and icafe_id = ?",
				array($member_account, $old_password_md5, $this->theCafeId));
			if ($exists == 0)
				throw new Exception('Old password does not match');

			$mysql->execute("UPDATE table_member SET member_password = ? WHERE member_account = ? and icafe_id = ?",
				array($new_password_md5, $member_account, $this->theCafeId));

			return true;
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	# [deprecated] 客户端使用PHP更新hot
	function update_hot($data)
	{
		return true;
	}

	# 如果是member登录，客户端在offer用完时，切换member的其它offer或者使用余额
	function auto_checkout($data)
	{
		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		try
		{
			$pc_member_status = $mysql->queryOne("SELECT s.*, p.*, m.*
				FROM table_pc_status s, table_pc p, table_member m
				WHERE s.icafe_id = ? AND s.status_pc_name = ? AND s.icafe_id = p.icafe_id AND s.status_pc_name = p.pc_name AND s.status_member_id = m.member_id", array($this->theCafeId, $this->thePcName));
			if (is_null($pc_member_status))
			{
				notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_CLIENT, $this->theUsingBilling, $this->theTimezone, $mysql);
				return true;
			}

			billing_session_checkout($this->theCafeId, $this->thePcName, $pc_member_status['pc_group_id'], $pc_member_status['member_account'], $pc_member_status['member_group_id'], 'auto', 0, $this->theTimezone, '', $mysql);
			billing_session_start($this->theCafeId, $this->thePcName, $pc_member_status['pc_group_id'], $pc_member_status['member_id'], $this->theUsingBilling, $this->theTimezone, '', $mysql);
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
		return true;
	}

	# 客户端请求checkout
	# 客户端不做任何CHECK OUT操作，所有CHECK OUT操作由服务端完成
	function request_checkout($data)
	{
		try
		{
			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
			$status_row = $mysql->queryOne("SELECT s.*, p.*, m.*
				FROM table_pc_status s, table_pc p, table_member m
				WHERE s.icafe_id = ? AND s.status_pc_name = ? AND s.icafe_id = p.icafe_id AND s.status_pc_name = p.pc_name AND s.status_member_id = m.member_id", array($this->theCafeId, $this->thePcName));
			if (is_null($status_row))
			{
				notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
				return true;
			}

			# prepaid/postpaid的用户不允许客户自己check out，必须由柜员处理
			if ($status_row['member_group_id'] != MEMBER_GROUP_PREPAID && $status_row['member_group_id'] != MEMBER_GROUP_POSTPAID && $status_row['member_group_id'] != MEMBER_GROUP_OFFER)
			{
				billing_session_checkout($this->theCafeId, $this->thePcName, $status_row['pc_group_id'], $status_row['member_account'], $status_row['member_group_id'], 'client', 0, $this->theTimezone, '', $mysql);
				notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
			}

			// notify web client checkout
			wss_send_to_web($this->theCafeId, array(
				'action' => 'remind',
				'target' => 'web',
				'pc' => $status_row['pc_name'],
				'message' => 'checkout',
				'page' => 'computers'));

			return true;
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	function customer_feedback($data)
	{
		try
		{
			$text = $data['subject'] . "\n" . $data['message'];
			$member_account = isset($data['account']) ? $data['account'] : $data['member_account'];

			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
			billingLogAddNew($this->theCafeId,
				$member_account,
				$this->thePcName,
				'FEEDBACK',
				0,
				$text,
				'Computer: '.$this->thePcName,
				0,
				0,
				'',
				$mysql);
			return true;
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	function get_realtime_balance($data)
	{
		try
		{
			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);

			notify_client_status($this->theCafeId, $data['pc_name'], NOTIFY_CLIENT, $this->theUsingBilling, $this->theTimezone, $mysql);

			return true;
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	function syslog($data)
	{
		try
		{
			if (!isset($data['event']))
				throw new Exception('Operation failure');

			$event = strtoupper(trim($data['event']));
			if (!in_array($event, array('ADMINEXIT')))
				throw new Exception('Operation failure');

			$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
			billingLogAddNew($this->theCafeId,
				'',
				$this->thePcName,
				$event,
				0,
				'admin exit from client',
				'',
				0,
				0,
				'',
				$mysql);

			return true;
		}
		catch (Exception $e)
		{
			$this->theLastError = $e->getMessage();
		}

		return false;
	}

	function onRegistered()
	{
        // 客户端连接并注册成功，向客户端，向WEB通知状态
		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_CLIENT | NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
	}

	function onUnregistered()
	{
        // 客户端断线，向WEB通知状态
		$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
		notify_client_status($this->theCafeId, $this->thePcName, NOTIFY_WEB, $this->theUsingBilling, $this->theTimezone, $mysql);
	}

	function process($data)
	{
		if (isset($data['target']))
		{
			if (strcasecmp($data['target'], 'web') == 0 && strcasecmp($data['action'], 'remind') == 0)
			{
				// 如果是客户端下了单，需要在WEB上通知员工处理，把pending order badge加在里面
				if (strcasecmp($data['message'], 'order') == 0)
				{
					$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
					if (!isset($data['badge']))
						$data['badge'] = array('shop' => 0);

					$data['badge']['shop'] = $mysql->execScalar("SELECT COUNT(DISTINCT order_no) FROM table_order WHERE icafe_id = ? AND order_status = ?", array($this->theCafeId, ORDER_STATUS_PENDING), 0);
				}

				// assist 处理逻辑:
				// 1) 客户端点assist按钮，通过wss，这个时候CP可能在线可能不在线，不管这些，直接存到数据库的logs表。如果CP在线就向CP发notify
				// 2) CP收到notify，在页面的右上角显示这个toast，一直显示直到员工点击它，员工点击就把log_used_secs置为从创建到点击的时长
				// 3) CP进入任何页面时，从logs表中搜索是否有未处理的assist (log_used_secs = 0)，如果有，就把它们显示在页面右上角（在function.js中完成，各个页面不用修改）
				if (strcasecmp($data['message'], 'assist') == 0)
				{
					$mysql = new MySQL(MYSQL_HOST_LOCAL, MYSQL_DB_DEFAULT);
					billingLogAddNew($this->theCafeId, '', $this->thePcName, 'CLIENTREQUEST', 0, '', '', 0, 0, '', $mysql);
					$data['billing_log_id'] = $mysql->execScalar("SELECT log_id FROM table_billing_log WHERE icafe_id = ? AND log_event = 'CLIENTREQUEST' AND log_date >= DATE_ADD(NOW(), INTERVAL -5 MINUTE) ORDER BY log_date DESC LIMIT 0,1", array($this->theCafeId), 0);
				}
			}

			$ret = wss_forward($this->theCafeId, $data);
			if (!$ret)
				$this->theLastError = $data['target'] . '_not_online';
			return $ret;
		}

		$action = $data['action'];
		if (!method_exists($this, $action))
			throw new Exception('method not found: ' . $action);

		return $this->$action($data);
	}
}

<?php
define('WSS_LOG_PATH',  '/www/log/wss');

define('WSS_LOG_LEVEL_INFO',    0);
define('WSS_LOG_LEVEL_ERROR',   1);

define('NOTIFY_WEB',	1);
define('NOTIFY_CLIENT', 2);

function register($connection, $cmd)
{
	global $theKeyToConnectionId, $theConnectionIdToConnection, $theKeyToLastActiveTime;

	$id = $connection->id;
	$theConnectionIdToConnection[$id] = array('connection' => $connection, 'cmd' => $cmd);
	$icafe_id = $cmd->theCafeId;

	$connType = '';
	$key = '';
	if ($cmd instanceof WsClientCmd)
	{
		$key = sprintf('%d-client-%s', $icafe_id, $cmd->thePcName);
		$connType = 'client';
	}

	if ($cmd instanceof WsServerCmd)
	{
		$key = sprintf('%d-server', $icafe_id);
		$connType = 'server';
	}

	if ($cmd instanceof WsWebCmd)
	{
		$key = sprintf('%d-web-%s', $icafe_id, $cmd->theStaffName);
		$connType = 'web';
	}

	if ($key == '')
		return;

	$cmd->theKey = $key;
	$theKeyToLastActiveTime[$key] = time();

	if (isset($theKeyToConnectionId[$key]))
	{
		try
		{
			// 踢掉前面的对象
			$old_id = $theKeyToConnectionId[$key];
			if (isset($theConnectionIdToConnection[$old_id]['connection']))
			{
				if ($connType == 'web')
				{
					$data = array('action' => 'kick_out');
					$theConnectionIdToConnection[$old_id]['connection']->send(json_encode($data) . " \n");
					wss_log($icafe_id, WSS_LOG_LEVEL_INFO, $theConnectionIdToConnection[$old_id]['connection']->id, $data, null);
				}
				else
					$theConnectionIdToConnection[$old_id]['connection']->close();
			}
		}
		catch (Exception $e)
		{}
	}
	$theKeyToConnectionId[$key] = $id;

	wss_log($icafe_id, WSS_LOG_LEVEL_INFO, $id, null, 'register: ' . $key);
	$cmd->onRegistered();
}

# WssClient C++库在程序终止后，并不需要立即close connection
# 会造成已经有新的c++连接起来，但旧的c++连接仍然在的情况
function unregister($id)
{
	global $theConnectionIdToConnection, $theKeyToConnectionId, $theKeyToLastActiveTime;

	if (!isset($theConnectionIdToConnection[$id]))
		return;
	$cmd = $theConnectionIdToConnection[$id]['cmd'];
	$icafe_id = $cmd->theCafeId;
	$pc_name = null;

	$key = '';
	if ($cmd instanceof WsWebCmd)
		$key = sprintf('%d-web-%s', $icafe_id, $cmd->theStaffName);

	if ($cmd instanceof WsServerCmd)
		$key = sprintf('%d-server', $icafe_id);

	if ($cmd instanceof WsClientCmd)
		$key = sprintf('%d-client-%s', $icafe_id, $cmd->thePcName);

	if ($key == '')
		return;

	if (isset($theKeyToConnectionId[$key]))
	{
		if ($theKeyToConnectionId[$key] == $id)
		{
			unset($theKeyToConnectionId[$key]);
			wss_log($icafe_id, WSS_LOG_LEVEL_INFO, $id, null, 'unregister: ' . $key);
		}
	}

	$cmd->onUnregistered();

	unset($theConnectionIdToConnection[$id]);
	unset($theKeyToLastActiveTime[$key]);
}


function monitor_wss_timeout_but_not_onclose_connections()
{
	global $theKeyToConnectionId, $theConnectionIdToConnection, $theKeyToLastActiveTime, $theClientPingTimeout;
	// Workerman: 如果对端是由于断网或者断电等极端情况断开的连接，这时由于无法及时发送tcp的fin包给workerman，workerman就无法得知连接已经断开，
	// 也就无法及时触发onClose。这种情况需要通过应用层心跳来解决。
	// http://doc.workerman.net/worker/on-close.html
	try
	{
		foreach ($theKeyToConnectionId as $name => $id)
		{
			$id = $theKeyToConnectionId[$name];
			if (!isset($theConnectionIdToConnection[$id]))
				continue;

			if (!isset($theKeyToLastActiveTime[$name]))
				continue;

			$ts = $theKeyToLastActiveTime[$name] + 0;
			if (time() - $ts < $theClientPingTimeout)
				continue;

			wss_log(0, WSS_LOG_LEVEL_ERROR, $id, null, "close ping timeout connection, " . $name . ' => ' . (time() - $ts));
			$connection = $theConnectionIdToConnection[$id]['connection'];
			$connection->close();
		}
	}
	catch (Exception $e)
	{
	}
}

function wss_log($icafe_id, $level, $connection_id, $package, $message)
{
	if (!is_dir(WSS_LOG_PATH))
	{
		@mkdir(WSS_LOG_PATH);
		@chmod(WSS_LOG_PATH, 0777);
	}

	$log_file = WSS_LOG_PATH . '/access.log';
	$package_text = '';
	if (!is_null($package))
	{
		if (is_array($package))
			$package_text = json_encode($package);
		else
			$package_text = $package;
	}

	$line = sprintf("[%s][%s][cafeid - %d][connectid - %d]", date('H:i:s'), ($level == WSS_LOG_LEVEL_ERROR ? 'ERR' : 'INFO'), $icafe_id, $connection_id);
	if (strlen($package_text) > 0)
		$line .= (' pkg=' . $package_text);

	if (!is_null($message))
		$line .= (' msg=' . $message);

	$line .= "\r\n";

	file_put_contents($log_file, $line, FILE_APPEND);
	if ($icafe_id > 0)
		file_put_contents(WSS_LOG_PATH . '/' . strval($icafe_id) . '-' . date('md') . '.log', $line, FILE_APPEND);
}

# $data 是未经json化的数组
function wss_send_to_web($icafe_id, $data)
{
	global $theKeyToConnectionId, $theConnectionIdToConnection;

	$ret = false;
	try
	{
		$key = sprintf('%d-web-', $icafe_id);
		foreach ($theKeyToConnectionId as $name => $id)
		{
			if (strpos($name, $key) === false)
				continue;

			if (!isset($theKeyToConnectionId[$name]))
				continue;

			$id = $theKeyToConnectionId[$name];
			if (!isset($theConnectionIdToConnection[$id]))
				continue;

			$connection = $theConnectionIdToConnection[$id]['connection'];
			$connection->send(json_encode($data) . " \n");
			$ret = true;
		}
	}
	catch (Exception $e)
	{
		wss_log($icafe_id, WSS_LOG_LEVEL_ERROR, 0, $data, 'send_to_web, error=' . $e->getMessage());
	}

	return $ret;
}

function wss_send_to_server($icafe_id, $data)
{
	global $theKeyToConnectionId, $theConnectionIdToConnection;

	$ret = false;
	$key = sprintf('%d-server', $icafe_id);
	try
	{
		if (isset($theKeyToConnectionId[$key]))
		{
			$id = $theKeyToConnectionId[$key];
			if (!isset($theConnectionIdToConnection[$id]))
				throw new Exception('server connect not found');

			$connection = $theConnectionIdToConnection[$id]['connection'];
			$connection->send(str_replace("%", "%%", json_encode($data)) . " \n");
		}
		$ret = true;
	}
	catch (Exception $e)
	{
		wss_log($icafe_id, WSS_LOG_LEVEL_ERROR, 0, $data, 'send_to_server, error=' . $e->getMessage());
	}

	return $ret;
}

function wss_send_to_client($icafe_id, $pc_name, $data)
{
	global $theKeyToConnectionId, $theConnectionIdToConnection;

	$ret = false;
	$key = sprintf('%d-client-%s', $icafe_id, $pc_name);
	try
	{
		if (isset($theKeyToConnectionId[$key]))
		{
			$id = $theKeyToConnectionId[$key];
			if (!isset($theConnectionIdToConnection[$id]))
				throw new Exception('client connect not found');

			$connection = $theConnectionIdToConnection[$id]['connection'];
			$connection->send(str_replace("%", "%%", json_encode($data)) . " \n");
		}

		$ret = true;
	}
	catch (Exception $e)
	{
		wss_log($icafe_id, WSS_LOG_LEVEL_ERROR, 0, $data, 'send_to_client, pc=' . $pc_name . ', error=' . $e->getMessage());
	}
	return $ret;
}

// 转发WSS包到其它平台
function wss_forward($icafe_id, $origin_data)
{
	$data = $origin_data;
	$target = $data['target'];
	$ok = false;

	do
	{
		if ($target == 'web')
		{
			unset($data['target']);
			if (wss_send_to_web($icafe_id, $data) == false)
				break;
		}

		if ($target == 'client')
		{
			if (!isset($data['pc_name']))
			{
				$ok = true;
				break;
			}

			$pc_name = $data['pc_name'];
			unset($data['target']);
			unset($data['pc_name']);
			if (wss_send_to_client($icafe_id, $pc_name, $data) == false)
				break;
		}

		if ($target == 'server')
		{
			unset($data['target']);
			if (wss_send_to_server($icafe_id, $data) == false)
				break;
		}

		$ok = true;
	}
	while(false);

	return $ok;
}

function wss_is_client_connected($icafe_id, $pc_name)
{
	global $theKeyToConnectionId, $theConnectionIdToConnection, $theKeyToLastActiveTime, $theClientTimeout;

	$key = sprintf('%d-client-%s', $icafe_id, $pc_name);

	if (!isset($theKeyToConnectionId[$key]))
		return false;

	$id = $theKeyToConnectionId[$key];
	if (!isset($theConnectionIdToConnection[$id]))
		return false;

	if (!isset($theConnectionIdToConnection[$id]['connection']))
		return false;

	if (!isset($theKeyToLastActiveTime[$key]))
		return true;

	$ts = $theKeyToLastActiveTime[$key] + 0;
	if (time() - $ts >= $theClientTimeout)
		return false;

	return true;
}

// 向客户端发送状态包
// 客户端有任何变化时，要向客户端发状态包，例如：上线，登录成功，start session, checkout，余额增加，offer增加等
// 不在线的客户端没有member项，在线的客户端都有member项，根据member_group来判断prepaid/postpaid/member_normal/member_guest/offer
// pc_name == null 发送所有PC状态
function notify_client_status($icafe_id, $pc_name, $target, $using_billing, $timezone, $mysql)
{
	// client_data's field name is not good
	$web_data = array('action' => 'pc_status', 'pcs' => array(), 'pc_stats' => null);

	// 如果向WEB发通知，带上总体情况
	if ($target & NOTIFY_WEB)
	{
		// 附加badge信息
		// free/busy/member/off
		list($free, $busy, $off) = array(0,0,0);
		$member = $mysql->execScalar("SELECT COUNT(s.status_pc_name)
			FROM table_pc_status s, table_member m
			WHERE s.icafe_id = ? AND s.icafe_id = m.icafe_id AND s.status_member_id = m.member_id AND m.member_group_id >= 0", array($icafe_id), 0);

		$items = $mysql->query("SELECT pc_name, (SELECT COUNT(*) FROM table_pc_status WHERE icafe_id = table_pc.icafe_id AND status_pc_name = table_pc.pc_name) AS has_session FROM table_pc WHERE icafe_id = ?", array($icafe_id));
		foreach ($items as $item)
		{
			$bConnected = wss_is_client_connected($icafe_id, $item['pc_name']);
			$bLogined = intval($item['has_session']);

			if ($bLogined)
			{
				$busy += 1;
				continue;
			}

			if (!$bLogined && $bConnected)
			{
				$free += 1;
				continue;
			}

			$off += 1;
		}
		$web_data['pc_stats'] = array( 'free' => $free, 'busy' => $busy, 'member' => $member, 'off' => $off );
	}

	$wheres = array('p.icafe_id = :icafe_id');
	$params = array('icafe_id' => $icafe_id);

	if (!is_null($pc_name))
	{
		array_push($wheres, 'p.pc_name = :pc_name');
		$params['pc_name'] = $pc_name;
	}

	$wheres = join(' AND ', $wheres);

	$pcs = $mysql->query("SELECT p.*,
			CONVERT_TZ(s.status_connect_time, '+00:00', '$timezone') AS status_connect_time_local,
			CONVERT_TZ(s.status_disconnect_time, '+00:00', '$timezone') AS status_disconnect_time_local,
			TIMEDIFF(NOW(), s.status_connect_time) as status_connect_time_duration,
			TIMEDIFF(s.status_disconnect_time, NOW()) as status_connect_time_left,
			m.member_id, 
			m.member_account, 
			m.member_balance, 
			m.member_balance_bonus, 
			m.member_group_id,
			m.member_first_name,
			m.member_last_name,
			m.member_birthday,
			m.member_expire_time,
			m.member_email,
			m.member_phone,
			m.member_id_card,
			s.status_disconnect_time,
			s.status_pc_token,
			s.status_member_offer_id,
			s.status_connect_time,
			pg.pc_group_name,
			mg.member_group_name,
			mg.member_group_desc,
			o.product_name AS offer_in_using
		FROM table_pc p
		LEFT OUTER JOIN table_pc_group pg ON pg.pc_group_id = p.pc_group_id
		LEFT OUTER JOIN table_pc_status s ON p.icafe_id = s.icafe_id AND p.pc_name = s.status_pc_name
		LEFT OUTER JOIN table_member m ON m.member_id = s.status_member_id
		LEFT OUTER JOIN table_member_group mg ON mg.member_group_id = m.member_group_id
		LEFT OUTER JOIN table_member_offer mo ON mo.id = s.status_member_offer_id
		LEFT OUTER JOIN table_offer o ON o.product_id = mo.offer_id
		WHERE $wheres", $params);

	foreach ($pcs as $pc)
	{
		if ($target & NOTIFY_CLIENT)
		{
			$client_data = array(
				'action' => 'client_status',
				'target' => 'client',
				'pc_name' => $pc['pc_name'],
				'account' => '', // deprecated
				'name' => $pc['pc_name'], // deprecated
				'token' => '', // deprecated
				'lefttime' => 0, // deprecated
				'balance' => 0, // deprecated
			);

			// deprecated
			if (!is_null($pc['member_id']))
			{
				$login_account = $pc['member_account'];
				if ($pc['member_group_id'] == MEMBER_GROUP_PREPAID)
					$login_account = 'prepaid';
				if ($pc['member_group_id'] == MEMBER_GROUP_POSTPAID)
					$login_account = 'postpaid';
				if ($pc['member_group_id'] == MEMBER_GROUP_OFFER)
					$login_account = 'offer';

				$member_balance = $pc['member_balance'];
				if (!$using_billing)
					$member_balance = 0;

				$left_time = 3600 * 24 * 365 * 10;
				if ($pc['member_group_id'] != MEMBER_GROUP_POSTPAID)
				{
					$left_time = strtotime($pc['status_disconnect_time']) - time();
					if ($left_time < 0)
						$left_time = 0;
				}

				$client_data['account'] = $login_account;
				$client_data['token'] = $pc['status_pc_token'];
				$client_data['lefttime'] = $left_time;
				$client_data['balance'] = $member_balance;

				if ($pc['member_group_id'] >= MEMBER_GROUP_GUEST)
				{
					$client_data['member'] = array(
						'name' => $pc['member_account'],
						'group' => is_null($pc['member_group_name']) ? billing_get_special_member_group_name($pc['member_group_id']) : $pc['member_group_name'],
						'first_name' => $pc['member_first_name'],
						'last_name' => $pc['member_last_name'],
						'birthday' => is_null($pc['member_birthday']) ? '0000-00-00' : $pc['member_birthday'],
						'expire' => is_null($pc['member_expire_time']) ? '0000-00-00' : $pc['member_expire_time'],
						'email' => $pc['member_email'],
						'phone' => $pc['member_phone'],
						'idcard' => $pc['member_id_card'],
					);
				}
			}

			// new client status
			$pc['member_balance_realtime'] = $pc['member_balance'];
			$pc['member_balance_bonus_realtime'] = $pc['member_balance_bonus'];
			if($pc['member_id'] != null and $pc['status_member_offer_id'] == 0)
			{
				// if member_id = 0, status_connect_time = 0, billing_calculate_pc_spend_by_time execute time cost
				$price_row = billing_get_price_define_by_group($icafe_id, $pc['pc_group_id'], $pc['member_group_id'], $mysql);
				$cost = billing_calculate_pc_spend_by_time($price_row, strtotime($pc['status_connect_time']), time(), $timezone);

				if($pc['member_balance_bonus'] > $cost)
				{
					$pc['member_balance_bonus_realtime'] = $pc['member_balance_bonus'] - $cost;
					$pc['member_balance_realtime'] = $pc['member_balance'];
				}
				else
				{
					$pc['member_balance_bonus_realtime'] = 0;
					$pc['member_balance_realtime'] = $pc['member_balance'] - $cost + $pc['member_balance_bonus'];
				}
			}
			$client_data['client_status'] = $pc;

			wss_send_to_client($icafe_id, $pc['pc_name'], $client_data);
		}

		if ($target & NOTIFY_WEB)
		{
			$pc['pc_is_connected'] = 0;
			if (wss_is_client_connected($icafe_id, $pc['pc_name']))
				$pc['pc_is_connected'] = 1;

			array_push($web_data['pcs'], $pc);
		}
	}

	if (NOTIFY_WEB & $target)
		wss_send_to_web($icafe_id, $web_data);
}

// 向WEB通知icafecloud server的状态
function send_icafecloud_server_status_to_web($icafe_id)
{
	global $theKeyToConnectionId, $theConnectionIdToConnection, $theKeyToLastActiveTime, $theClientTimeout;

	$key = sprintf('%d-server', $icafe_id);

	if (isset($theKeyToConnectionId[$key]))
	{
		$id = $theKeyToConnectionId[$key];
		if (isset($theConnectionIdToConnection[$id]))
		{
			if (isset($theConnectionIdToConnection[$id]['connection']))
			{
				$ts = time();
				if (isset($theKeyToLastActiveTime[$key]))
					$ts = intval($theKeyToLastActiveTime[$key]);

				if (time() - $ts <= $theClientTimeout)
				{
					$ip = $theConnectionIdToConnection[$id]['connection']->getRemoteIp();
					$data = array( 'action' => 'icafecloud_server_status', 'status' => 1, 'ip' => $ip );
					wss_send_to_web($icafe_id, $data);
					return;
				}
			}
		}
	}

	$data = array( 'action' => 'icafecloud_server_status', 'status' => 0, 'ip' => '' );
	wss_send_to_web($icafe_id, $data);
}
?>
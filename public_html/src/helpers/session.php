<?php
class SessionHelper extends ChibiHelper {
	private static $stopped = false;
	private static $oldData = [];
	private static $oldId = '';

	public function close() {
		if (!self::$stopped) {
			self::$stopped = true;
			self::$oldData = $_SESSION;
			self::$oldId = session_id();
			session_write_close();
		}
	}

	public function restore() {
		if (self::$stopped) {
			ini_set('session.use_only_cookies', false);
			ini_set('session.use_cookies', false);
			ini_set('session.use_trans_sid', false);
			ini_set('session.cache_limiter', null);
			session_id(self::$oldId);
			session_start();
			$_SESSION = self::$oldData;
			self::$stopped = false;
		}
	}
}
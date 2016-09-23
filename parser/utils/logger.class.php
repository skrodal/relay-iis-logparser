<?php

	namespace Parser\Utils;

	class Logger {
		private static $log = [];
		private static $line = 1;
		private static $verbose = true;

		public static function init($verbose) {
			self::$verbose = $verbose;
		}

		public static function log($msg) {
			$timestamp       = date('Y-m-d H:i:s');
			$key             = "[$timestamp] " . self::$line;
			self::$log[$key] = $msg;
			if(self::$verbose) {
				echo "$key :: $msg" . PHP_EOL;
			}
			self::$line++;
		}

		public static function done($logfile = false) {
			if(!$logfile) {
				return;
			}
			file_put_contents($logfile, json_encode(self::$log, JSON_PRETTY_PRINT));
		}
	}
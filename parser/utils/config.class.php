<?php
	namespace Parser\Utils;

	class Config {
		private static $config = [];

		public static function init($config_path) {
			self::$config = self::loadConfig($config_path);
		}

		private static function loadConfig($config_path) {
			if(!file_exists($config_path)) {
				Logger::log("EXIT! Log file not found at " . $config_path);
				exit();
			}

			$config   = json_decode(file_get_contents($config_path), true);
			$settings = ['memory_limit'       => ini_get("memory_limit"),
			             'iis_log_path'       => $config['iis_log_path'],
			             'min_hit_diff_sec'   => $config['min_hit_diff_sec'],
			             'presentation_depth' => $config['presentation_depth']];

			Logger::log('Loaded config with the following settings: ' . json_encode($settings, JSON_PRETTY_PRINT));

			return $config;
		}

		public static function get($key) {
			if(!isset(self::$config[$key])) {
				Logger::log('Requested config item ' . $key . ' was not found!');
				exit();
			}

			return isset(self::$config[$key]) ? self::$config[$key] : false;
		}
	}
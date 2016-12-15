<?php

	namespace parser\db;

	use Parser\Utils\Config;
	use Parser\Utils\Logger;
	use PDO;
	use PDOException;

	ini_set('mssql.charset', 'UTF-8');

	class MSSQL {
		private static $mssql;

		public static function init() {
			// Path to the shared Relay config file
			$relayConfig = json_decode(file_get_contents(Config::get('path_to_relay_config')), true);
			self::$mssql = self::getConnection($relayConfig);
		}

		private static function getConnection($config) {
			// Read only access
			$host = $config['db_host'];
			$db   = $config['db'];
			$user = $config['user'];
			$pass = $config['pass'];
			try {
				$connection = new PDO("dblib:host=$host;dbname=$db;charset=UTF8", $user, $pass);
				$connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

				return $connection;
			} catch(PDOException $e) {
				Logger::log('Utilgjengelig - Relay databasekobling feilet: ' . $e->getMessage());
				exit();
			}
		}

		/**
		 *
		 * @param $path
		 *
		 * @return array
		 */
		public static function getIdsForPath($path) {
			// Get presId AND userId for a presentation path (use for HITS)
			return self::query("
			    SELECT DISTINCT presUser_userId, presId
			    FROM tblPresentation
			    INNER JOIN tblFile
			    ON tblFile.filePresentation_presId = tblPresentation.presId
			    WHERE tblFile.filePath LIKE '%$path%'
			 ");
		}

		private static function query($sql) {
			try {
				$response = self::$mssql->query($sql, PDO::FETCH_ASSOC);
				return !empty($response) ? $response[0] : [];
			} catch(PDOException $e) {
				Response::error(500, 'Samtale med database feilet (SQL): ' . $e->getMessage());
			}
		}
	}
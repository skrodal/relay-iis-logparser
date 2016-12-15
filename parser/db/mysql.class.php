<?php
	namespace Parser\DB;

	use Parser\Utils\Config;
	use Parser\Utils\Logger;

	class MySQL {
		private static $mysqli;

		public static function escape($string) {
			return self::$mysqli->real_escape_string($string);
		}

		public static function closeConnection() {
			self::$mysqli->close();
		}


		public static function init() {
			self::$mysqli = self::getConnection();
		}

		private static function getConnection() {
			$conn = new \mysqli(Config::get('db_host'), Config::get('db_user'), Config::get('db_pass'), Config::get('db_name'));
			if($conn->connect_errno) {
				Logger::log("EXIT: Failed to connect to DB: (" . $conn->connect_errno . ") " . $conn->connect_error);
				exit();
			}

			return $conn;
		}

		public static function query($sql) {
			$response = self::$mysqli->query($sql);
			if(!$response) {
				Logger::log("EXIT: Invalid query: " . self::$mysqli->error);
				exit();
			}
			return $response;
		}

		public static function getPathsWithNoUserId(){
			$tblHits  = Config::get('db_table_hits');
			$result = self::query(
				"SELECT * FROM $tblHits " .
				"WHERE userid = NULL "
			);
			$response = [];
			foreach($result->fetch_assoc() as $row) {
				$response[] = $row;
			}
			return $response;
		}

		public static function getFirstRecordTimestamp(){
			$tblInfo  = Config::get('db_table_info');
			$response = self::query("SELECT conf_val AS first_record_timestamp FROM $tblInfo WHERE conf_key = 'first_record_timestamp'");
			$timestamp = $response->fetch_assoc()['first_record_timestamp'];
			$response->free();
			return $timestamp;
		}

		/**
		 * Update record if passed-in timestamp is less than what is already in the table
		 * @param $first_record_timestamp
		 */
		public static function updateFirstRecordTimestamp($first_record_timestamp){
			$tblInfo  = Config::get('db_table_info');
			self::query(
				"UPDATE $tblInfo " .
				"SET conf_val = '$first_record_timestamp' " .
				"WHERE conf_key = 'first_record_timestamp' " .
				"AND conf_val > $first_record_timestamp");
		}

		public static function getLastRecordTimestamp(){
			$tblInfo  = Config::get('db_table_info');
			$response = self::query("SELECT conf_val AS last_record_timestamp FROM $tblInfo WHERE conf_key = 'last_record_timestamp'");
			$timestamp = $response->fetch_assoc()['last_record_timestamp'];
			$response->free();
			return $timestamp;
		}

		/**
		 * Update record if passed-in timestamp is greater than what is already in the table
		 * @param $last_record_timestamp
		 */
		public static function updateLastRecordTimestamp($last_record_timestamp){
			$tblInfo  = Config::get('db_table_info');
			self::query(
				"UPDATE $tblInfo " .
				"SET conf_val = '$last_record_timestamp' " .
				"WHERE conf_key = 'last_record_timestamp' " .
				"AND conf_val < $last_record_timestamp");
		}

		public static function getLastLogfileRead(){
			$tblInfo  = Config::get('db_table_info');
			$response = self::query("SELECT conf_val AS last_logfile_read FROM $tblInfo WHERE conf_key = 'last_logfile_read'");
			// Get the value (which might be empty)
			$logfile = $response->fetch_assoc()['last_logfile_read'];
			$response->free();
			return $logfile;
		}

		public static function updateLastLogfileRead($last_logfile_read){
			$tblInfo  = Config::get('db_table_info');
			self::query(
				"UPDATE $tblInfo " .
				"SET conf_val = '$last_logfile_read' " .
				"WHERE conf_key = 'last_logfile_read'");
		}

		/**
		 * @param $path
		 * @param $hits
		 * @param $timestamp
		 * @param $username
		 */
		public static function updateHitsTable($path, $hits, $timestamp, $username){
			// If path already exists, only add hits if found timestamp is newer than latest recorded
			// (we do not want to increment hits each time we run the script)
			$tblHits  = Config::get('db_table_hits');
			self::query(
				"INSERT INTO $tblHits (path, hits, timestamp_latest, username) " .
				"VALUES ('$path', $hits, $timestamp, '$username') " .
				"ON DUPLICATE KEY UPDATE " .
				"hits = IF (timestamp_latest < $timestamp, hits + $hits, hits), " .
				"timestamp_latest = IF (timestamp_latest < $timestamp, $timestamp, timestamp_latest)"
			);
		}

		/**
		 * Add presId and userId as a separate operation (pulled from Relay DB).
		 *
		 * @param $path
		 * @param $userId
		 * @param $presId
		 */
		public static function updateHitsTableWithIDs($path, $userId, $presId){
			$tblHits  = Config::get('db_table_hits');
			self::query(
				"UPDATE $tblHits" .
				"SET userId = $userId, presId = $presId " .
				"WHERE path = '$path'"
			);
		}

		public static function updateDailyTable($dailyArr){
			$tblDaily  = Config::get('db_table_daily');
			foreach($dailyArr as $date => $hits){
				self::query(
					"INSERT INTO $tblDaily (log_date, hits) " .
					"VALUES ('$date', $hits) " .
					"ON DUPLICATE KEY UPDATE " .
					"hits = hits + $hits"
				);
			}
		}


		/**
		 * Drop and rebuild tables.
		 */
		public static function RESET() {
			self::init();
			$tblHits = Config::get('db_table_hits');
			$tblInfo = Config::get('db_table_info');
			$tblDaily = Config::get('db_table_daily');

			self::query("DROP TABLE IF EXISTS $tblHits;");
			self::query("DROP TABLE IF EXISTS $tblInfo;");
			self::query("DROP TABLE IF EXISTS $tblDaily;");

			$hitsTableQuery = "CREATE TABLE $tblHits (" .
				"path text NOT NULL," .
				"hits int(11) NOT NULL DEFAULT 0," .
				"timestamp_latest int(11) NOT NULL DEFAULT 0," .
				"username varchar(50) NOT NULL," .
				"presId int(11) DEFAULT NULL," .
				"userId int(11) DEFAULT NULL," .
				"UNIQUE KEY path (path(170))" .
				") ENGINE=InnoDB DEFAULT CHARSET=utf8;";

			$infoTableQuery = "CREATE TABLE $tblInfo (" .
				"conf_key varchar(50) NOT NULL," .
				"conf_val varchar(50) NOT NULL," .
				"PRIMARY KEY (conf_key)" .
				") ENGINE=InnoDB DEFAULT CHARSET=latin1;";

			// Default first recorded log line defaulted to right now
			// Last recorded log line defaulted to beginning of time (and then some)
			// Both will be updated on first proper run
			$timestamp_now = time();
			$infoTableQueryInit = "INSERT INTO $tblInfo (conf_key, conf_val)" .
				"VALUES ('last_logfile_read', ''), ('first_record_timestamp', $timestamp_now), ('last_record_timestamp', 0)";

			$hitsDailyTableQuery = "CREATE TABLE $tblDaily (" .
				"log_date date NOT NULL," .
				"hits int(11) NOT NULL," .
				"PRIMARY KEY (log_date)" .
				") ENGINE=InnoDB DEFAULT CHARSET=latin1;";

			self::query($hitsTableQuery);
			self::query($infoTableQuery);
			self::query($infoTableQueryInit);
			self::query($hitsDailyTableQuery);
		}
	}
<?php

	namespace Parser;

	use Parser\DB\SQL;
	use Parser\Utils\Config;
	use Parser\Utils\Logger;


	class Parser {
		const DATE_POS = 0;
		const TIME_POS = 1;
		const METHOD = 3;
		const URI_POS = 4;
		const HTTP_CODE_POS = 11;

		/**
		 * Array of paths to all logfiles in the IIS folder.
		 * @return array
		 */
		public static function getLogfiles() {
			$logfiles = glob(Config::get('iis_log_path') . "*.log");
			Logger::log("Info: Found a total of " . count($logfiles) . " logfiles.");
			return $logfiles;
		}

		/**
		 * Expected IIS #Fields (and order) from a logfile:
		 *      [0] date
		 *      [1] time
		 *      [2] s-ip
		 *      [3] cs-method
		 *      [4] cs-uri-stem
		 *      [5] cs-uri-query
		 *      [6] s-port
		 *      [7] cs-username
		 *      [8] c-ip
		 *      [9] cs(User-Agent)
		 *     [10] cs(Referer)
		 *     [11] sc-status
		 *     [12] sc-substatus
		 *     [13] sc-win32-status
		 *     [14] time-taken
		 *
		 *  IIS #Fields we want to keep:
		 *      [0] time        (Merged with date and transformed to UNIX timestamp)
		 *      [1] cs-uri-stem (Transformed from full path to 'ansatt/username/year/date/####/')
		 *      [2] c-ip
		 *
		 * @param $file_name
		 *
		 * @return array|bool
		 */
		public static function getUniquePresentationsFromLogFile($file_name) {
			$response = [];
			// One dimension for each table we might want to update
			$response['hits']                           = [];
			$response['daily']                          = [];
			$response['info']                           = [];
			$response['info']['first_record_timestamp'] = time();   // Now
			$response['info']['last_record_timestamp']  = 0;
			// All lines from the logfile pertaining to Relay files that we will transform and return
			$relay_log_lines = [];
			// An array with all lines from the log
			$log_lines_raw = self::readIISLogFile($file_name);
			// Will contain the fields (headers) from the log
			$log_fields_raw = [];
			// Get the first log-line containing the field headers and store as array
			foreach($log_lines_raw as $line_raw) {
				if(strpos($line_raw, '#Fields:') !== false) {
					$log_fields_raw = explode(" ", str_replace("#Fields: ", "", $line_raw));
					break;
				}
			}
			// Array of field names we want to keep for further processing
			$fields_to_keep = ['time' => '', 'cs-uri-stem' => '', 'c-ip' => ''];
			// Loop each and every logline in file
			foreach($log_lines_raw as $line_raw) {
				// Only interested in log-lines with hits on MP3 or MP4
				if((strpos($line_raw, '.mp3') !== false) || (strpos($line_raw, '.mp4') !== false)) {
					// Transform the line into an array
					$line_arr = explode(' ', $line_raw);
					// We only want the HTTP status code to be in the 200s AND HTTP Method must be GET
					if( (200 <= $line_arr[self::HTTP_CODE_POS]) && ($line_arr[self::HTTP_CODE_POS] < 300) && (strcasecmp($line_arr[self::METHOD], "GET") == 0) ) {
						// Transform date + time to timestamp and store in the time-field
						$line_arr[self::TIME_POS] = strtotime($line_arr[self::DATE_POS] . ' ' . $line_arr[self::TIME_POS]);
						// Track the earliest recorded timestamp
						if($response['info']['first_record_timestamp'] > $line_arr[self::TIME_POS]) {
							$response['info']['first_record_timestamp'] = $line_arr[self::TIME_POS];
						}
						// ...and the last
						if($response['info']['last_record_timestamp'] < $line_arr[self::TIME_POS]) {
							$response['info']['last_record_timestamp'] = $line_arr[self::TIME_POS];
						}
						// Transform presentation path to base path (we don't want hits on individual files)
						// Some files are in base folder (Conf::PRESENTATION_DEPTH levels deep), while others may be a few levels deeper.
						// Find offset depth of the media file:
						$depth_offset = substr_count(dirname($line_arr[self::URI_POS]), '/') - Config::get('presentation_depth');
						// Then use offset to get to base folder for presentation
						do {
							$line_arr[self::URI_POS] = dirname($line_arr[self::URI_POS]);
							$depth_offset--;
						} while($depth_offset >= 0);
						// Make path structure exactly the same as used by relay-harvester (to simplify integrations)
						$line_arr[self::URI_POS] = str_replace('/relay/', '', $line_arr[self::URI_POS] . DIRECTORY_SEPARATOR);
						// 1. Array_Combine keys (fields) with the new values into a new array
						// 2. Remove any unwanted fields from our new array (intersect).
						$relay_log_lines[] = array_intersect_key(array_combine($log_fields_raw, $line_arr), $fields_to_keep);
					}
				}
			}

			# Note: the above has given us an array with lines of three fields: time (timestamp), cs-uri-stem (presentation base path) and c-ip

			// Now we need to post-process this logfile and filter out unique IPs and limit hits on time
			// (same IP logged as accessing same file several times a minute should not count)
			// We also want to make sure that we don't count old loglines - hence pull the latest timestamp from our info table:
			$last_recorded_timestamp = SQL::getLastRecordTimestamp();
			//
			foreach($relay_log_lines as $key => $fields) {
				$TIMESTAMP = $fields['time'];
				$PRES_PATH = $fields['cs-uri-stem'];
				$IP = $fields['c-ip'];
				// Only do something if this log line is newer than the latest recorded entry in our DB
				if($TIMESTAMP > $last_recorded_timestamp) {
					$PRES_PATH = SQL::escape($PRES_PATH);
					// Build data structure, where path is the key (also unique in DB):
					//	"__PATH__": {
					//	    "__IP__": {
					//		    "hits": 2,
					//          "last_access": 1445106359
					//      },
					//      "__IP__": {
					//			...
					//      }
					//  },

					// Initialise structures
					$date = date("Y-m-d", $TIMESTAMP);
					if(!isset($response['daily'][$date])) {
						$response['daily'][$date] = 0;
					}
					if(!isset($response['hits'][$PRES_PATH])) {
						$response['hits'][$PRES_PATH] = [];
					}
					if(!isset($response['hits'][$PRES_PATH][$IP])) {
						$response['hits'][$PRES_PATH][$IP] = [];
					}
					if(!isset($response['hits'][$PRES_PATH][$IP]['hits'])) {
						$response['hits'][$PRES_PATH][$IP]['hits'] = 1;
						$response['daily'][$date]++;
					}
					if(!isset($response['hits'][$PRES_PATH][$IP]['last_access'])) {
						$response['hits'][$PRES_PATH][$IP]['last_access'] = $TIMESTAMP;
					}
					// ADD log-line, IF
					// - Diff between current and previous recorded timestamp hit for IP on URI is greater than TIMER
					if($TIMESTAMP - $response['hits'][$PRES_PATH][$IP]['last_access'] >= Config::get('min_hit_diff_sec')) {
						$response['hits'][$PRES_PATH][$IP]['last_access'] = $TIMESTAMP;
						$response['hits'][$PRES_PATH][$IP]['hits']++;
						// Add to daily counter as well
						$response['daily'][$date]++;
					}
				}
			}
			//
			return $response;
		}

		/**
		 * Read a logfile and return array with lines.
		 *
		 * @param $file_path
		 *
		 * @return array|bool
		 */
		private static function readIISLogFile($file_path) {
			if(file_exists($file_path)) {
				return explode(PHP_EOL, file_get_contents($file_path));
			}
			Logger::log('EXIT: Log file ' . $file_path . ' could not be found!');
			exit();
		}
	}
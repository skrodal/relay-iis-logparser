<?php
	namespace Parser;
	use Parser\DB\SQL;
	use Parser\Utils\Config;
	use Parser\Utils\Logger;


	class Process {
		private $tblHits, $tblInfo, $tblDaily;

		function __construct() {
			$this->tblInfo  = Config::get('db_table_info');
			$this->tblHits  = Config::get('db_table_hits');
			$this->tblDaily = Config::get('db_table_daily');
		}

		/**
		 * Kickoff
		 */
		public function run() {
			// DB connect
			SQL::init();
			// Daily/update/reset job
			$this->parse();
			//
			$this->done();
		}

		private function parse() {
			// List of all logfiles in folder (ordered by name, which is what we want)
			$logfiles = Parser::getLogfiles();
			// Remove from array those logfiles that have already been processed
			$logfiles = $this->getLogFilesLatest($logfiles);
			$filenum = 0;
			$total_hits_this_run = 0;
			// Get latest records from our info table
			$first_record_timestamp = time();
			$last_record_timestamp = 0;
			// Process each logfile in turn
			foreach($logfiles as $file_name) {
				$filenum++;
				// Transform the logfile into an arrayobject serving our info needs for:
				// {
				//   hits : {paths => IPs => hits | timestamp},
				//   daily: {date => hits},
				//   info:  {last|first_record_timestamp => ..}
				// }
				$presentations = Parser::getUniquePresentationsFromLogFile($file_name);
				// Loop every presentation path we found in this logfile
				foreach($presentations['hits'] as $path => $ips) {
					// Hits and latest found timestamp for this presentation path
					$hits      = 0;
					$timestamp = 0;
					// Get the username from the path (sometimes '@' is missing, so lets normalise on that)
					$username = explode('/', $path);
					$username = $username[Config::get('username_depth')-1];
					$username = str_replace("@","",$username);
					// Loop logged IPs and their info
					foreach($ips as $ip => $hitinfo) {
						// Accumulate all hits
						$hits += $hitinfo['hits'];
						$total_hits_this_run += $hitinfo['hits'];
						// Keep track of the most recent hit (this will be used to determine if data is newer than what exist in DB)
						$timestamp = ($hitinfo['last_access'] > $timestamp) ? $hitinfo['last_access'] : $timestamp;
					}
					// Insert/update record for this presentation (will exit if query fails)
					SQL::updateHitsTable($path, $hits, $timestamp, $username);
				}
				// If earliest timestamp logged in this file is older (less) than what we have on record
				if($presentations['info']['first_record_timestamp'] < $first_record_timestamp){
					$first_record_timestamp = $presentations['info']['first_record_timestamp'];
				}
				// If most recent timestamp logged in this file is newer (greater) than what we have on record
				if($presentations['info']['last_record_timestamp'] > $last_record_timestamp){
					$last_record_timestamp = $presentations['info']['last_record_timestamp'];
				}
				// Insert/update hits for this logfile
				SQL::updateDailyTable($presentations['daily']);
				// Done with this file
				Logger::log("Process: Done processing logfile " . $filenum . "/" . count($logfiles) . " (" . basename($file_name) . ")");
			}

			// DONE with all logfiles, now do various updates
			$last_logfile_read = basename(end($logfiles));
			// Store the last processed file to our info table so we know where to begin next time.
			SQL::updateLastLogfileRead($last_logfile_read);
			// And update our timestamps
			SQL::updateFirstRecordTimestamp($first_record_timestamp);
			SQL::updateLastRecordTimestamp($last_record_timestamp);
			//
			Logger::log("Summary: Done processing all IIS log files.");
			Logger::log("Summary: Logged $total_hits_this_run unique hits in this run.");
			Logger::log("Summary: tblInfo has been updated with the last read logfile ($last_logfile_read) and first/last timestamps.");
		}

		private function getLogFilesLatest($logfiles) {
			// Get the value (which might be empty)
			if(empty($last_logfile_read = SQL::getLastLogfileRead())){
				Logger::log("Info: No logfiles have previously been read. Starting fresh.");
				return $logfiles;
			}
			Logger::log("Info: Last logfile read was $last_logfile_read.");
			// Now drop all files we have read
			foreach($logfiles as $index => $logfile) {
				// We have a match!
				if(strcmp($last_logfile_read, basename($logfile)) == 0) {
					Logger::log("Info: " . count($logfiles) . " logfile(s) will be processed in this run.");
					return $logfiles;
				}
				// Delete logfile from list
				unset($logfiles[$index]);
			}
		}

		private function done() {
			SQL::closeConnection();
		}
	}
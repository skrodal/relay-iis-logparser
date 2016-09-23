<?php
	// Defaults
	header('Content-Type: application/json');
	// Set to the same timezone as what is used by IIS logs
	date_default_timezone_set('Europe/Oslo');
	ini_set("memory_limit", "256M");  // `bytes exhausted` insurance...
	require_once ('loader.php');
	use Parser\DB\SQL;
	use Parser\Process;
	use Parser\Utils\Config;
	use Parser\Utils\Logger;
	//
	$VERBOSE_LOG     = true;
	$CONFIG_PATH     = __DIR__ . '/etc/config.js';
	$OUTPUT_LOG_PATH = __DIR__ . '/output/log/';
	//
	if(defined('STDIN') && isset($argv[1])) {
		$argv[1] = strtoupper($argv[1]);
		Logger::init($VERBOSE_LOG);
		Logger::log("SCREENCAST IIS LOGPARSER: Starting a new run of type [$argv[1]] for " . gethostname());

		switch($argv[1]) {
			case "UPDATE":
				Config::init($CONFIG_PATH);
				$parser = new Process($argv[1]);
				$parser->run();
				break;
			case "RESET":
				confirmReset();
				Config::init($CONFIG_PATH);
				Logger::log("Dropping and rebuilding DB tables!");
				SQL::RESET();
				Logger::log("Done! The service has been cleaned.");
				break;
			default:
				Logger::log("EXIT! Parameter [$argv[1]] is unknown to me - I expected 'UPDATE' or 'RESET'.");
				break;
		}
		Logger::log("End of [$argv[1]].");
		Logger::done($OUTPUT_LOG_PATH . date('Y-m-d H:i:s') . '.json');
		exit();
	}

	Logger::log("EXIT! Missing required parameter (hint: expected 'full' or 'latest')");

	function confirmReset(){
		$br = PHP_EOL.PHP_EOL;
		echo $br . "### This will DROP and rebuild all existing tables and reset EVERYTHING ###". $br ."Type 'yes' to continue: ";
		$handle = fopen ("php://stdin","r");
		if(trim(fgets($handle)) !== 'yes'){
			exit(PHP_EOL."~~~ Mission was aborted ~~~".$br);
		}
		fclose($handle);
	}
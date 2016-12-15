_This service is tailor-made for UNINETT AS for a specific use-case. Its re-usability may, as such, be limited._

## About

Collects unique hits on Relay presentations. 

The service parses IIS logfiles available on a Relay Screencast (Windows)Server, filters and stores data from unique (on IP/time) (GET)requests on (mp4/mp3) mediafiles. 
A Relay presentation may offer 4 different formats; this script combines hits on all of these. 

The service stores (in MySQL tables) statistical data to cover our **basic** needs (how many hits per presentation and how many hits overall per day). 
While it would be very straightforward to store more details from these logs (e.g. "which IP visited which presentation at what time during the day"), 
our use-case does not call for it. 

The intention is that the script be run automatically (e.g. cronjob) once every 24 hours. A different API will make use of the data this service harvests.

> 15.12.2016: See branch for a start on pulling in presId and userId from Relay's own DB. 
This info would be useful in the presHits table for APIs trying to match presentation paths with a user (since usernames may change (e.g. fusjonering) 
while IDs are constant). Did not have time to finish off this feature (testing and implementing in APIs, Min Relay specifically)

## Configure

1. Fill in settings in `etc/config.json` and point `$CONFIG_PATH` in `index.php` to this config file.
2. Point $OUTPUT_LOG_PATH to the folder where you would like to store logs for each run.

Config fields explained:

- `iis_log_path` : points to the folder where all IIS logs are kept (typically a samba mount to the Windows Server's IIS log folder).    
- `min_hit_diff_sec` : do not record more than one hit for a presentation for a unique IP within this timeframe (e.g. 7200 for 2 hours).
- `username_depth`: folder depth to username folder (pends on publishing structure of Relay content) - start counting from 1.
- `presentation_depth`: folder depth to presentation base path (pends on publishing structure of Relay content) - start counting from 1:

GET requests of interest in the IIS log look something like this:

    GET */relay/ansatt/username.no/year/date/random_num*/presentation_name_and_quality/media/video.mp4

We're interested in the base path for the presentation, which is up to and including the *random_num* folder. 
In the current Relay publishing setup (configured in Relay Server), the publishing depth is 6, and a number of 
media formats for this presentation will live in subfolders of this basepath.

## Tables

The service requires three tables:

- `presentations_hits`
- `presentations_hits_daily`
- `presentations_hits_info`

See `mysql.class.php` function `RESET` for the structure of these tables. 

### Table `presentations_hits`

Stores fields `path` (unique), `hits` (accumulated), `timestamp_latest` (the last recorded hit on this presentation) and `username` (without the ampersand).

The `path` is the basepath for a presentation, and since the publishing structure of our setup includes  
the Relay username in the path, we can do queries such as
 
> SELECT * FROM presentations_hits WHERE path LIKE '%uninett.no%' AND hits > 6 AND timestamp_latest >=unix_timestamp('2016-08-01');

...to get all presentations from uninett users with more than 6 hits since August 1 2016.

Our usernames in published paths have changed through service upgrades from username@org.no to usernameorg.no (lost the `@`). 
A query that combines both usernames may look like:  

> SELECT * FROM presentations_hits WHERE path LIKE '%simon%uninett.no%' AND hits > 6 AND timestamp_latest >=unix_timestamp('2016-08-01');

List all presentations that were viewed the last 7 days:
> SELECT * FROM presentations_hits WHERE timestamp_latest > unix_timestamp(date(now()) - INTERVAL 7 DAY);

...or just the count from the above:

> SELECT SUM(hits) FROM presentations_hits WHERE timestamp_latest > unix_timestamp(date(now()) - INTERVAL 7 DAY);

Sum of hits on presentations belonging to my account(s) (with the % wildchard, the query checks old and new username)

> SELECT SUM(hits) AS totalHits FROM presentations_hits WHERE path LIKE "%simon%uninett.no%";

Which presentations were viewed on a particular date:

> SELECT * FROM presentations_hits WHERE FROM_UNIXTIME(timestamp_latest, '%Y-%m-%d') = '2016-09-01';
    
### Table `presentations_hits_daily`

Contains two columns, `log_date` (unique) and hits and keeps track of the number of unique hits for any given date, no more no less.
 
A sum of hits from the `presentations_hits_daily` and `presentations_hits_daily` tables should be identical. 
However, the latter table allows us better insights into daily trends.

How many overall unique hits on August 1 2016:

> SELECT hits FROM presentations_hits_daily WHERE log_date = date('2016-08-01');

How many overall unique hits the last week:

> SELECT SUM(hits) FROM presentations_hits_daily WHERE log_date > date(now()) - INTERVAL 7 DAY;
 
### Table `presentations_hits_info`

The info table is used for tracking simple data about the logs and assist the parser:

`first_record_timestamp` timestamp for the first logline read - useful reminder when presenting stats; Although Relay has been offered since 2011, 
we only have IIS traffic logs starting from `first_record_timestamp`.
 
`last_logfile_read` and `last_record_timestamp` are useful for the parser on `update` jobs (don't have to scan GB of already processed data). 

## Run

On the very first run ever, run: 

> php index.php RESET 

This will build the needed tables. Warning: if you already have content in the tables; this will reset everything and start *completely* fresh (drops and rebuilds tables):
Designed to run as a nightly CRON-job, command to do a run:

> php index.php UPDATE 

- On first run, the above will scan all logfiles available (can be time-consuming). 
- On subsequent runs, the above will pick up on the last logfile read at the last timestamp read.  

### CRON-job

Example (run at 05:00 every morning): 

```sh
# Relay IIS Harvest job definition:

#
# 	.---------------- minute (0 - 59)
# 	|  	.------------- hour (0 - 23)
# 	|  	|  			.---------- day of month (1 - 31)
# 	|  	|  			|  	.------- month (1 - 12) OR jan,feb,mar,apr ...
# 	|  	|  			|  	|  	.---- day of week (0 - 6) (Sunday=0 or 7) OR sun,mon,tue,wed,thu,fri,sat
# 	|  	|  			|  	|  	|
# 	*  	*  			*  	*  	* 	command to be executed
#
    0   5           *   *   *   php /path/to/relay-iis-logparser/index.php update | mail -s "Relay IIS Nightly Harvest Report" "email_user@uninett.no"
```

### Output

- Parselog is saved to whereever `$OUTPUT_LOG_PATH` points to in `index.php` (if no logs are wanted, set `$OUTPUT_LOG_PATH = false;`)

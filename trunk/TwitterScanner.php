<?php
/*
Twitter Scanner Version 1.0

*/

class TwitterScanner {
  private $tweetTable;
  private $userTable;
  private $tweetsURL;
  
  /**
  * Instantiates a new TwitterScanner object. Also connects to the MySQL database used to 
  * store Tweets in, and sets a few default variables.
  * @param string $user The username for the MySQL Database
  * @param string $pass The password for the MySQL Database
  * @param string $db The database that the table(s) are stored in
  * @param string $host The host of the MySQL database. Defaults to localhost
  */
  public function __construct($user, $pass, $db, $host = "localhost") {
    // Attempt to connect to the database. Raise an error if it fails
    if (!mysql_connect($host, $user, $pass))
      throw new Exception("Unable to connect to database");
    
    // Connection established successfully. Lets select our database for it.
    mysql_select_db($db);
    
    // Define our tweets URL. PLEASE NOTE--- IF TWITTER CHANGES THE FORMAT OF THEIR URLS YOU MUST UPDATE THIS VALUE
    // {USERNAME} is the location of the username we are scanning
    // {PAGE} is the ID for the page
    $this->tweetsURL = "http://twitter.com/{USERNAME}?page={PAGE}";
  }
  
  /**
  * Define the tables we will be storing the Tweets in. If $userTable is left as null then it will
  * be assumed that the Tweet Usernames are stored in a de-normalized fashion
  * @param string $tweetTable The table that the tweets are stored in
  * @param string $userTable The table to store usernames in a normalized setup
  */
  public function SetTables($tweetTable = 'tweets', $userTable = null) {
    $this->tweetTable = $tweetTable;
    $this->userTable = $userTable;
  }
  
  /**
  * Scans the Twitter website. It sets up a loop to read Tweets from multiple pages. It stores the
  * page HTML in $this->page, and then calls a few private methods to scan the page with regular expresions
  * to pull out the information we need. Stores obtained data in a local array. Scans either all tweets
  * or up to the $cutoff, which must be a valid ISO 8601 date. After the scans are complete we add the
  * tweets to our database by calling StoreTweets($tweets)
  * @param string $username The username that we are scanning.
  * @param date $cutoff The date we should cutoff at.
  * @return The number of tweets stored
  */
  
  public function Scan($username, $cutoff = null) {
    // Ensure our tweet table has been set before allowing a scan
    if (empty($this->tweetTable))
      throw new Exception("No table to record Tweets in defined");
    
    // Setup some variables
    $tweets = array();
    $tweetsURL = str_replace("{USERNAME}", $username, $this->tweetsURL); // Take our tweetsURL and add our username
    
    // Setting a hard cap of 10000 pages (2000000 tweets); If twitter changes their code in a fashion
    // that causes the script to think there is always more tweets we don't want this to run
    // forever. Though you can adjust it to!
    for ($page = 1; $page < 10000; $page++) {
      $done = false; // If we set this anywhere then at the end the for is broken
      
      // Initialize our curl processor. 
      $ch = curl_init(str_replace("{PAGE}", $page, $tweetsURL));
      
      // Set curl options
      curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
      
      // Fetch our page
      $this->page = curl_exec($ch);
      
      // If this is the first page than confirm it's a valid user; If it doesn't this method
      // will throw an exception
      //$this->CheckUserExists();
      
      // Run our scans
      $ts = $this->ReadTweetStatuses();
      $t = $this->ReadTweets();
      $d = $this->ReadDates();
      $s = $this->ReadSources();
      
      // Confirm that the formatting on twitter is the same by ensuring all our info arrays
      // have the same number of elements and have more than 0 elements
      if (!count($ts) || (count($ts) != count($d)) || (count($ts) != count($s))) {        
        throw new Exception("Twitter formatting unexpected; please update scanner");
      }
      
      // Format them into our tweets array
      foreach ($ts as $key=>$nothing) {
        // If the cutoff was set and the tweet is older (less in time() form) than
        // we have reached all the tweets we want. Set our $done and break the foreach
        if (!is_null($cutoff) && strtotime($d[$key]) < strtotime($cutoff)) {
          $done = true;
          break;
        }
        
        // Create a new array with our items and add it to our tweets array
        $tweets[] = array("tweet"=>$t[$key], "status"=>$ts[$key], "date"=>$d[$key], "source"=>strip_tags($s[$key]));
      }
      
      // Check the page to see if there are is another page of tweets. If not, mark our app done
      if (!$done && !$this->MoreTweets($username)) {
        $done = true;
      }
      
      // If $done is set then we have finished scanning. Break
      if ($done) {
        break;
      }
      
      // Delay for some time to give twitter.com a breather
      usleep(1000000); // one second
    }
    
    // Add our tweets to the database
    $this->InsertTweets($username, $tweets);
    
    // print_r($tweets); // FOR TESTING ONLY
    
    return count($tweets);
  }
  
  /**
  The following four methods all scan the page retrieved from Twitter for data.
  ReadTweetStatuses obtains all the status numbers on the page
  ReadDates obtains all the dates on the page
  ReadSources obtains all the sources on the page
  MoreTweets checks the page to see if there is a page after the one just scanned
  
  All of these methods depend on REGEX's that may be changed at any time by the folks at Twitter. 
  Please update them as twitter does.
  */
  private function ReadTweetStatuses() {
    $pattern = "/<tr class=\"hentry status( reply)?( latest-status)?\" id=\"status_([0-9]+)\"><td/";
    preg_match_all($pattern, $this->page, $matches);
    
    return $matches[3];
  }

  private function ReadTweets() {
    $pattern = "/<td class=\"status-body\"><div><span class=\"entry-content\">(.*?)<\/span>/";
    preg_match_all($pattern, $this->page, $matches);
    
    return $matches[1];
  }
  
  private function ReadDates() {
    $pattern = "/<span class=\"published\" title=\"([0-9\-T:\+]+)\">/";
    preg_match_all($pattern, $this->page, $matches);
  
    return $matches[1];
  }
  
  private function ReadSources() {
    $pattern = "%</a> <span>from (.+?)</span>%";
    preg_match_all($pattern, $this->page, $matches);
    
    return $matches[1];
  }
  
  private function MoreTweets($username) {
    $pattern = "%<a href=\"/$username\?page=([0-9]+)\" class=\"section_links\" rel=\"prev\">Older &#187;</a>%";

    return (preg_match($pattern, $this->page)) ? true : false;

  }
  
  /**
  * This method inserts tweets into the database. If a usersTable is defined it will first
  * check to see if that user already is in the database. If they are, it uses their id for the
  * username_id in the tweets table. If it isn't set then it just inserts the username into the 
  * username field in the tweets table. (Different structures for normalized and denormalized database
  * structures)
  */
  private function InsertTweets($username, $tweets) {
    if (!is_null($this->userTable)) {
      // Obtain the user ID.
      $query = "SELECT `id` FROM `$this->userTable` WHERE `username`='" . mysql_real_escape_string($username) . "' LIMIT 1";
      $result = mysql_query($query);
      
      if (!$result) {
        die("Could not select from userTable. Tables may not exist in DB.\n");
      }
      
      // Check if the result returned anything. If not, add a new user.
      if (!mysql_num_rows($result)) {
        $query = "INSERT INTO `$this->userTable` SET `username`='" . mysql_real_escape_string($username) . "'";
        mysql_query($query);
        
        $user = mysql_insert_id();
      } else {
        $row = mysql_fetch_object($result);
        $user = $row->id;
      }
      
      
    } else {
      $user = mysql_real_escape_string($username);
    }
    
    
    foreach ($tweets as $tweet) {
      $query = "INSERT INTO `$this->tweetTable` VALUES ('0','$user',
      '" . mysql_real_escape_string($tweet["date"]) . "','" . mysql_real_escape_string($tweet["status"]) . "', '',
      '" . mysql_real_escape_string($tweet["source"]) . "', '" . mysql_real_escape_string(strip_tags($tweet["tweet"])) . "')";
      mysql_query($query);
    }
  }
  
  /**
  * This method creates the MySQL tables for you if they haven't been yet =)
  */
  
  public function CreateTables() {
    // Check if we're using normalized tables or not
    if (is_null($this->userTable)) { 
      // Not normalized
      $query = "
CREATE TABLE IF NOT EXISTS `$this->tweetTable` (
    `id` int(10) unsigned NOT NULL auto_increment,
    `twitter_username` varchar(255) NOT NULL,
    `tweet_date` datetime NOT NULL,
    `status_id` int(11) NOT NULL,
    `tweet_source_url` varchar(255) NOT NULL,
    `tweet_source_name` varchar(255) NOT NULL,
    `tweet` varchar(255) NOT NULL,
    UNIQUE INDEX ( `status_id` ),
    PRIMARY KEY  (`id`)
) ENGINE=MyISAM;";
      mysql_query($query);
    } else { 
      // Normalized tables
      // Tweet table
      $query = " 
CREATE TABLE IF NOT EXISTS `$this->tweetTable` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY ,
  `user_id` INT UNSIGNED NOT NULL ,
  `tweet_date` DATETIME NOT NULL ,
  `status_id` INT NOT NULL ,
  `tweet_source_url` VARCHAR( 255 ) NOT NULL ,
  `tweet_source_name` varchar(255) NOT NULL,
  `tweet` varchar(255) NOT NULL,
  UNIQUE INDEX ( `status_id` ),
  INDEX ( `user_id` )
) ENGINE = MYISAM;";
      mysql_query($query);
      // User table      
      $query = "
CREATE TABLE IF NOT EXISTS `$this->userTable` (
  `id` INT NOT NULL AUTO_INCREMENT PRIMARY KEY ,
  `username` VARCHAR( 255 ) NOT NULL
) ENGINE = MYISAM;";
      mysql_query($query);
    }
    
  }
  
}

?>

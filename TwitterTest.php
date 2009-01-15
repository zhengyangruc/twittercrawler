<?php
require_once("TwitterScanner.php");

// Ensure we have a username from $_GET
if (!isset($_GET["username"]))
  die("Error: You must provide a tweeter to scan!");

// Set our cutoff if we have one
if (isset($_GET["cutoff"])) {
  $cutoff = $_GET["cutoff"];
  
  // Ensure it's a valid time
  if (strtotime($cutoff) == 0)
    die("Provided cutoff date is invalid");
} else
  $cutoff = null;
  
try {
  /**
  * The following line of code creates our TwitterScanner object and connects to our database
  * Argument 1 is the MySQL username, 2 is the MySQL password, and 3 is the MySQL database
  */
  $handle = new TwitterScanner("root", "", "dev_twitterscanner");
  /**
  * This next line assigns the tables we will be storing information in. Argument 2 can be left blank. If it is
  * then we will store the all the information in a denormalized format in the tweet table. If argument 2 is defined
  * then a users table is expected. The usernames are stored in this users table, and where the username is in
  * the tweets table is the ID of the row in the users table, and the username field in the tweets table is
  * an index.
  */
  $handle->SetTables("tweets", "users");
  
  // Uncomment the following line if you need to create table(s). Be sure to comment it again after its first run
  $handle->CreateTables();
  
  echo "Read " . $handle->Scan($_GET["username"], $cutoff) . " tweets!";
} catch (Exception $e) {
  echo "Error parsing tweets: " . $e->getMessage();
}
?>
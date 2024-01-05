<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once('includes/home-assistant-kiosk.php');

$homeAssistantBaseUrl='https://ha.example.com:8123/';
$username = 'MyHomeAssistantUsername';
$password = 'MyHomeAssistantPassword';
$dashboardUrl = 'lovelace-frame';

$ha = new HomeAssistantKiosk($homeAssistantBaseUrl);

$ha->login($username,$password,$dashboardUrl);

//wait for login to finish
while( $ha->hasFinishedLoggingIn() === false ) {
  sleep(1);
}

//remove top bar
$ha->removeTopBar();

//remove side bar
$ha->removeSideBar();
?>

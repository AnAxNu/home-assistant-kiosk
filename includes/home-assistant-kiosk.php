<?php
require_once(__DIR__ . '/modules/marionette-driver/includes/marionette-driver.php');

/**
 * Home Assistant kiosk
 *
 * A class for setting up kiosk with Home Assistant
 *
 * @author AnAx
 * @copyright GPL-2.0 license
 * @version 0.0.1
 */
class HomeAssistantKiosk {

  private const HTTP_METHOD_POST = 'post';
  private const HTTP_METHOD_GET = 'get';

  private ?MarionetteDriver $marionetteDriver = null;
  private ?string $baseUrl = null;

  /**
   * Constructor
   *
   * @param string $baseUrl The Home Assistant base url, ex: https://ha.yourdomain.com:8123/
   *
   * @return void
   */
  public function __construct(string $baseUrl) {
    $this->baseUrl = $baseUrl;

    $this->startDriver();
  }

  public function __destruct() {
    
  }

  /**
   * Set error message
   *
   * @param string $errStr The error message
   *
   * @return void
   */
  protected function setError(string $errStr) {
    echo('HomeAssistantKiosk error: ' . $errStr . "\n");
  }

  /**
   * Setup marionette driver object
   * 
   * Create a new MarionetteDriver object and start a new session.
   *
   * @return string|null MarionetteDriver object on success or null on fail
   */
  protected function startDriver() : bool {
    $rtn = false;

    $this->marionetteDriver = new MarionetteDriver();

    if(empty($this->marionetteDriver->startNewSession())) {
      $this->setError('Failed to start new marionette session');
      $this->marionetteDriver = null;
    }else{
      $rtn = true;
    }
    
    return $rtn;
  }

  protected function doRequest(string $method, string $url, $data, string $contentTypeStr='application/json') : ?string {
    $rtn = null;
    
    $contentTypeArr = array('Content-Type: ' . $contentTypeStr);

    $ch = curl_init($url); 
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1); 
    curl_setopt($ch, CURLOPT_HTTPHEADER, $contentTypeArr);
    //curl_setopt($ch, CURLOPT_HTTPHEADER, array('Content-Type: multipart/form-data'));
    
    $result = curl_exec($ch);
    curl_close($ch);

    $rtn = $result;

    return $rtn;
  }

  /**
   * Get a flow id (flow_id) from Home Assistant
   * 
   * This is step 1 to get login credentials from Home Assistant
   *
   * @return string|null Id string on success or null on fail
   */
  protected function getLoginFlowId() : ?string {
    $rtn = null;

    $data = '{"client_id":"' . $this->baseUrl . '","handler":["homeassistant",null],"redirect_uri":"' . $this->baseUrl . '?auth_callback=1"}';
    $url = $this->baseUrl . 'auth/login_flow';

    $jsonResponse = $this->doRequest(self::HTTP_METHOD_POST,$url,$data);
    if(empty($jsonResponse)) {
      $this->setError('Failed to get flowId');
    }else{
      $jsonArr = json_decode($jsonResponse,true);

      if(empty($jsonArr)) {
        $this->setError('Failed to decode response json string for flowId: ' . $jsonResponse);
      }else{
        if(empty($jsonArr['flow_id'])) {
          $this->setError('flow_id not found in response');
        }else{
          $rtn = $jsonArr['flow_id'];
        }
      }
    }

    return $rtn;
  }

  /**
   * Get a login code (code) from Home Assistant
   * 
   * This is step 2 to get login credentials from Home Assistant
   *
   * @param string $flowId A flow id ( returned from getLoginFlowId() )
   * @param string $username Home Assistant username
   * @param string $username Home Assistant password
   *
   * @return string|null Code string on success or null on fail
   */
  protected function getLoginCode(string $flowId,string $username, string $password) : ?string {
    $rtn = null;

    $url = $this->baseUrl . 'auth/login_flow/' . $flowId;
    $data = '{"username": "' . $username . '","password": "' . $password . '","client_id": "' . $this->baseUrl . '"}';

    $jsonResponse = $this->doRequest(self::HTTP_METHOD_POST,$url,$data);
    if(empty($jsonResponse)) {
      $this->setError('Failed to get code');
    }else{
      $jsonArr = json_decode($jsonResponse,true);

      if(empty($jsonArr)) {
        $this->setError('Failed to decode response json string for code: ' . $jsonResponse);
      }else{
        if(empty($jsonArr['result'])) {
          $this->setError('result (code) not found in response');
        }else{
          $rtn = $jsonArr['result'];
        }
      }
    }


    return $rtn;
  }


  /**
   * Get login token data from Home Assistant
   * 
   * This is step 3 to get login credentials from Home Assistant
   *
   * @param string $code Code string ( returned from getLoginCode() )
   * @return array|null Token data array on success or null on fail
   */
  protected function getLoginTokenData(string $code) : ?array {
    $rtn = null;

    $url = $this->baseUrl . 'auth/token';
    $data = [];
    $data['client_id'] = $this->baseUrl;
    $data['code'] = $code;
    $data['grant_type'] = 'authorization_code';

    $jsonResponse = $this->doRequest(self::HTTP_METHOD_POST,$url,$data, 'multipart/form-data');
    if(empty($jsonResponse)) {
      $this->setError('Failed to get token');
    }else{
      $jsonArr = json_decode($jsonResponse,true);

      if(empty($jsonArr)) {
        $this->setError('Failed to decode response json string for token: ' . $jsonResponse);
      }else{
        if(empty($jsonArr['access_token'])) {
          $this->setError('result (access_token) not found in response');
        }else{
          $rtn = $jsonArr;
        }
      }
    }

    return $rtn;
  }

  /**
   * Get a login data to be able to login to Home Assistant
   * 
   * The fetched data makes it possible to login to Home Assistant by setting it in
   * the browsers local storage using the key 'hassTokens'.
   *
   * @param string $username Home Assistant username
   * @param string $username Home Assistant password
   *
   * @return string|null Json login string on success or null on fail
   */
  protected function getLoginData(string $username, string $password) : ?string {
    $rtn = null;

    $flowId = $this->getLoginFlowId();
    if(!empty($flowId)) {

      $code = $this->getLoginCode($flowId,$username,$password);
      if(!empty($code)) {

        $tokenDataArr = $this->getLoginTokenData($code);
        if(!empty($tokenDataArr)) {
          //got needed data from HA, now add some fields
          $tokenDataArr['hassUrl'] = rtrim($this->baseUrl,'/');
          $tokenDataArr['clientId'] = $this->baseUrl;
          $tokenDataArr['expires'] = time() + intval($tokenDataArr['expires_in']) * 1000;

          $tokenJson = json_encode($tokenDataArr);
          if($tokenJson === false) {
            $this->setError('Failed to encode login array to json');
          }else{
            $rtn = $tokenJson;
          }
        }
      }
      
    }

    return $rtn;
  }

  /**
   * Login to Home Assistant in browser
   * 
   * Create login json string, by calling Home Assistant with $username and $password,
   * and then set the string in the browsers local storage using the key 'hassTokens'.
   *
   * @param string $username Home Assistant username
   * @param string $username Home Assistant password
   * @param string $dashboardUrlPath Url path to page to be shown when logged in, ex: 'lovelace-frame/0'.
   *                                 The base url is used to create a complete url. If not set then the
   *                                 base url is used.
   *
   * @return string|null Json login string on success or null on fail
   */
  public function login(string $username, string $password, ?string $dashboardUrlPath=null) : bool {
    $rtn = false;

    $loginStr = $this->getLoginData($username,$password);
    if(!empty($loginStr)) {

      if($this->marionetteDriver->navigateToUrl($this->baseUrl) === false) {
        $this->setError('Failed to navigate to Home Assistant url: ' . $this->baseUrl);
      }else{

        //set login string and some parameters in local storage
        //note: some strings need to be set with quotes, like the value for 'dockedSidebar' : '"always_hidden"'
        $localStorageArr = [];
        $localStorageArr['hassTokens'] = $loginStr;
        $localStorageArr['defaultPanel'] = '"lovelace-frame"';
        $localStorageArr['dockedSidebar'] = '"always_hidden"';
        $localStorageArr['enableShortcuts'] = true;
        $localStorageArr['selectedLanguage'] = null;
        $localStorageArr['selectedTheme'] = '{"dark":true}';
        $localStorageArr['suspendWhenHidden'] = true;
        $localStorageArr['vibrate'] = false;
        $localStorageArr['sidebarHiddenPanels'] = '"["media-browser","history","logbook","map","energy","lovelace-alex"]"';
        
        if($this->marionetteDriver->setLocalStorage($localStorageArr) === false) {
          $this->setError('Failed to set local storage parameters');
        }else{

          //navigate to url to refresh the browser so the set local storage login is detected
          $nav2Url = $this->baseUrl;
          if(!empty($dashboardUrlPath)) {
            $nav2Url .= $dashboardUrlPath;
          }
          $this->marionetteDriver->navigateToUrl($nav2Url);

          $rtn = true;
        }

      }

    }

    return $rtn;
  }

  /**
   * Logout of Home Assistant in browser
   * 
   * Logout of Home Assistant in the browser by deleting the local storage login data ('hassTokens').
   * 
   * @param bool $clearStorage If all local storage should be cleared/deleted or only the login data
   *
   * @return bool True on success or false on fail
   */
  public function logout(bool $clearStorage=true) : bool {
    $rtn = false;

    if($clearStorage === true) {
      //clear all local storage
      if($this->marionetteDriver->clearLocalStorage() === false) {
        $this->setError('Failed to clear local storage to logout');
      }else{
        $rtn = true;
      }
    }else{
      //only delete login data from storage
      $localStorageArr = ['hassTokens' => ''];
      if($this->marionetteDriver->setLocalStorage($localStorageArr) === false) {
        $this->setError('Failed to set local storage login parameters (to empty) to logout');
      }else{
        $rtn = true;
      }
    }

    return $rtn;
  }

}

?>
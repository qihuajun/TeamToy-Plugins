<?php

namespace Evernote;

require_once dirname(__DIR__)."/Thrift.php";
require_once dirname(__DIR__)."/transport/TTransport.php";
require_once dirname(__DIR__)."/transport/THttpClient.php";
require_once dirname(__DIR__)."/protocol/TProtocol.php";
require_once dirname(__DIR__)."/protocol/TBinaryProtocol.php";
require_once dirname(__DIR__)."/packages/UserStore/UserStore.php";
require_once dirname(__DIR__)."/packages/UserStore/UserStore_constants.php";
require_once dirname(__DIR__)."/packages/NoteStore/NoteStore.php";

require_once dirname(__DIR__)."../../oauth/OAuthStore.php";
require_once dirname(__DIR__)."../../oauth/OAuthRequester.php";

use \OAuthStore;
use \OAuthRequester;

class Client
{
    private $consumerKey;
    private $consumerSecret;
    private $sandbox;
    private $serviceHost;
    private $additionalHeaders;
    private $token;
    private $secret;

    public function __construct($options)
    {
        $this->consumerKey = isset($options['consumerKey']) ? $options['consumerKey'] : null;
        $this->consumerSecret = isset($options['consumerSecret']) ? $options['consumerSecret'] : null;

        $options += array('sandbox' => true);
        $this->sandbox = $options['sandbox'];
        
        $options += array('serviceHost' => 'https://www.evernote.com');
        
        $defaultServiceHost = $this->sandbox ? 'https://sandbox.evernote.com' : $options['serviceHost'];

        $this->serviceHost = $defaultServiceHost;

        $options += array('additionalHeaders' => array());
        $this->additionalHeaders = $options['additionalHeaders'];

        $this->token = isset($options['token']) ? $options['token'] : null;
        $this->secret = isset($options['secret']) ? $options['secret'] : null;
        
        if(!$this->token){
        	$options = array(
        			'consumer_key' =>  $this->consumerKey,
        			'consumer_secret' => $this->consumerSecret,
        			'server_uri' => $this->serviceHost,
        			'request_token_uri' => $this->serviceHost."/oauth",
        			'authorize_uri' => $this->serviceHost.'/OAuth.action',
        			'access_token_uri' => $this->serviceHost."/oauth"
        	);
        	
        	OAuthStore::instance("Session", $options);
        }
        
       
    }

    public function getRequestToken($callbackUrl)
    {
    	try {
    		$getAuthTokenParams = array(
				'oauth_callback' => $callbackUrl
			);
    		$tokenResultParams = OAuthRequester::requestRequestToken($this->consumerKey, 0, $getAuthTokenParams);
    		 
    		return $tokenResultParams;
    	}catch (\Exception $e){
    		
    	}
    	
    	
    }

    public function getAccessToken($oauthToken, $tokenResultParams)
    {
        try {
        	$accessToken = OAuthRequester::requestAccessToken($this->consumerKey, $oauthToken, 0, 'POST', $tokenResultParams);
        	
        	$this->token = $accessToken['oauth_token'];
        	
        	return $accessToken;
        } catch (Exception $e) {
        	return false;
        }
    	
    }

    public function getAuthorizeUrl($requestToken)
    {
        $url = $this->getEndpoint('OAuth.action');
        $url .= '?oauth_token=';
        $url .= urlencode($requestToken);

        return $url;
    }

    public function getUserStore()
    {
        $userStoreUrl = $this->getEndpoint('/edam/user');

        return new Store($this->token, '\EDAM\UserStore\UserStoreClient', $userStoreUrl);
    }

    public function getNoteStore()
    {
        $userStore = $this->getUserStore();
        $noteStoreUrl = $userStore->getNoteStoreUrl();

        return new Store($this->token, '\EDAM\NoteStore\NoteStoreClient', $noteStoreUrl);
    }

    public function getSharedNoteStore($linkedNoteBook)
    {
        $userStore = $this->getUserStore();
        $bizAuth = $userStore->authenticateToBusiness();
        $bizToken = $bizAuth->authenticationToken;
        $noteStoreUrl = $bizAuth->noteStoreUrl;

        return new Store($bizToken, '\EDAM\NoteStore\NoteStoreClient', $noteStoreUrl);
    }

    public function getBusinessNoteStore()
    {
        $noteStoreUrl = $linkedNotebook->noteStoreUrl;
        $noteStore = new Store($this->token, '\EDAM\NoteStore\NoteStoreClient', $noteStoreUrl);
        $sharedAuth = $noteStore->authenticateToSharedNotebook($linkedNotebook->shareKey);
        $sharedToken = $sharedAuth->authenticationToken;

        return new Store($sharedToken, '\EDAM\NoteStore\NoteStoreClient', $noteStoreUrl);
    }

    protected function getEndpoint($path = null)
    {
        $url = $this->serviceHost;
        if ($path != null) {
            $url .= "/".$path;
        }

        return $url;
    }

}

class Store
{
    private $token;
    private $userAgentId = '';
    private $client;

    public function __construct($token, $clientClass, $storeUrl)
    {
        $this->token = $token;
        if (preg_match(':A=(.+):', $token, $matches)) {
            $this->userAgentId = $matches[1];
        }
        $this->client = $this->getThriftClient($clientClass, $storeUrl);
    }

    public function __call($name, $arguments)
    {
        $method = new \ReflectionMethod($this->client, $name);
        $params = array();
        foreach ($method->getParameters() as $param) {
            $params[] = $param->name;
        }

        if (count($params) == count($arguments)) {
            return $method->invokeArgs($this->client, $arguments);
        } elseif (in_array('authenticationToken', $params)) {
            $newArgs = array();
            foreach ($method->getParameters() as $idx=>$param) {
                if ($param->name == 'authenticationToken') {
                    $newArgs[] = $this->token;
                }
                if ($idx < count($arguments)) {
                    $newArgs[] = $arguments[$idx];
                }
            }

            return $method->invokeArgs($this->client, $newArgs);
        } else {
            return $method->invokeArgs($this->client, $arguments);
        }
    }

    protected function getThriftClient($clientClass, $url)
    {
        $parts = parse_url($url);
        if (!isset($parts['port'])) {
            if ($parts['scheme'] === 'https') {
                $parts['port'] = 443;
            } else {
                $parts['port'] = 80;
            }
        }

        $httpClient = new \THttpClient(
            $parts['host'], $parts['port'], $parts['path'], $parts['scheme']);
        $httpClient->addHeaders(
            array('User-Agent' => $this->userAgentId.' / '.$this->getSdkVersion().'; PHP / '.phpversion()));
        $thriftProtocol = new \TBinaryProtocol($httpClient);

        return new $clientClass($thriftProtocol, $thriftProtocol);
    }

    protected function getSdkVersion()
    {
        $version = $GLOBALS['EDAM_UserStore_UserStore_CONSTANTS']['EDAM_VERSION_MAJOR']
            .'.'.$GLOBALS['EDAM_UserStore_UserStore_CONSTANTS']['EDAM_VERSION_MINOR'];

        return $version;
    }

}

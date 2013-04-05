<?php
/*** 
TeamToy extenstion info block  
##name Evernote 
##folder_name evernote
##author Seven
##email qihjun@gmail.com
##reversion 1
##desp 支持从Evernote获取附件
##update_url http://tt2net.sinaapp.com/?c=plugin&a=update_package&name=evernote 
##reverison_url http://tt2net.sinaapp.com/?c=plugin&a=latest_reversion&name=evernote 
***/

// Include the Evernote API from the lib subdirectory.
// lib simply contains the contents of /php/lib from the Evernote API SDK
define ( "EVERNOTE_LIBS", dirname ( __FILE__ ) . DIRECTORY_SEPARATOR . "lib" );
ini_set ( "include_path", ini_get ( "include_path" ) . PATH_SEPARATOR . EVERNOTE_LIBS );

require_once 'Evernote/Client.php';
require_once 'packages/Types/Types_types.php';

// Import the classes that we're going to be using
use EDAM\Error\EDAMSystemException, EDAM\Error\EDAMUserException, EDAM\Error\EDAMErrorCode, EDAM\Error\EDAMNotFoundException;
use Evernote\Client;
use EDAM\NoteStore\NoteFilter;
use EDAM\NoteStore\NotesMetadataResultSpec;

define('EVERNOTE_SANDBOX', FALSE);

// 检查并创建数据库
if( !mysql_query("SHOW COLUMNS FROM `evernote_user`",db()) )
{
	// table not exists
	// create it
	run_sql("CREATE TABLE `evernote_user` (
  `id` int(11) NOT NULL AUTO_INCREMENT COMMENT '自增主键',
  `uid` int(11) NOT NULL COMMENT '用户ID',
  `name` varchar(64) NOT NULL COMMENT '用户名',
  `edam_userId` bigint(20) NOT NULL COMMENT 'Evernote用户ID',
  `edam_shard` varchar(64) DEFAULT NULL COMMENT 'Evernote Shard',
  `oauth_token` varchar(256) NOT NULL COMMENT 'Evernote oauth Token',
  `oauth_token_secret` varchar(256) DEFAULT NULL COMMENT 'Evernote oauth Token Secret',
  `edam_expires` bigint(20) NOT NULL COMMENT 'Token过期时间',
  `createdtime` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP COMMENT '数据插入时间',
  PRIMARY KEY (`id`),
  UNIQUE KEY `inx_uid` (`uid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8 ");

}


// 添加邮件设置菜单
add_action( 'UI_USERMENU_ADMIN_LAST' , 'evernote_api_setup');
function evernote_api_setup()
{
$content = <<<EOT
<li><a href="javascript:show_float_box( 'Evernote 设置' , '?c=plugin&a=evernote_api' );void(0);">Evernote设置</a></li>
EOT;
echo $content;	 	
}

// 添加邮件设置菜单
add_action( 'UI_USERMENU_ADMIN_LAST' , 'evernote_api_oauth');
function evernote_api_oauth()
{
	$url = c('site_url')."/index.php?c=plugin&a=evernote_oauth";
	if(!is_user_authed_by_evernote()){
		$content = <<<EOT
<li><a href="$url"><img width=16 src='./plugin/evernote/client/enlogo.png'>绑定Evernote</a></li>
EOT;
	}else{
		
	}
	
	echo $content;
}


add_action( 'PLUGIN_EVERNOTE_API' , 'plugin_evernote_api');
function  plugin_evernote_api()
{
	$data = array();
	$data['evernote_api_key'] = kget('evernote_api_key');
	$data['evernote_api_secret'] = kget('evernote_api_secret');
	return render( $data , 'ajax' , 'plugin' , 'evernote' );
}

add_action( 'PLUGIN_EVERNOTE_TREE' , 'plugin_evernote_tree');
function  plugin_evernote_tree()
{
	$data = array();
	return render( $data , 'ajax' , 'plugin' , 'evernote' );
}

add_action( 'PLUGIN_EVERNOTE_API_SAVE' , 'plugin_evernote_api_save');
function  plugin_evernote_api_save()
{
	$key = z(t(v('evernote_api_key')));
	$secret = z(t(v('evernote_api_secret')));

	if(empty($key) || empty($secret)) {
		return ajax_echo('设置内容不能为空');
	}
	
	if(!getTemporaryCredentials($key,$secret)){
		return ajax_echo('错误的Key或者Secret！');
	}
	
	kset('evernote_api_key' , $key);
	kset('evernote_api_secret' , $secret);

	return ajax_echo('设置已保存<script>setTimeout( close_float_box, 500)</script>');

}

add_action( 'PLUGIN_EVERNOTE_API_CALLBACK' , 'plugin_evernote_api_callback');
function plugin_evernote_api_callback()
{
	if(evernote_callback()){
		$accessTokenInfo = getTokenCredentials();
		if($accessTokenInfo){
			if(save_evernote_userinfo($accessTokenInfo)){
				$url = c('site_url')."/index.php?c=dashboard";
				header("Location: $url");
			}
		}
	}
	$data = array();
	render( $data , 'web' , 'plugin' , 'evernote' );
}

add_action( 'PLUGIN_EVERNOTE_OAUTH' , 'plugin_evernote_oauth');
function plugin_evernote_oauth()
{
	if(getTemporaryCredentials(kget('evernote_api_key'),kget('evernote_api_secret'))){
		$authorizationUrl = getAuthorizationUrl();
		header("Location:$authorizationUrl",302);
	}
}

add_action( 'UI_TODO_DETAIL_COMMENTBOX_TOOLBAR' , 'evernote_show_link' );
function evernote_show_link( $data )
{
	if(is_user_authed_by_evernote()){
		echo '<script type="text/javascript" src="./plugin/evernote/static/evernote.js"></script>
			  <div id="todo_comment_evernote" style="background:url(\'./plugin/evernote/enlogo.png\') no-repeat 0;padding-left:18px;"><a href="javascript:show_evernote();void(0);">Evernote</a></div>
				';
	}
}

add_action( 'PLUGIN_EVERNOTE_DATA' , 'evernote_data' );
function evernote_data(  )
{
	$data = array();
	$uid = uid();
	$sql = "SELECT oauth_token FROM evernote_user WHERE uid = $uid;";
	$accessToken = get_var($sql);
	if($accessToken){
		try {
			$client = new Client(array(
					'token' => $accessToken,
					'sandbox' => EVERNOTE_SANDBOX
			));
			
			if(isset($_POST['id'])){
				$id = z(t(v('id')));
				$lv = z(t(v('lv')));
				
				$noteFilter = new NoteFilter();
				$noteFilter->notebookGuid = $id;
				
				$spec = new NotesMetadataResultSpec();
				$spec->includeTitle = true;
				$spec->includeNotebookGuid = true;
				
				$startIndex = 0;
				$totalNotes =  1000;
				
				while ($startIndex<$totalNotes){
					$notesMetadataList  = $client->getNoteStore()->findNotesMetadata($noteFilter,$startIndex,100,$spec);
					$notebooks = $notesMetadataList->notes;
					$startIndex = $notesMetadataList->startIndex + count($notebooks);
					$totalNotes = $notesMetadataList->totalNotes;
					if(EVERNOTE_SANDBOX){
						$host = "sandbox.evernote.com";
					}else{
						$host = "www.evernote.com";
					}
					foreach ($notebooks as $notebook){
						$item = array(
								"id"=>$notebook->guid,
								"name" => $notebook->title,
								"link" => "https://{$host}/shard/s1/view/notebook/".$notebook->guid
						);
						$data[] = $item;
					}
				}
			}else{
				$notebooks = $client->getNoteStore()->listNotebooks();
				foreach ($notebooks as $notebook){
					$item = array(
							"id"=>$notebook->guid,
							"name" => $notebook->name,
							"isParent" => true
					);
					$data[] = $item;
				}
			}
			
		} catch (Exception $e) {
		}
	}
	
	echo json_encode($data);
}




function is_user_authed_by_evernote(){
	$uid = uid();
	$sql = "select edam_expires from evernote_user where uid={$uid}";
	$expire = get_var($sql);
	if($expire){
		if(($expire/1000)<=(time()+24*3600*365)){
			return true;
		}
	}
	return false;
}



/*
 * The first step of OAuth authentication: the client (this application)
* obtains temporary credentials from the server (Evernote).
*
* After successfully completing this step, the client has obtained the
* temporary credentials identifier, an opaque string that is only meaningful
* to the server, and the temporary credentials secret, which is used in
* signing the token credentials request in step 3.
*
* This step is defined in RFC 5849 section 2.1:
* http://tools.ietf.org/html/rfc5849#section-2.1
*
* @return boolean TRUE on success, FALSE on failure
*/
function getTemporaryCredentials($key,$secret)
{
	try {
		$client = new Client(array(
				'consumerKey' => $key,
				'consumerSecret' => $secret,
				'sandbox' => EVERNOTE_SANDBOX
		));
		$requestTokenInfo = $client->getRequestToken(getCallbackUrl());
		if ($requestTokenInfo) {
			$_SESSION['evernote_requestToken'] = $requestTokenInfo['oauth_token'];
			$_SESSION['evernote_requestTokenSecret'] = $requestTokenInfo['oauth_token_secret'];
			return TRUE;
		}
	} catch (OAuthException $e) {
		$error = 'Error obtaining temporary credentials: ' . $e->getMessage();
	}

	return FALSE;
}

/*
 * Get the Evernote server URL used to authorize unauthorized temporary credentials.
*/
function getAuthorizationUrl()
{
	$client = new Client(array(
			'consumerKey' => kget('evernote_api_key'),
			'consumerSecret' => kget('evernote_api_secret'),
			'sandbox' => EVERNOTE_SANDBOX
	));

	return $client->getAuthorizeUrl($_SESSION['evernote_requestToken']);
}


/*
 * Get the URL of this application. This URL is passed to the server (Evernote)
* while obtaining unauthorized temporary credentials (step 1). The resource owner
* is redirected to this URL after authorizing the temporary credentials (step 2).
*/
function getCallbackUrl()
{
	return c('site_url').'/index.php?c=plugin&a=evernote_api_callback';
}

/*
 * The completion of the second step in OAuth authentication: the resource owner
* authorizes access to their account and the server (Evernote) redirects them
* back to the client (this application).
*
* After successfully completing this step, the client has obtained the
* verification code that is passed to the server in step 3.
*
* This step is defined in RFC 5849 section 2.2:
* http://tools.ietf.org/html/rfc5849#section-2.2
*
* @return boolean TRUE if the user authorized access, FALSE if they declined access.
*/
function evernote_callback()
{
	if (isset($_GET['oauth_verifier'])) {
		$_SESSION['evernote_oauthVerifier'] = $_GET['oauth_verifier'];
		return TRUE;
	} else {
		return FALSE;
	}
}



/*
 * The third and final step in OAuth authentication: the client (this application)
* exchanges the authorized temporary credentials for token credentials.
*
* After successfully completing this step, the client has obtained the
* token credentials that are used to authenticate to the Evernote API.
* In this sample application, we simply store these credentials in the user's
* session. A real application would typically persist them.
*
* This step is defined in RFC 5849 section 2.3:
* http://tools.ietf.org/html/rfc5849#section-2.3
*
* @return boolean TRUE on success, FALSE on failure
*/
function getTokenCredentials()
{
// 	if (isset($_SESSION['evernote_accessToken'])) {
// 		return FALSE;
// 	}
	
	try {
		$client = new Client(array(
				'consumerKey' => kget('evernote_api_key'),
				'consumerSecret' => kget('evernote_api_secret'),
				'sandbox' => EVERNOTE_SANDBOX
		));
		$accessTokenInfo = $client->getAccessToken($_SESSION['evernote_requestToken'], $_SESSION['evernote_requestTokenSecret'], $_SESSION['evernote_oauthVerifier']);
		if ($accessTokenInfo) {
			$_SESSION['evernote_accessToken'] = $accessTokenInfo['oauth_token'];
			return $accessTokenInfo;
		}
	} catch (OAuthException $e) {
		$lastError = 'Error obtaining token credentials: ' . $e->getMessage();
	}

	return FALSE;
}


function save_evernote_userinfo($accessTokenInfo){
	try {
		$accessToken = $_SESSION['evernote_accessToken'];
		$client = new Client(array(
				'token' => $accessToken,
				'sandbox' => EVERNOTE_SANDBOX
		));
		$userinfo = $client->getUserStore()->getUser();
		$name = $userinfo->username;
		$uid = uid();
		$sql = "REPLACE INTO evernote_user (uid,name,edam_userId,edam_shard,oauth_token,oauth_token_secret,edam_expires) value({$uid},'{$name}',{$accessTokenInfo['edam_userId']},'{$accessTokenInfo['edam_shard']}','{$accessTokenInfo['oauth_token']}','{$accessTokenInfo['oauth_token_secret']}',{$accessTokenInfo['edam_expires']});";
		run_sql($sql);
		return TRUE;
	}catch (Exception $e){
		return false;
	}
}
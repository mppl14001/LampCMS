<?php
/**
 *
 * License, TERMS and CONDITIONS
 *
 * This software is lisensed under the GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * Please read the license here : http://www.gnu.org/licenses/lgpl-3.0.txt
 *
 *  Redistribution and use in source and binary forms, with or without
 *  modification, are permitted provided that the following conditions are met:
 * 1. Redistributions of source code must retain the above copyright
 *    notice, this list of conditions and the following disclaimer.
 * 2. Redistributions in binary form must reproduce the above copyright
 *    notice, this list of conditions and the following disclaimer in the
 *    documentation and/or other materials provided with the distribution.
 * 3. The name of the author may not be used to endorse or promote products
 *    derived from this software without specific prior written permission.
 *
 * ATTRIBUTION REQUIRED
 * 4. All web pages generated by the use of this software, or at least
 * 	  the page that lists the recent questions (usually home page) must include
 *    a link to the http://www.lampcms.com and text of the link must indicate that
 *    the website's Questions/Answers functionality is powered by lampcms.com
 *    An example of acceptable link would be "Powered by <a href="http://www.lampcms.com">LampCMS</a>"
 *    The location of the link is not important, it can be in the footer of the page
 *    but it must not be hidden by style attibutes
 *
 * THIS SOFTWARE IS PROVIDED BY THE AUTHOR "AS IS" AND ANY EXPRESS OR IMPLIED
 * WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED WARRANTIES OF
 * MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE DISCLAIMED.
 * IN NO EVENT SHALL THE FREEBSD PROJECT OR CONTRIBUTORS BE LIABLE FOR ANY
 * DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
 * (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
 * LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
 * ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
 * (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF
 * THIS SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
 *
 * This product includes GeoLite data created by MaxMind,
 *  available from http://www.maxmind.com/
 *
 *
 * @author     Dmitri Snytkine <cms@lampcms.com>
 * @copyright  2005-2011 (or current year) ExamNotes.net inc.
 * @license    http://www.gnu.org/licenses/lgpl-3.0.txt GNU LESSER GENERAL PUBLIC LICENSE (LGPL) version 3
 * @link       http://www.lampcms.com   Lampcms.com project
 * @version    Release: @package_version@
 *
 *
 */


namespace Lampcms;

/**
 * Class to login or create new account (and then login)
 * the Facebook user
 *
 * @todo Do something if user with the same email already exists
 *
 * @todo post event on user creation, on user update
 * if just adding record to USERS_FACEBOOK for existing user
 * or if updating user data in any way.
 *
 * @todo
 * run post-registration post to wall if Admin set this in config
 *
 * @todo set the fbid as meta tag if facebook user
 * this way we will be able to know that user is facebook user
 *
 *
 *@todo this class should extend Facebook so that we
 *may reuse removeFacebookConnect()
 *
 * @author Dmitri Snytkine
 *
 */
class ExternalAuthFb extends Facebook
{

	/**
	 * Parsed facebook cookie
	 *
	 * @var array
	 */
	protected $aCookieParams = array();

	/**
	 * Array of data returned from Facebook server
	 * @var array
	 */
	protected $aFbUserData = array();

	/**
	 * Indicates if post announcment of new
	 * registation to user's FB Wall
	 *
	 * @var bool
	 */
	protected $bToWall = false;

	/**
	 * Auto generated password for the new user
	 * @var string
	 */
	protected $tempPassword;

	protected function __construct(Registry $oRegistry, array $aFacebookConf, array $aCookieParams)
	{
		if(!extension_loaded('curl')){
			throw new \Lampcms\Exception('Cannot use this class because php extension "curl" is not loaded');
		}
		parent::__construct($oRegistry);
		d('$this->oUser: '.get_class($this->oUser).' '.print_r($this->oUser->getArrayCopy(), 1));
		$this->sAccessToken = $aCookieParams['access_token'];
		$this->sAppId = $aFacebookConf['APP_ID'];
		$this->bToWall = (!empty($aFacebookConf['POST_TO_WALL'])) ? true : false;
		$this->aCookieParams = $aCookieParams;
	}

	/**
	 * Get user data from Facebook, do whatever is necessary
	 * and return either null or object of type UserFacebook
	 * which extends User Object
	 *
	 * @param Registry $oRegistry
	 *
	 * @return mixed null of failure or object UserFacebook
	 *
	 * @throws FacebookAuthException in case user does not have
	 * fbs_ cookie or in case this site config does not have
	 * necessary settings in [FACEBOOK] section
	 * or in case something else goes wrong
	 */
	public static function getUserObject(Registry $oRegistry)
	{

		$oIni = $oRegistry->Ini;
		$aFacebookConf = $oIni->getSection('FACEBOOK');

		if(empty($aFacebookConf) ||
		(is_array($aFacebookConf) && (empty($aFacebookConf['APP_ID']) || empty($aFacebookConf['APP_SECRET']) ) )){
			throw new FacebookAuthException('No values in !config.inc for FACEBOOK');
		}

		$sAppId = $aFacebookConf['APP_ID'];
		$sSecret = $aFacebookConf['APP_SECRET'];

		$cookieName = 'fbs_'.$sAppId;
		if(!isset($_COOKIE) || empty($_COOKIE[$cookieName])){
			throw new FacebookAuthException('No fbs_ cookie present');
		}

		$cookie = $_COOKIE[$cookieName];

		$aCookieParams = array();
		parse_str(trim($cookie, '\\"'), $aCookieParams);

		d('$aCookieParams: '.print_r($aCookieParams, 1));


		if(empty($aCookieParams)
		|| empty($aCookieParams['sig']) || empty($aCookieParams['access_token'])){

			throw new FacebookAuthException('Unable to parse fbs_ cookie: '.$cookie);
		}

		/**
		 * Security check of fbs cookie
		 */
		if($aCookieParams['sig'] !== self::generateSignature($aCookieParams, $sSecret)){

			throw new FacebookAuthException('Facebook signature violation. Potential security threat! '.print_r($aCookieParams, 1));
		}

		/**
		 * At this point we can try to get user
		 * just by facebook uid which should be in fb cookie
		 * If we can get uid by fbid and then userobject by uid
		 * then we can defer calling FB api to later time,
		 * but we can't just call fastcgi_finish_request now
		 * because it would send out something to the browser now
		 * and we still at early stage of page generation.
		 *
		 * We can try to pass anonymous function back to
		 * oGlobal->setPostProcess(function(){
		 * // do some type of fb update but pass
		 * // a CookieParams now
		 * })
		 */
		if(!empty($aCookieParams['uid']) && function_exists('fastcgi_finish_request')){
			d('going to try to get user object by fbu uid cookie');

			$aU = $oRegistry->Mongo->getCollection('USERS_FACEBOOK')->findOne(array('_id' => (string)$aCookieParams['uid']));
			if(!empty($aU) && !empty($aU['i_uid'])){
				d('$aU[i_uid]: '.$aU['i_uid']);
				$uid = (int)$aU['i_uid'];
				$aUser = $oRegistry->Mongo->getCollection('USERS')->findOne(array('_id' => $uid));
				if(!empty($aUser)){
					$oUser = UserFacebook::factory($oRegistry, $aUser);
				}
			}

			/**
			 * If we able to find our user by facebook user id
			 * we will add the job of contacting facebook api
			 * for possible update of data to be executed
			 * later
			 *
			 * But if we did not get facebook user by facebook uid
			 * that means it's a new user and we must finish
			 * this method now in order to create a new user
			 *
			 */
			if(isset($oUser)){
				d('got FB user by fb uid from fbu cookie');

				$oRegistry->Viewer = $oUser;
				$oAuthFB = new self($oRegistry, $aFacebookConf, $aCookieParams);
				$callable = function() use ($oAuthFB){
					try{
						//d('before facebook auth post precessing  $oAuthFB: '.$oAuthFB);
						$oAuthFB->getFbData()->getFacebookUserObject();
						//d('after facebook auth post precessing');
					} catch (Exception $e){
						//e('Unable to run post processing of FB data: '.$e->getFile().' '.$e->getLine().' '.$e->getMessage());
					}
				};

				runLater($callable);

				return $oUser;
			}
		}

		$oAuthFB = new self($oRegistry, $aFacebookConf, $aCookieParams);

		return $oAuthFB->getFbData()->getFacebookUserObject();
	}


	/**
	 * Generate a signature for the given params and secret.
	 *
	 * @param Array $params the parameters to sign
	 * @param String $secret the secret to sign with
	 * @return String the generated signature
	 */
	protected static function generateSignature(array $params, $secret) {

		ksort($params);

		$s = '';
		foreach($params as $key => $value) {
			if ($key != 'sig') {
				$s .= $key . '=' . $value;
			}
		}

		$s .= $secret;

		return hash('md5', $s);
	}

	/**
	 * Return object of type FacebookUser
	 * this is either the existing user or newly created
	 * user
	 *
	 * @return object of type UserFacebook which extends User Object
	 *
	 * @throws FacebookAuthException in case something goes wrong
	 */
	public function getFacebookUserObject()
	{
		d('cp');

		/**
		 * First get userid by fb_id, via cache
		 * even though this is usually less than 1 millisecond,
		 * still avoiding mysql call is good.
		 *
		 */
		$fbid = (string)$this->aFbUserData['id'];
		d('$fbid: '.$fbid);

		$aU = $this->oRegistry->Mongo->getCollection('USERS_FACEBOOK')->findOne(array('_id' => $fbid));
		$uid = (!empty($aU) && !empty($aU['i_uid'])) ? (int)$aU['i_uid'] : null;
		d('uid: '.$uid);

		$uidByEmail = null;

		$bFacebookId = (!empty($uid)) ? true : false;
		d('$bFacebookId: '.$bFacebookId);


		/**
		 * See if we already have the user with the email
		 * address provided by facebook.
		 * In such case we just create the record in USERS_FACEBOOK
		 * And possibly run updateUser()
		 * And then.... append array of access_token, expires
		 * to the object
		 *
		 * @todo potential problem:
		 * someone registers bogus account with someone else's email
		 * address.
		 *
		 * Then the real owner of that email registers via Facebook
		 * We then associate some bogus account with this one
		 *
		 * The bogus account cannot be used by hacker because hacker does
		 * not know the password so this is not a big problem.
		 *
		 *
		 */
		if(empty($uid) && !empty($this->aFbUserData['email'])){
			$aByEmail = $this->oRegistry->Mongo->getCollection('EMAILS')->findOne(array('email' => strtolower($this->aFbUserData['email']) ));
			d('$aByEmail: '.print_r($aByEmail, 1) );
			if(!empty($aByEmail) && !empty($aByEmail['i_uid'])){
				$uidByEmail = (int)$aByEmail['i_uid'];
				d('$uidByEmail: '.$uidByEmail);
			}
		}

		$uid = (!empty($uid)) ? $uid : $uidByEmail;

		/**
		 * This means this facebook user is not
		 * registered on our site.
		 */
		if(empty($uid)){
			d('cp empty uid');
			$this->createNewUser();

			return $this->oUser;
		}

		$aUser = $this->oRegistry->Mongo->getCollection('USERS')->findOne(array('_id' => $uid));
		d('aUser var type: '.gettype($aUser).' ' .print_r($aUser, 1));

		if(!empty($aUser)){
			$this->oUser = UserFacebook::factory($this->oRegistry, $aUser);
			d('existing user $this->oUser: '.print_r($this->oUser->getArrayCopy(), 1));
			$this->updateUser();

			/**
			 * It's possible that this is not the new user
			 * but also a new FACEBOOK user.
			 * This is when we determined that user with this email
			 * already exists in our database but
			 * this user has never logged in as Facebook user
			 * in this case we still have to create a new
			 * record in USERS_FACEBOOK
			 */
			$this->updateFbUserRecord($bFacebookId);
		} else {
			d('cp need to create new user');
			$this->createNewUser();
		}

		return $this->oUser;
	}


	/**
	 * Get JSON data from the server for this user
	 * If timeout, then what? Then we will throw our own
	 * Exception and user will see a message
	 * that timeout has occured
	 *
	 * @return object $this
	 */
	public function getFbData()
	{
		d('$this->oUser: '.get_class($this->oUser).' '.print_r($this->oUser->getArrayCopy(), 1));

		d('cp this is: '.gettype($this).(is_object($this)) ? get_class($this) : 'not object');
		$url = $this->graphUrl.$this->sAccessToken;
		d('url: '.$url);
		//$oHTTP = new Http();
		d('cp');
		//$oHTTP->setOption('timeout', 12);

		$oHTTP = new Curl();

		try{
			d('cp');
			$this->oResponse = $oHTTP->getDocument($url);
			$json = $this->oResponse->getResponseBody();

			$retCode = $oHTTP->getHttpResponseCode();
			d('json '.$json.' http code: '.$retCode);

			$this->aFbUserData = json_decode($json, true);
			d('$this->aFbUserData: '.print_r($this->aFbUserData, 1));

			if(empty($this->aFbUserData)
			|| !is_array($this->aFbUserData)
			|| !array_key_exists('id', $this->aFbUserData)
			|| empty($this->aFbUserData['name'])){

				throw new FacebookAuthException('Invalid data returned by FriendConnect server: '.print_r($this->aFbUserData, 1));;
			}

		} catch (HttpTimeoutException $e ){
			d('Request to GFC server timedout');

			throw new FacebookAuthException('Request to Facebook server timed out. Please try again later');
		} catch (Http401Exception $e){
			d('Unauthorized to get data from Facebook, most likely user unjoined the site');
			$this->revokeFacebookConnect();

			Cookie::delete('fbs_'.$this->sAppId);

			throw new FacebookAuthException('Unauthorized with Facebook server');

		} catch(HttpResponseCodeException $e){
			e('LampcmsError Facebook response exception: '.$e->getHttpCode().' '.$e->getMessage());
			/**
			 * The non-200 response code means there is some kind
			 * of error, maybe authorization failed or something like that,
			 * or maybe Facebook Connect server was acting up,
			 * in this case it is better to delete fcauth cookies
			 * so that we dont go through these steps again.
			 * User will just have to re-do the login fir Facebook step
			 */
			Cookie::delete('fbs_'.$this->sAppId);
			$this->revokeFacebookConnect();
			throw new FacebookAuthException('Error during authentication with Facebook server');
		}

		return $this;
	}



	/**
	 * Update user data but ONLY if name
	 * or access_token value has changed
	 *
	 * If update is necessary, then also
	 * post notification onUserUpdate
	 */
	protected function updateUser($bForceUpdate = false)
	{
		$this->oUser['fb_id'] = (string)$this->aFbUserData['id'];
		$this->oUser['fb_token'] = $this->aCookieParams['access_token'];
		$this->oUser['fn'] = $this->aFbUserData['first_name'];
		$this->oUser['ln'] = $this->aFbUserData['last_name'];
		$this->oUser['avatar_external'] = 'http://graph.facebook.com/'.$this->aFbUserData['id'].'/picture';
		$this->oUser->save();
			
		$this->oRegistry->Dispatcher->post($this->oUser, 'onUserUpdate');

		return $this;
	}

	/**
	 * @todo
	 * What if email address provided from Facebook
	 * already belongs to some other user?
	 *
	 * This would mean that existing user is just
	 * trying to signup with Facebook.
	 *
	 * In this case we should allow it but ONLY create
	 * a record in the USERS_FACEBOOK table and use users_id
	 * of use that we find by email address
	 *
	 * and then also insert avatar_external into USERS
	 *
	 * @todo create username for user based on Facebook username
	 * Facebook does not really have username, so we can use fn_ln
	 *
	 */
	protected function createNewUser()
	{
		/**
		 * Time zone offset in seconds
		 * @var int
		 */
		$tzo = (array_key_exists('timezone', $this->aFbUserData)) ? $this->aFbUserData['timezone'] * 3600 : Cookie::get('tzo', 0);

		/**
		 * User language
		 * @var unknown_type
		 */
		$lang = (!empty($this->aFbUserData['locale'])) ? strtolower(substr($this->aFbUserData['locale'], 0, 2)) : $this->oRegistry->getCurrentLang();

		$this->tempPassword = String::makePasswd();

		/**
		 * Sid value use existing cookie val
		 * if possible, otherwise create a new one
		 * @var string
		 */
		$sid = (false === ($sid = Cookie::getSidCookie())) ? String::makeSid() : $sid;


		/**
		 * Create new record in USERS table
		 * do this first because we need uid from
		 * newly created record
		 */
		$aUser = array(
		'fn' => $this->aFbUserData['first_name'],
		'ln' => $this->aFbUserData['last_name'],
		'rs' => $sid,
		'email' => strtolower($this->aFbUserData['email']),
		'fb_id' => (string)$this->aFbUserData['id'], 
		'fb_token' => $this->aCookieParams['access_token'],
		'pwd' => String::hashPassword($this->tempPassword),
		'avatar_external' => 'http://graph.facebook.com/'.$this->aFbUserData['id'].'/picture',
		'i_reg_ts' => time(),
		'date_reg' => date('r'),
		'role' => 'external_auth',
		'lang' => $lang,
		'i_rep' => 1,
		'tz' => TimeZone::getTZbyoffset($tzo),
		'i_fv' => (false !== $intFv = Cookie::getSidCookie(true)) ? $intFv : time());

		if(!empty($this->aFbUserData['gender'])){
			$aUser['gender'] = ('male' === $this->aFbUserData['gender']) ? 'M' : 'F';
		}

		$oGeoData = $this->oRegistry->Cache->{sprintf('geo_%s', Request::getIP())};
		$aProfile = array(
		'cc' => $oGeoData->countryCode,
		'country' => $oGeoData->countryName,
		'state' => $oGeoData->region,
		'city' => $oGeoData->city,
		'zip' => $oGeoData->postalCode);
		d('aProfile: '.print_r($aProfile, 1));

		$aUser = array_merge($aUser, $aProfile);

		if(!empty($this->aFbUserData['locale'])){
			$aUser['locate'] = $this->aFbUserData['locale'];
		}

		if(!empty($this->aFbUserData['link'])){
			$aUser['fb_url'] = $this->aFbUserData['link'];
		}

		d('aUser: '.print_r($aUser, 1));

		$this->oUser = UserFacebook::factory($this->oRegistry, $aUser);
		$this->oUser->insert();
		//$this->oUser->setNewUser();

		d('cp');
		$this->oRegistry->Dispatcher->post($this->oUser, 'onNewUser');
		$this->oRegistry->Dispatcher->post($this->oUser, 'onNewFacebookUser');
		d('cp');

		$this->saveEmailAddress();
		d('cp');

		/**
		 * Create new record in USERS_FACEBOOK
		 */
		$this->updateFbUserRecord();

		PostRegistration::createReferrerRecord($this->oRegistry, $this->oUser);

		$this->postRegistrationToWall();

		return $this;
	}


	/**
	 * Create new record in EMAILS table for this new user
	 * but only if user has provided email address
	 *
	 * @return object $this
	 */
	protected function saveEmailAddress()
	{
		if(!empty($this->aFbUserData['email'])){
			$coll = $this->oRegistry->Mongo->getCollection('EMAILS');
			$coll->ensureIndex(array('email' => 1), array('unique' => true));

			$a = array(
			'email' => strtolower($this->aFbUserData['email']),
			'i_uid' => $this->oUser->getUid(),
			'has_gravatar' => Gravatar::factory($this->aFbUserData['email'])->hasGravatar(),
			'ehash' => hash('md5', $this->aFbUserData['email']));
			try{
				$o = MongoDoc::factory($this->oRegistry, 'EMAILS', $a)->insert();
			} catch (\Exception $e){
				e('Unable to save email address from Facebook to our EMAILS: '.$e->getMessage().' in '.$e->getFile().' on '.$e->getLine());
			}
		}

		return $this;
	}

	/**
	 * Create a new record in USERS_FACEBOOK table
	 * or update an existing record
	 *
	 * @param bool $isUpdate
	 */
	protected function updateFbUserRecord($isUpdate = false)
	{

		/**
		 * In case of update, update only if fcauth value changed
		 * Also if fcauth changed, need to update (delete)
		 * user objects from cache
		 * But what about the cache object for non-gfc user?
		 * Can user login by id?
		 *
		 */
		/**
		 * Create new record in USERS_GFC table
		 */
		$uid = $this->oUser->getUid();
		d('uid '.$uid);

		$aFb = array(
		'_id' => (string)$this->aFbUserData['id'],
		'i_uid' => $uid,
		'access_token' => $this->aCookieParams['access_token'],
		'token_expiration' => (array_key_exists('expires', $this->aCookieParams)) ? $this->aCookieParams['expires'] : 0,
		'a_data' => $this->aFbUserData);

		d('aFb: '.print_r($aFb, 1));

		$this->oRegistry->Mongo->getCollection('USERS_FACEBOOK')->save($aFb, array('fsync' => true));

		return $this;
	}


	/**
	 * @todo translate strings
	 *
	 * @todo have site logo image in settings
	 *
	 * Post to user wall
	 *
	 */
	protected function postRegistrationToWall()
	{
		d('bToWall: '.$this->bToWall);
		if($this->bToWall){
			$aData = array(
		'access_token' => $this->aCookieParams['access_token'],
		'message' => 'Joined this website',
		'link' => $this->oRegistry->Ini->SITE_URL,
		'caption' => 'Interesting stuff',
		'picture' => 'http://www.apacheserver.net/images/apache.jpg',
		'name' => $this->oRegistry->Ini->SITE_TITLE,
		'description' => '<b>Cool stuff </b>');

			$this->postUpdate($aData);

		}
	}


	/**
	 * @todo sent a welcome email,
	 * include temp password and explain to user
	 * that user can keep logging in with facebook connect
	 * OR using email address and password
	 *
	 * Enter description here ...
	 */
	protected function sendWelcomeEmail()
	{

	}

}

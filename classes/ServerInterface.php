<?php
require_once dirname(__FILE__).'/PermissionManager.php';
require_once dirname(__FILE__).'/MurmurClasses.php';

/**
 * Provides murmur server functionality
 */
class ServerInterface{
	private static $instance = null;

	/**
	 * @return ServerInterface_ice
	 */
	public static function getInstance()
	{
		if (!isset(self::$instance)) {
			$dbType = SettingsManager::getInstance()->getDbInterfaceType();
			if ( class_exists('ServerInterface_'.$dbType) ) {
				eval('self::$instance = new ServerInterface_'.$dbType.'();');
			} else {
				MessageManager::addError(tr('error_unknowninterface'));
			}
		}
		return self::$instance;
	}

}

class ServerInterface_ice
{
	private $conn;
	private $meta;
	private $version;
	private $contextVars;

	function __construct()
	{
		// Check that the PHP Ice extension is loaded.
		if (!extension_loaded('ice')) {
			MessageManager::addError(tr('error_noIceExtensionLoaded'));
		} else {
			try {
				Ice_loadProfile();
				$this->contextVars = SettingsManager::getInstance()->getDbInterface_iceSecrets();
				$this->connect();
			} catch (Ice_ProfileAlreadyLoadedException $exc) {
				MessageManager::addError(tr('iceprofilealreadyloaded'));
			}
		}
	}

	private function connect()
	{
		global $ICE;

		//$ICE->setProperty('Ice.ImplicitContext', 'Shared');
		/* not avail. in Ice 3.3
		$ICE->getImplicitContext();
		$ICE->getImplicitContext()->put('secret', 'ts');
		$ICE->getImplicitContext()->put('icesecret', 'ts');*/
		/* for Ice 3.4:
		 * $initData = new Ice_InitializationData;
		 * $initData->properties = Ice_createProperties();
		 * $initData->properties->setProperty('Ice.ImplicitContext', 'Shared');
		 * $ICE = Ice_initialize($initData);
		 */

		$this->conn = $ICE->stringToProxy(SettingsManager::getInstance()->getDbInterface_address());
		// it would be good to be able to add a check if slice file is loaded
		//MessageManager::addError(tr('error_noIceSliceLoaded'));
		$this->meta = $this->conn->ice_checkedCast("::Murmur::Meta");
		// use IceSecret if set
		if (!empty($this->contextVars)) {
			$this->meta = $this->meta->ice_context($this->contextVars);
		}
		$this->meta = $this->meta->ice_timeout(10000);

		// to check the connection get the version (e.g. was a needed (context-)password not provided?)
		try {
			$this->version = $this->getVersion();
		} catch (Ice_UnknownUserException $exc) {
			switch ($exc->unknown) {
				case 'Murmur::InvalidSecretException':
					//TODO i18n
					MessageManager::addError('The Ice end requires a password, but you did not specify one or not the correct one.');
					die('The Ice end requires a password, but you did not specify one or not the correct one.' . get_class($exc) . ' Stacktrage: <pre>' . $exc->getTraceAsString() . '</pre>' );
					$this->conn = null;
					break;

				default:
					//TODO i18n
					MessageManager::addError('Unknown exception was thrown. Please report to the developer. Class: ' . get_class($exc) . isset($exc->unknown)?' ->unknown: '.$exc->unknown:'' . ' Stacktrage: <pre>' . $exc->getTraceAsString() . '</pre>');
					$this->conn = null;
					break;
			}
		} catch (Ice_LocalException $exc) {
			//TODO i18n
			MessageManager::addError('Unknown exception was thrown. Please report to the developer. Class: ' . get_class($exc) . ' Stacktrage: <pre>' . $exc->getTraceAsString() . '</pre>');
			$this->conn = null;
		}
	}

	//Meta
	/**
	 * Get servers version.
	 * @return string version
	 */
	public function getVersion()
	{
		if ($this->version == null) {
			$this->meta->getVersion($major, $minor, $patch, $text);
			$this->version = $major . '.' . $minor . '.' . $patch . ' ' . $text;
		}
		return $this->version;
	}
	/**
	 *
	 * @return Array with name=>value
	 */
	public function getDefaultConfig()
	{
		return $this->meta->getDefaultConf();
	}
	/**
	 * Get all virtual servers
	 * @return unknown_type all virtual servers
	 */
	public function getServers()
	{
		$servers = $this->meta->getAllServers();
		$filtered = array();
		foreach ($servers as $server) {
			// icesecret context
			if (!empty($this->contextVars)) {
				$server = $server->ice_context($this->contextVars);
			}
			if (HelperFunctions::getActiveSection()!='admin' || PermissionManager::getInstance()->isAdminOfServer($server->id()))
				$filtered[] = $server;
		}
		return $filtered;
	}
	/**
	 * Get all running virtual servers
	 * @return unknown_type all running virtual servers
	 */
	public function getRunningServers()
	{
		$servers = $this->meta->getBootedServers();
		return $servers;
	}
	/**
	 * Get a specific virtual server
	 * @param $srvid server id
	 * @return unknown_type (virtual) server
	 */
	public function getServer($srvid)
	{
		$server = $this->meta->getServer(intval($srvid));
		if (!empty($this->contextVars)) {
			$server = $server->ice_context($this->contextVars);
		}
		return $server;
	}
	/**
	 * Create a new virtual server. Will return the created servers id.
	 * @return int server id
	 */
	public function createServer()
	{
		return $this->meta->newServer()->id();
	}



	// Virtual Server

	/**
	 * Is the virtual server currently running?
	 * @param $sid server id
	 * @return boolean
	 */
	public function isRunning($sid)
	{
		return self::getServer($sid)->isRunning();
	}
	/**
	 * Start a specific virtual server
	 * @param $sid server id
	 */
	public function startServer($sid)
	{
		self::getServer($sid)->start();
	}
	/**
	 * Stop a specific running virtual server
	 * @param $sid server id
	 */
	public function stopServer($sid)
	{
		self::getServer($sid)->stop();
	}
	/**
	 * Delete a virtual server with all it's configuration settings
	 * @param $sid server id
	 */
	public function deleteServer($sid)
	{
		if ($this->isRunning($sid)) {
			$this->stopServer($sid);
		}
		$this->getServer($sid)->delete();
	}
	//TODO implement callbacks (add, remove)
	//TODO setAuthenticator(ServerAuthenticator* auth)

	public function getServerConfigEntry($sid, $key)
	{
		return $this->getServer($sid)->getConf($key);
	}
	public function getServerConfig($sid)
	{
		// As an unset config entry will fall back to the default config, we will get the default config and overwrite/add it with server specific settings
		$conf = $this->getDefaultConfig();
		$confS = $this->getServer($sid)->getAllConf();
		foreach ($confS as $key=>$val) {
			$conf[$key] = $val;
		}
		return $conf;
	}
	public function setServerConfigEntry($sid, $key, $newValue)
	{
		$this->getServer($sid)->setConf($key, $newValue);
	}

	public function setServerSuperuserPassword($sid, $newPw)
	{
		$this->getServer($sid)->setSuperuserPassword($newPw);
	}

	/**
	 *
	 * @param $sid server id
	 * @param $first Lowest numbered entry to fetch. 0 is the most recent item.
	 * @param $last Last entry to fetch.
	 * @return array(string) log entries
	 */
	public function getServerLog($sid, $first=25, $last=0)
	{
		return $this->getServer($sid)->getLog($first, $last);
	}


	/**
	 * Get all user registrations of the virtual server
	 * @param $sid
	 * @param $filter a filter
	 * @return sequence of registrations
	 */
	public function getServerRegistrations($serverId, $filter='')
	{
		return $this->getServer($serverId)->getRegisteredUsers($filter);
	}
	/**
	 * @param int $serverId
	 * @param int $userId
	 * @return MurmurRegistration
	 */
	public function getServerRegistration($serverId, $userId)
	{
		$serverId = intval($serverId);
		$userId = intval($userId);

		$server=$this->getServer($serverId);
		if (null===$server) {
			throw new Exception('Invalid server id, server not found.');
		}
		return MurmurRegistration::fromIceObject($server->getRegistration($userId), $serverId, $userId);
	}
	/**
	 * Get connected users of a virtual server
	 * @param $sid
	 * @return array of MurmurUser objects
	 */
	public function getServerUsersConnected($serverId)
	{
		//return $this->getServer($serverId)->getUsers();
		$users = array();
		$userMap = $this->getServer($serverId)->getUsers();
		foreach ($userMap as $sessionId=>$iceUser) {
			$user = MurmurUser::fromIceObject($iceUser);
			$users[] = $user;
		}
		return $users;
	}
	/**
	 * @param int $serverId
	 * @param int $sessionId
	 * @return MurmurUser
	 */
	public function getServerUser($serverId, $sessionId)
	{
		return MurmurUser::fromIceObject($this->getServer($serverId)->getState($sessionId));
	}
	/**
	 * @param int $serverId
	 * @param MurmurUser $user
	 * @return void
	 */
	public function saveServerUser($serverId, MurmurUser $user)
	{
		MurmurUser::fromIceObject($this->getServer($serverId)->setState($user->toIceObject()));
	}
	/**
	 * Get a user account by searching for a specific email.
	 * This will only return the first user account found.
	 * @param $srvid server id
	 * @param $email email address
	 * @return MurmurRegistration registration or null
	 */
	function getUserByEmail($srvid, $email)
	{
		$regs = $this->getServerRegistrations($srvid);
		foreach ($regs AS $uid=>$name) {
			$user = $this->getServerRegistration($srvid, $uid);
			if ($user->getEmail() == $email) {
				return $user;
			}
		}
		return null;
	}
	function getUserName($srvid, $uid)
	{
		return $this->getServerRegistration($srvid, $uid)->getName();
	}
	function getUserEmail($srvid, $uid)
	{
		return $this->getServerRegistration($srvid, $uid)->getEmail();
	}
	function getUserPw($srvid, $uid)
	{
		return $this->getServerRegistration($srvid,$uid)->getPassword();
	}
	function getUserTexture($srvid, $uid)
	{
		return $this->getServer($srvid)->getTexture(intval($uid));
	}

	function addUser($srvid, $name, $password, $email=null)
	{
		try {
			$tmpServer = ServerInterface::getInstance()->getServer(intval($srvid));
			if (empty($tmpServer)) {
				echo 'Server could not be found.<br/>';
				die();
			}

			$reg = new MurmurRegistration($srvid, null, $name, $email, null, null, $password);
			$tmpUid = $tmpServer->registerUser($reg->toArray());

			echo TranslationManager::getInstance()->getText('doregister_success').'<br/>';
		} catch(Murmur_InvalidServerException $exc) {	// This is depreciated (murmur.ice)
			echo 'Invalid server. Please check your server selection.<br/><a onclick="history.go(-1); return false;" href="?page=register">go back</a><br/>If the problem persists, please contact a server admin or webmaster.<br/>';
		} catch(Murmur_ServerBootedException $exc) {
			echo 'Server is currently not running, but it has to to be able to register.<br/>Please contact a server admin';
		} catch(Murmur_InvalidUserException $exc) {
			echo 'The username you specified is probably already in use or invalid. Please try another one.<br/><a onclick="history.go(-1); return false;" href="?page=register">go back</a>';
		} catch(Ice_UnknownUserException $exc) {	// This should not happen
			echo $exc->unknown.'<br/>';
//			echo '<pre>'; var_dump($exc); echo '</pre>';
		}
	}
	function removeRegistration($srvid, $uid)
	{
		ServerInterface::getInstance()->getServer(intval($srvid))->unregisterUser(intval($uid));
	}
	function saveRegistration(MurmurRegistration $reg)
	{
		$this->getServer($reg->getServerId())->updateregistration($reg->getUserId(), $reg->toArray());
	}
	function updateUserName($srvid, $userId, $newName)
	{
		$reg = $this->getServerRegistration($srvid, $userId);
		$reg->setName($newName);
		$this->getServer($srvid)->updateregistration($userId, $reg->toArray());
	}
	function updateUserEmail($srvid, $userId, $newEmail)
	{
		$srv = $this->getServer($srvid);
		$reg = $this->getServerRegistration($srvid, $userId);
		$reg->setEmail($newEmail);
		$srv->updateregistration($userId, $reg->toArray());
	}
	function updateUserHash($srvid, $userId, $newHash)
	{
		$srv = $this->getServer($srvid);
		$reg = $this->getServerRegistration($srvid, $userId);
		$reg->setHash($newHash);
		$srv->updateRegistration($userId, $reg->toArray());
	}
	function updateUserPw($srvid, $userId, $newPw)
	{
		$srv = $this->getServer($srvid);
		$reg = $this->getServerRegistration($srvid, $userId);
		$reg->setPassword($newPw);
		$srv->updateRegistration($userId, $reg->toArray());
	}
	function updateUserTexture($srvid, $uid, $newTexture)
	{
		try {
			if (is_string($newTexture)) {
				// conversation string -> byte array (PHP5)
				$newTexture = str_split($newTexture);
			}
			$this->getServer($srvid)->setTexture($uid, $newTexture);
			return true;
		} catch(Murmur_InvalidTextureException $exc) {
			MessageManager::addError(tr('error_invalidTexture'));
			return false;
		}
	}
	function muteUser($srvid, $sessid)
	{
		$srv = $this->meta->getServer(intval($srvid));
		$user = $srv->getState(intval($sessid));
		$user->mute = true;
		$srv->setState($user);
	}
	function unmuteUser($srvid, $sessid)
	{
		$srv = $this->meta->getServer(intval($srvid));
		$user = $srv->getState(intval($sessid));
		$user->deaf = false;
		$user->mute = false;
		$srv->setState($user);
	}
	function deafUser($srvid, $sessid)
	{
		$srv = $this->meta->getServer(intval($srvid));
		$user = $srv->getState(intval($sessid));
		$user->deaf = true;
		$srv->setState($user);
	}
	function undeafUser($srvid, $sessid)
	{
		$srv = $this->meta->getServer(intval($srvid));
		$user = $srv->getState(intval($sessid));
		$user->deaf = false;
		$srv->setState($user);
	}
	function kickUser($srvid, $sessid, $reason='')
	{
		$this->meta->getServer(intval($srvid))->kickUser(intval($sessid), $reason);
	}
	function ban($serverId, $ip, $bits=32)
	{
		if (!is_int($ip)) {
			$ip = HelperFunctions::ip2int($ip);
		}

		$srv = $this->meta->getServer(intval($serverId));
		$bans = $srv->getBans();
		$ban = new Murmur_Ban();
	  $ban->address = $ip;
	  $ban->bits = $bits;
		$bans[] = $ban;
		$srv->setBans($bans);
	}
	function unban($serverId, $ipmask, $bits=32)
	{
		$srv = $this->meta->getServer(intval($serverId));
		$bans = $srv->getBans();
		$newBans = array();
		foreach ($bans as $ban)
		{
			if ($ban->address != $ipmask || $ban->bits != $bits) {
				$newBans[] = $ban;
			}
		}
		$srv->setBans($newBans);
	}
	function getServerBans($serverId)
	{
		return $this->meta->getServer(intval($serverId))->getBans();
	}
	function getServerBansIpString($srvid)
	{
		$bans=$this->getServerBans($srvid);
		foreach ($bans as &$ban) {
			$ban->address=HelperFunctions::int2ip($ban->address);
		}
		return $bans;
	}

	function verifyPassword($serverid,$uname,$pw)
	{
		return $this->getServer(intval($serverid))->verifyPassword($uname,$pw);
	}
}

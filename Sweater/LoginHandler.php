<?php

namespace Sweater;
use Sweater\Crypto;
use Silk;
use Silk\Exceptions;


trait LoginHandler {
	
	use Crypto\Cryptography;
	
	public $arrHandlers = [
		'sys' => [
			'verChk' => 'handleVerChk',
			'login' => 'handleLogin',
			'rndK'	=> 'handleRndK',
		]
	];
	
	private $intTime;
	private $strServers;
	
	function decodeData($strData){
		$arrData = simplexml_load_string($strData, 'SimpleXMLElement', LIBXML_NOCDATA);
		if($arrData === false){
			throw new Exceptions\HandlingException('Unable to parse client\'s XML data');
		}
		$arrData = json_decode(json_encode((array)$arrData), true);
		return $arrData;
	}
	
	function getServers(){
		return $this->strServers;
	}
	
	function handleVerChk($arrData, Client $objClient){
		$objClient->sendData('<msg t="sys"><body action="apiOK" r="0"></body></msg>');
	}
	
	function handleLogin($arrData, Client $objClient){
		$strUser = $arrData['body']['login']['nick'];
		$strPass = $arrData['body']['login']['pword'];
		Silk\Logger::Log('Client is attempting to login with username \'' . $strUser . '\'');
		$blnExist = $this->objDatabase->playerExists($strUser);
		if($blnExist === false){
			$objClient->sendError(100);
			return $this->removeClient($objClient->resSocket);
		}
		$arrUser = $this->objDatabase->getRow($strUser);
		$intUser = $arrUser['ID'];
		$strPassword = $arrUser['Password'];
		$strRandom = $objClient->getRandomKey();
		if($this->arrServer['Type'] == 'Login'){
			Silk\Logger::Log('Handling login hashing', 'DEBUG');
			$strUppedPass = strtoupper($strPassword);
			$strEncrypt = $strUppedPass;
			//if($strEncrypt != $strPass){
			if(password_verify($strPass, $strPassword) !== true) {
				Silk\Logger::Log('Failed login attempt for user \'' . $strUser . '\'', Silk\Logger::Debug);
				$objClient->sendError(101);
				$this->removeClient($objClient->resSocket);
			}elseif($arrUser['ID'] > 99999999999){
				Silk\Logger::Log('Failed login attempt for user \'' . $strUser . '\'', Silk\Logger::Debug);
				$objClient->sendError(101);
				$this->removeClient($objClient->resSocket);
			}else {
				// TODO: Implement buddy-on-server smiley thing
				$objClient->sendXt('sd', -1, $this->getServers());
				$strHash = md5(strrev($objClient->strRandomKey));
				Silk\Logger::Log('Random string: ' . $strHash);
				$objClient->arrBuddies = json_decode($arrUser['Buddies'], true);
				$strServers = $this->objDatabase->getServerPopulation();
				$intBuddiesOnline = $this->objDatabase->getOnlineBuddiesCount($objClient);
				$objClient->sendXt('guc', -1, $strServers . ',' . $intBuddiesOnline);
				$objClient->sendXt('l', -1, $intUser, $strHash, '', $strServers);
				$this->objDatabase->updateColumn($intUser, 'LoginKey', $strHash);
				$this->removeClient($objClient->resSocket);
				Silk\Logger::Log('User \'' . $strUser . '\' has successfully logged in!', Silk\Logger::Debug);
			}
		} else {
			Silk\Logger::Log('Handling game hashing', Silk\Logger::Debug);
			$strLoginKey = $this->objDatabase->getLoginKey($intUser);
			$strHash = $this->encryptPassword($strLoginKey . $objClient->strRandomKey) . $strLoginKey;
			if($strHash != $strLoginKey){
				$objClient->sendXt('f#lb', -1, $strHash);
				$objClient->sendXt('l', -1);
				$objClient->setClient($arrUser);
				$this->updateStats();
			} else {
				$objClient->sendError(101);
				$this->removeClient($objClient->resSocket);
			}
		}
	}
	
	function handleRndK($arrData, Client $objClient){
		$objClient->strRandomKey = "e4a2dbcca10a7246817a83cd" . $objClient->strNickname;
		$objClient->sendData('<msg t="sys"><body action="rndK" r="-1"><k>' . $objClient->strRandomKey . '</k></body></msg>'); 
		$objClient->setRandomKey($objClient->strRandomKey);
	}
	
	// TODO: Implement buddy-on-server smiley thing
	function handleUpdateServerList(){
		Silk\Logger::Log('Updating server list');
		$arrServers = $this->arrConfig['Servers'];
		$strServers = '';
		$intServer = 0;
		foreach($arrServers as $intID=>$arrServer){
			if($arrServer['Type'] == 'Game'){
				if($intServer > 0) $strServers .= '%';
				$strServers .= $intID . '|' . $arrServer['Name'] . '|' . $arrServer['Address'] . '|' . $arrServer['Port'];
				$intServer++;
			}
		}
		$this->updateServerList($strServers);
	}
	
	function handleXMLData($strData, Client $objClient){
		if($strData == '<policy-file-request/>'){
			$objClient->sendData('<cross-domain-policy><allow-access-from domain="*" to-ports="*" /></cross-domain-policy>');
		} else {
			$arrData = $this->decodeData($strData);
			if(empty($arrData)){
				throw new Exceptions\HandlingException('Client sending invalid XML');
			}
			$strType = $arrData['@attributes']['t'];
			$strAction = $arrData['body']['@attributes']['action'];
			$strMethod = $this->arrHandlers[$strType][$strAction];
			$blnExist = method_exists($this, $strMethod);
			if($blnExist === false){
				throw new Exceptions\HandlingException('Client sent an unknown packet');
			} else {
				$intTime = time();
				$intUpdate = $intTime + 180;
				if($this->intTime === null) $this->intTime = $intUpdate;
				if($intTime > $this->intTime){
					$this->handleUpdateServerList();
					$this->intTime = $intUpdate;
				}
				$this->$strMethod($arrData, $objClient);
			}
			foreach($this->arrPlugins as $objPlugin){
				$objPlugin->handleLoginPacket([$arrData, $objClient]);
			}
		}
	}
	
	function updateServerList($strServers){
		$this->strServers = $strServers;
	}
	
}

?>

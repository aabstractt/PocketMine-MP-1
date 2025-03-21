<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\network\mcpe\handler;

use pocketmine\entity\InvalidSkinException;
use pocketmine\event\player\PlayerPreLoginEvent;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\network\mcpe\auth\ProcessLoginTask;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\JwtException;
use pocketmine\network\mcpe\JwtUtils;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\LoginPacket;
use pocketmine\network\mcpe\protocol\types\login\AuthenticationData;
use pocketmine\network\mcpe\protocol\types\login\ClientData;
use pocketmine\network\mcpe\protocol\types\login\ClientDataToSkinDataHelper;
use pocketmine\network\mcpe\protocol\types\login\JwtChain;
use pocketmine\network\PacketHandlingException;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\Server;
use Ramsey\Uuid\Uuid;
use function is_array;
use function is_string;
use function preg_match;

/**
 * Handles the initial login phase of the session. This handler is used as the initial state.
 */
class LoginPacketHandler extends PacketHandler{

	private string $xuid = "";

	/**
	 * @phpstan-param \Closure(PlayerInfo) : void $playerInfoConsumer
	 * @phpstan-param \Closure(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void $authCallback
	 */
	public function __construct(
		private Server $server,
		private NetworkSession $session,
		private \Closure $playerInfoConsumer,
		private \Closure $authCallback
	){}

	public function handleLogin(LoginPacket $packet) : bool{
		$extraData = $this->fetchAuthData($packet->chainDataJwt);

		if(!Player::isValidUserName($extraData->displayName)){
			$this->session->disconnect(KnownTranslationKeys::DISCONNECTIONSCREEN_INVALIDNAME);

			return true;
		}

		$clientData = $this->parseClientData($packet->clientDataJwt);

		if ($clientData === null) {
			$this->session->disconnect('Please connect using play.infinitymcpe.com:19132');

			return true;
		}

		try{
			$skin = SkinAdapterSingleton::get()->fromSkinData(ClientDataToSkinDataHelper::fromClientData($clientData));
		}catch(\InvalidArgumentException | InvalidSkinException $e){
			$this->session->getLogger()->debug("Invalid skin: " . $e->getMessage());
			$this->session->disconnect(KnownTranslationKeys::DISCONNECTIONSCREEN_INVALIDSKIN);

			return true;
		}

		if(!Uuid::isValid($extraData->identity)){
			throw new PacketHandlingException("Invalid login UUID");
		}
		$uuid = Uuid::fromString($extraData->identity);
		if($extraData->XUID !== "" || $this->xuid !== ""){
			$playerInfo = new XboxLivePlayerInfo(
				$this->xuid,
				$extraData->displayName,
				$uuid,
				$skin,
				$clientData->LanguageCode,
				(array) $clientData
			);
		}else{
			$playerInfo = new PlayerInfo(
				$extraData->displayName,
				$uuid,
				$skin,
				$clientData->LanguageCode,
				(array) $clientData
			);
		}
		($this->playerInfoConsumer)($playerInfo);

		$ev = new PlayerPreLoginEvent(
			$playerInfo,
			$this->session->getIp(),
			$this->session->getPort(),
			$this->server->requiresAuthentication()
		);
		if($this->server->getNetwork()->getValidConnectionCount() > $this->server->getMaxPlayers()){
			$ev->setKickReason(PlayerPreLoginEvent::KICK_REASON_SERVER_FULL, KnownTranslationKeys::DISCONNECTIONSCREEN_SERVERFULL);
		}
		if(!$this->server->isWhitelisted($playerInfo->getUsername())){
			$ev->setKickReason(PlayerPreLoginEvent::KICK_REASON_SERVER_WHITELISTED, "Server is whitelisted");
		}
		if($this->server->getNameBans()->isBanned($playerInfo->getUsername()) || $this->server->getIPBans()->isBanned($this->session->getIp())){
			$ev->setKickReason(PlayerPreLoginEvent::KICK_REASON_BANNED, "You are banned");
		}

		$ev->call();
		if(!$ev->isAllowed()){
			$this->session->disconnect($ev->getFinalKickMessage());
			return true;
		}

		$this->processLogin($packet, $ev->isAuthRequired());

		return true;
	}

	/**
	 * @throws PacketHandlingException
	 */
	protected function fetchAuthData(JwtChain $chain) : AuthenticationData{
		/** @var AuthenticationData|null $extraData */
		$extraData = null;
		foreach($chain->chain as $k => $jwt){
			//validate every chain element
			try{
				[, $claims, ] = JwtUtils::parse($jwt);
			}catch(JwtException $e){
				throw PacketHandlingException::wrap($e);
			}
			if(isset($claims["extraData"])){
				if($extraData !== null){
					throw new PacketHandlingException("Found 'extraData' more than once in chainData");
				}

				if(!is_array($claims["extraData"])){
					throw new PacketHandlingException("'extraData' key should be an array");
				}
				$mapper = new \JsonMapper();
				$mapper->bEnforceMapType = false; //TODO: we don't really need this as an array, but right now we don't have enough models
				$mapper->bExceptionOnMissingData = true;
				$mapper->bExceptionOnUndefinedProperty = true;
				try{
					/** @var AuthenticationData $extraData */
					$extraData = $mapper->map($claims["extraData"], new AuthenticationData());
				}catch(\JsonMapper_Exception $e){
					throw PacketHandlingException::wrap($e);
				}
			}
		}
		if($extraData === null){
			throw new PacketHandlingException("'extraData' not found in chain data");
		}
		return $extraData;
	}

	/**
	 * @throws PacketHandlingException
	 */
	protected function parseClientData(string $clientDataJwt) : ?ClientData{
		try{
			[, $clientDataClaims, ] = JwtUtils::parse($clientDataJwt);
		}catch(JwtException $e){
			throw PacketHandlingException::wrap($e);
		}

		if (!isset($clientDataClaims['Waterdog_XUID'], $clientDataClaims['Waterdog_IP'])) {
			return null;
		}

		if (!is_string($clientDataClaims['Waterdog_XUID'])) return null;

		$this->xuid = $clientDataClaims['Waterdog_XUID'];

		unset($clientDataClaims['Waterdog_IP'], $clientDataClaims['Waterdog_XUID']);

		$mapper = new \JsonMapper();
		$mapper->bEnforceMapType = false; //TODO: we don't really need this as an array, but right now we don't have enough models
		$mapper->bExceptionOnMissingData = true;
		$mapper->bExceptionOnUndefinedProperty = true;
		try{
			$clientData = $mapper->map($clientDataClaims, new ClientData());
		}catch(\JsonMapper_Exception $e){
			throw PacketHandlingException::wrap($e);
		}
		return $clientData;
	}

	/**
	 * TODO: This is separated for the purposes of allowing plugins (like Specter) to hack it and bypass authentication.
	 * In the future this won't be necessary.
	 *
	 * @throws \InvalidArgumentException
	 */
	protected function processLogin(LoginPacket $packet, bool $authRequired) : void{
		$this->server->getAsyncPool()->submitTask(new ProcessLoginTask($packet->chainDataJwt->chain, $packet->clientDataJwt, $authRequired, $this->authCallback));
		$this->session->setHandler(null); //drop packets received during login verification
	}
}

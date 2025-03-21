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

namespace pocketmine\network\mcpe;

use pocketmine\entity\effect\EffectInstance;
use pocketmine\event\player\PlayerDuplicateLoginEvent;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\form\Form;
use pocketmine\lang\KnownTranslationFactory;
use pocketmine\lang\KnownTranslationKeys;
use pocketmine\lang\Translatable;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\network\mcpe\cache\ChunkCache;
use pocketmine\network\mcpe\compression\CompressBatchPromise;
use pocketmine\network\mcpe\compression\Compressor;
use pocketmine\network\mcpe\compression\DecompressionException;
use pocketmine\network\mcpe\convert\SkinAdapterSingleton;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\encryption\DecryptionException;
use pocketmine\network\mcpe\encryption\EncryptionContext;
use pocketmine\network\mcpe\encryption\PrepareEncryptionTask;
use pocketmine\network\mcpe\handler\DeathPacketHandler;
use pocketmine\network\mcpe\handler\HandshakePacketHandler;
use pocketmine\network\mcpe\handler\InGamePacketHandler;
use pocketmine\network\mcpe\handler\LoginPacketHandler;
use pocketmine\network\mcpe\handler\PacketHandler;
use pocketmine\network\mcpe\handler\PreSpawnPacketHandler;
use pocketmine\network\mcpe\handler\ResourcePacksPacketHandler;
use pocketmine\network\mcpe\handler\SessionStartPacketHandler;
use pocketmine\network\mcpe\handler\SpawnResponsePacketHandler;
use pocketmine\network\mcpe\protocol\AvailableCommandsPacket;
use pocketmine\network\mcpe\protocol\ChunkRadiusUpdatedPacket;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DisconnectPacket;
use pocketmine\network\mcpe\protocol\MapInfoRequestPacket;
use pocketmine\network\mcpe\protocol\ModalFormRequestPacket;
use pocketmine\network\mcpe\protocol\MovePlayerPacket;
use pocketmine\network\mcpe\protocol\NetworkChunkPublisherUpdatePacket;
use pocketmine\network\mcpe\protocol\OpenSignPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketDecodeException;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\PlayerListPacket;
use pocketmine\network\mcpe\protocol\PlayStatusPacket;
use pocketmine\network\mcpe\protocol\serializer\PacketBatch;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializer;
use pocketmine\network\mcpe\protocol\serializer\PacketSerializerContext;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\ServerToClientHandshakePacket;
use pocketmine\network\mcpe\protocol\SetDifficultyPacket;
use pocketmine\network\mcpe\protocol\SetPlayerGameTypePacket;
use pocketmine\network\mcpe\protocol\SetSpawnPositionPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\SetTitlePacket;
use pocketmine\network\mcpe\protocol\SubChunkRequestPacket;
use pocketmine\network\mcpe\protocol\TextPacket;
use pocketmine\network\mcpe\protocol\ToastRequestPacket;
use pocketmine\network\mcpe\protocol\TransferPacket;
use pocketmine\network\mcpe\protocol\types\AbilitiesData;
use pocketmine\network\mcpe\protocol\types\AbilitiesLayer;
use pocketmine\network\mcpe\protocol\types\BlockPosition;
use pocketmine\network\mcpe\protocol\types\command\CommandData;
use pocketmine\network\mcpe\protocol\types\command\CommandEnum;
use pocketmine\network\mcpe\protocol\types\command\CommandOverload;
use pocketmine\network\mcpe\protocol\types\command\CommandParameter;
use pocketmine\network\mcpe\protocol\types\command\CommandPermissions;
use pocketmine\network\mcpe\protocol\types\DimensionIds;
use pocketmine\network\mcpe\protocol\types\PlayerListEntry;
use pocketmine\network\mcpe\protocol\types\PlayerPermissions;
use pocketmine\network\mcpe\protocol\UpdateAbilitiesPacket;
use pocketmine\network\mcpe\protocol\UpdateAdventureSettingsPacket;
use pocketmine\network\NetworkSessionManager;
use pocketmine\network\PacketHandlingException;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\permission\DefaultPermissions;
use pocketmine\player\GameMode;
use pocketmine\player\Player;
use pocketmine\player\PlayerInfo;
use pocketmine\player\UsedChunkStatus;
use pocketmine\player\XboxLivePlayerInfo;
use pocketmine\Server;
use pocketmine\timings\Timings;
use pocketmine\utils\AssumptionFailedError;
use pocketmine\utils\BinaryDataException;
use pocketmine\utils\BinaryStream;
use pocketmine\utils\ObjectSet;
use pocketmine\utils\TextFormat;
use pocketmine\world\Position;
use function array_map;
use function array_values;
use function base64_encode;
use function bin2hex;
use function count;
use function get_class;
use function in_array;
use function json_encode;
use function strcasecmp;
use function strlen;
use function strtolower;
use function substr;
use function time;
use function ucfirst;
use const JSON_THROW_ON_ERROR;

class NetworkSession{
	private const INCOMING_PACKET_BATCH_PER_TICK = 2; //usually max 1 per tick, but transactions arrive separately
	private const INCOMING_PACKET_BATCH_BUFFER_TICKS = 100; //enough to account for a 5-second lag spike

	private const INCOMING_GAME_PACKETS_PER_TICK = 2;
	private const INCOMING_GAME_PACKETS_BUFFER_TICKS = 100;

	private PacketRateLimiter $packetBatchLimiter;
	private PacketRateLimiter $gamePacketLimiter;

	private \PrefixedLogger $logger;
	private ?Player $player = null;
	private ?PlayerInfo $info = null;
	private ?int $ping = null;

	private ?PacketHandler $handler = null;

	private bool $connected = true;
	private bool $disconnectGuard = false;
	private bool $loggedIn = false;
	private bool $authenticated = false;
	private int $connectTime;
	private ?CompoundTag $cachedOfflinePlayerData = null;

	private ?EncryptionContext $cipher = null;

	/** @var string[] */
	private array $sendBuffer = [];

	/**
	 * @var \SplQueue|CompressBatchPromise[]
	 * @phpstan-var \SplQueue<CompressBatchPromise>
	 */
	private \SplQueue $compressedQueue;
	private bool $forceAsyncCompression = true;
	private bool $enableCompression = false; //disabled until handshake completed

	private ?InventoryManager $invManager = null;

	/**
	 * @var \Closure[]|ObjectSet
	 * @phpstan-var ObjectSet<\Closure() : void>
	 */
	private ObjectSet $disposeHooks;

	public function __construct(
		private Server $server,
		private NetworkSessionManager $manager,
		private PacketPool $packetPool,
		private PacketSerializerContext $packetSerializerContext,
		private PacketSender $sender,
		private PacketBroadcaster $broadcaster,
		private EntityEventBroadcaster $entityEventBroadcaster,
		private Compressor $compressor,
		private string $ip,
		private int $port
	){
		$this->logger = new \PrefixedLogger($this->server->getLogger(), $this->getLogPrefix());

		$this->compressedQueue = new \SplQueue();

		$this->disposeHooks = new ObjectSet();

		$this->connectTime = time();
		$this->packetBatchLimiter = new PacketRateLimiter("Packet Batches", self::INCOMING_PACKET_BATCH_PER_TICK, self::INCOMING_PACKET_BATCH_BUFFER_TICKS);
		$this->gamePacketLimiter = new PacketRateLimiter("Game Packets", self::INCOMING_GAME_PACKETS_PER_TICK, self::INCOMING_GAME_PACKETS_BUFFER_TICKS);

		$this->setHandler(new SessionStartPacketHandler(
			$this->server,
			$this,
			fn() => $this->onSessionStartSuccess()
		));

		$this->manager->add($this);
		$this->logger->info("Session opened");
	}

	private function getLogPrefix() : string{
		return "NetworkSession: " . $this->getDisplayName();
	}

	public function getLogger() : \Logger{
		return $this->logger;
	}

	private function onSessionStartSuccess() : void{
		$this->logger->debug("Session start handshake completed, awaiting login packet");
		$this->flushSendBuffer(true);
		$this->enableCompression = true;
		$this->setHandler(new LoginPacketHandler(
			$this->server,
			$this,
			function(PlayerInfo $info) : void{
				$this->info = $info;
				$this->logger->info("Player: " . TextFormat::AQUA . $info->getUsername() . TextFormat::RESET);
				$this->logger->setPrefix($this->getLogPrefix());
				$this->manager->markLoginReceived($this);
			},
			function(bool $isAuthenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void{
				$this->setAuthenticationStatus($isAuthenticated, $authRequired, $error, $clientPubKey);
			}
		));
	}

	protected function createPlayer() : void{
		$this->server->createPlayer($this, $this->info, $this->authenticated, $this->cachedOfflinePlayerData)->onCompletion(
			\Closure::fromCallable([$this, 'onPlayerCreated']),
			fn() => $this->disconnect("Player creation failed") //TODO: this should never actually occur... right?
		);
	}

	private function onPlayerCreated(Player $player) : void{
		if(!$this->isConnected()){
			//the remote player might have disconnected before spawn terrain generation was finished
			return;
		}
		$this->player = $player;
		if(!$this->server->addOnlinePlayer($player)){
			return;
		}

		$this->invManager = new InventoryManager($this->player, $this);

		$effectManager = $this->player->getEffects();
		$effectManager->getEffectAddHooks()->add($effectAddHook = function(EffectInstance $effect, bool $replacesOldEffect) : void{
			$this->entityEventBroadcaster->onEntityEffectAdded([$this], $this->player, $effect, $replacesOldEffect);
		});
		$effectManager->getEffectRemoveHooks()->add($effectRemoveHook = function(EffectInstance $effect) : void{
			$this->entityEventBroadcaster->onEntityEffectRemoved([$this], $this->player, $effect);
		});
		$this->disposeHooks->add(static function() use ($effectManager, $effectAddHook, $effectRemoveHook) : void{
			$effectManager->getEffectAddHooks()->remove($effectAddHook);
			$effectManager->getEffectRemoveHooks()->remove($effectRemoveHook);
		});

		$permissionHooks = $this->player->getPermissionRecalculationCallbacks();
		$permissionHooks->add($permHook = function() : void{
			$this->logger->debug("Syncing available commands and abilities/permissions due to permission recalculation");
			$this->syncAbilities($this->player);
			$this->syncAvailableCommands();
		});
		$this->disposeHooks->add(static function() use ($permissionHooks, $permHook) : void{
			$permissionHooks->remove($permHook);
		});
		$this->beginSpawnSequence();
	}

	public function getPlayer() : ?Player{
		return $this->player;
	}

	public function getPlayerInfo() : ?PlayerInfo{
		return $this->info;
	}

	public function isConnected() : bool{
		return $this->connected && !$this->disconnectGuard;
	}

	public function getIp() : string{
		return $this->ip;
	}

	public function getPort() : int{
		return $this->port;
	}

	public function getDisplayName() : string{
		return $this->info !== null ? $this->info->getUsername() : $this->ip . " " . $this->port;
	}

	/**
	 * Returns the last recorded ping measurement for this session, in milliseconds, or null if a ping measurement has not yet been recorded.
	 */
	public function getPing() : ?int{
		return $this->ping;
	}

	/**
	 * @internal Called by the network interface to update last recorded ping measurements.
	 */
	public function updatePing(int $ping) : void{
		$this->ping = $ping;
	}

	public function getHandler() : ?PacketHandler{
		return $this->handler;
	}

	public function setHandler(?PacketHandler $handler) : void{
		if($this->connected){ //TODO: this is fine since we can't handle anything from a disconnected session, but it might produce surprises in some cases
			$this->handler = $handler;
			if($this->handler !== null){
				$this->handler->setUp();
			}
		}
	}

	/**
	 * @throws PacketHandlingException
	 */
	public function handleEncoded(string $payload) : void{
		if(!$this->connected){
			return;
		}

		Timings::$playerNetworkReceive->startTiming();
		try{
			$this->packetBatchLimiter->decrement();

			if($this->cipher !== null){
				Timings::$playerNetworkReceiveDecrypt->startTiming();
				try{
					$payload = $this->cipher->decrypt($payload);
				}catch(DecryptionException $e){
					$this->logger->debug("Encrypted packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Packet decryption error");
				}finally{
					Timings::$playerNetworkReceiveDecrypt->stopTiming();
				}
			}

			if($this->enableCompression){
				Timings::$playerNetworkReceiveDecompress->startTiming();
				try{
					$decompressed = $this->compressor->decompress($payload);
				}catch(DecompressionException $e){
					$this->logger->debug("Failed to decompress packet: " . base64_encode($payload));
					throw PacketHandlingException::wrap($e, "Compressed packet batch decode error");
				}finally{
					Timings::$playerNetworkReceiveDecompress->stopTiming();
				}
			}else{
				$decompressed = $payload;
			}

			try{
				$stream = new BinaryStream($decompressed);
				$count = 0;
				foreach(PacketBatch::decodeRaw($stream) as $buffer){
					$this->gamePacketLimiter->decrement();
					if(++$count > 100){
						throw new PacketHandlingException("Too many packets in batch");
					}
					$packet = $this->packetPool->getPacket($buffer);
					if($packet === null){
						$this->logger->debug("Unknown packet: " . base64_encode($buffer));
						throw new PacketHandlingException("Unknown packet received");
					}
					try{
						$this->handleDataPacket($packet, $buffer);
					}catch(PacketHandlingException $e){
						$this->logger->debug($packet->getName() . ": " . base64_encode($buffer));
						throw PacketHandlingException::wrap($e, "Error processing " . $packet->getName());
					}
				}
			}catch(PacketDecodeException|BinaryDataException $e){
				$this->logger->logException($e);
				throw PacketHandlingException::wrap($e, "Packet batch decode error");
			}
		}finally{
			Timings::$playerNetworkReceive->stopTiming();
		}
	}

	/**
	 * @throws PacketHandlingException
	 */
	public function handleDataPacket(Packet $packet, string $buffer) : void{
		if(!($packet instanceof ServerboundPacket)){
			throw new PacketHandlingException("Unexpected non-serverbound packet");
		}

		$timings = Timings::getReceiveDataPacketTimings($packet);
		$timings->startTiming();

		try{
			$ev = new DataPacketDecodeEvent($this, $packet->pid(), $buffer);
			$ev->call();
			if($ev->isCancelled()){
				return;
			}

			if ($packet instanceof MapInfoRequestPacket || $packet instanceof SubChunkRequestPacket) {
				$this->disconnect("An error occurred");

				return;
			}

			$decodeTimings = Timings::getDecodeDataPacketTimings($packet);
			$decodeTimings->startTiming();
			try{
				$stream = PacketSerializer::decoder($buffer, 0, $this->packetSerializerContext);
				try{
					$packet->decode($stream);
				}catch(PacketDecodeException $e){
					throw PacketHandlingException::wrap($e);
				}
				if(!$stream->feof()){
					$remains = substr($stream->getBuffer(), $stream->getOffset());
					$this->logger->debug("Still " . strlen($remains) . " bytes unread in " . $packet->getName() . ": " . bin2hex($remains));
				}
			}finally{
				$decodeTimings->stopTiming();
			}

			$ev = new DataPacketReceiveEvent($this, $packet);
			$ev->call();
			if(!$ev->isCancelled()){
				$handlerTimings = Timings::getHandleDataPacketTimings($packet);
				$handlerTimings->startTiming();
				try{
					if($this->handler === null || !$packet->handle($this->handler)){
						$this->logger->debug("Unhandled " . $packet->getName() . ": " . base64_encode($stream->getBuffer()));
					}
				}finally{
					$handlerTimings->stopTiming();
				}
			}
		}finally{
			$timings->stopTiming();
		}
	}

	public function sendDataPacket(ClientboundPacket $packet, bool $immediate = false) : bool{
		if(!$this->connected){
			return false;
		}
		//Basic safety restriction. TODO: improve this
		if(!$this->loggedIn && !$packet->canBeSentBeforeLogin()){
			throw new \InvalidArgumentException("Attempted to send " . get_class($packet) . " to " . $this->getDisplayName() . " too early");
		}

		$timings = Timings::getSendDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$ev = new DataPacketSendEvent([$this], [$packet]);
			$ev->call();
			if($ev->isCancelled()){
				return false;
			}

			$this->addToSendBuffer(self::encodePacketTimed(PacketSerializer::encoder($this->packetSerializerContext), $packet));
			if($immediate){
				$this->flushSendBuffer(true);
			}

			return true;
		}finally{
			$timings->stopTiming();
		}
	}

	/**
	 * @internal
	 */
	public static function encodePacketTimed(PacketSerializer $serializer, ClientboundPacket $packet) : string{
		$timings = Timings::getEncodeDataPacketTimings($packet);
		$timings->startTiming();
		try{
			$packet->encode($serializer);
			return $serializer->getBuffer();
		}finally{
			$timings->stopTiming();
		}
	}
	
	public function addToSendBuffer(string $buffer) : void{
		$this->sendBuffer[] = $buffer;
	}

	private function flushSendBuffer(bool $immediate = false) : void{
		if(count($this->sendBuffer) > 0){
			Timings::$playerNetworkSend->startTiming();
			try{
				$syncMode = null; //automatic
				if($immediate){
					$syncMode = true;
				}elseif($this->forceAsyncCompression){
					$syncMode = false;
				}

				$stream = new BinaryStream();
				PacketBatch::encodeRaw($stream, $this->sendBuffer);

				if($this->enableCompression){
					$promise = $this->server->prepareBatch(new PacketBatch($stream->getBuffer()), $this->compressor, $syncMode, Timings::$playerNetworkSendCompressSessionBuffer);
				}else{
					$promise = new CompressBatchPromise();
					$promise->resolve($stream->getBuffer());
				}
				$this->sendBuffer = [];
				$this->queueCompressedNoBufferFlush($promise, $immediate);
			}finally{
				Timings::$playerNetworkSend->stopTiming();
			}
		}
	}

	public function getPacketSerializerContext() : PacketSerializerContext{ return $this->packetSerializerContext; }

	public function getBroadcaster() : PacketBroadcaster{ return $this->broadcaster; }

	public function getEntityEventBroadcaster() : EntityEventBroadcaster{ return $this->entityEventBroadcaster; }

	public function getCompressor() : Compressor{
		return $this->compressor;
	}

	public function queueCompressed(CompressBatchPromise $payload, bool $immediate = false) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			$this->flushSendBuffer($immediate); //Maintain ordering if possible
			$this->queueCompressedNoBufferFlush($payload, $immediate);
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	private function queueCompressedNoBufferFlush(CompressBatchPromise $payload, bool $immediate = false) : void{
		Timings::$playerNetworkSend->startTiming();
		try{
			if($immediate){
				//Skips all queues
				$this->sendEncoded($payload->getResult(), true);
			}else{
				$this->compressedQueue->enqueue($payload);
				$payload->onResolve(function(CompressBatchPromise $payload) : void{
					if($this->connected && $this->compressedQueue->bottom() === $payload){
						Timings::$playerNetworkSend->startTiming();
						try{
							$this->compressedQueue->dequeue(); //result unused
							$this->sendEncoded($payload->getResult());

							while(!$this->compressedQueue->isEmpty()){
								/** @var CompressBatchPromise $current */
								$current = $this->compressedQueue->bottom();
								if($current->hasResult()){
									$this->compressedQueue->dequeue();

									$this->sendEncoded($current->getResult());
								}else{
									//can't send any more queued until this one is ready
									break;
								}
							}
						}finally{
							Timings::$playerNetworkSend->stopTiming();
						}
					}
				});
			}
		}finally{
			Timings::$playerNetworkSend->stopTiming();
		}
	}

	private function sendEncoded(string $payload, bool $immediate = false) : void{
		if($this->cipher !== null){
			Timings::$playerNetworkSendEncrypt->startTiming();
			$payload = $this->cipher->encrypt($payload);
			Timings::$playerNetworkSendEncrypt->stopTiming();
		}
		$this->sender->send($payload, $immediate);
	}

	/**
	 * @phpstan-param \Closure() : void $func
	 */
	private function tryDisconnect(\Closure $func, string $reason) : void{
		if($this->connected && !$this->disconnectGuard){
			$this->disconnectGuard = true;
			$func();
			$this->disconnectGuard = false;
			$this->flushSendBuffer(true);
			$this->sender->close("");
			foreach($this->disposeHooks as $callback){
				$callback();
			}
			$this->disposeHooks->clear();
			$this->setHandler(null);
			$this->connected = false;
			$this->logger->info("Session closed due to $reason");
		}
	}

	/**
	 * Performs actions after the session has been disconnected. By this point, nothing should be interacting with the
	 * session, so it's safe to destroy any cycles and perform destructive cleanup.
	 */
	private function dispose() : void{
		$this->invManager = null;
	}

	/**
	 * Disconnects the session, destroying the associated player (if it exists).
	 */
	public function disconnect(string $reason, bool $notify = true) : void{
		$this->tryDisconnect(function() use ($reason, $notify) : void{
			if($notify){
				$this->sendDataPacket(DisconnectPacket::create(0, $reason));
			}
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	/**
	 * Instructs the remote client to connect to a different server.
	 */
	public function transfer(string $ip, int $port, string $reason = "transfer") : void{
		$this->tryDisconnect(function() use ($ip, $port, $reason) : void{
			$this->sendDataPacket(TransferPacket::create($ip, $port), true);
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	/**
	 * Called by the Player when it is closed (for example due to getting kicked).
	 */
	public function onPlayerDestroyed(string $reason) : void{
		$this->tryDisconnect(function() use ($reason) : void{
			$this->sendDataPacket(DisconnectPacket::create(0, $reason));
		}, $reason);
	}

	/**
	 * Called by the network interface to close the session when the client disconnects without server input, for
	 * example in a timeout condition or voluntary client disconnect.
	 */
	public function onClientDisconnect(string $reason) : void{
		$this->tryDisconnect(function() use ($reason) : void{
			if($this->player !== null){
				$this->player->onPostDisconnect($reason, null);
			}
		}, $reason);
	}

	private function setAuthenticationStatus(bool $authenticated, bool $authRequired, ?string $error, ?string $clientPubKey) : void{
		if(!$this->connected){
			return;
		}
		if($error === null){
			if($authenticated && !($this->info instanceof XboxLivePlayerInfo)){
				$error = "Expected XUID but none found";
			}elseif($clientPubKey === null){
				$error = "Missing client public key"; //failsafe
			}
		}

		if($error !== null && !$this->info instanceof XboxLivePlayerInfo){
			$this->disconnect($this->server->getLanguage()->translate(KnownTranslationFactory::pocketmine_disconnect_invalidSession($this->server->getLanguage()->translateString($error))));

			return;
		}

		$this->authenticated = $authenticated;

		if(!$this->authenticated && !$this->info instanceof XboxLivePlayerInfo){
			if($authRequired){
				$this->disconnect(KnownTranslationKeys::DISCONNECTIONSCREEN_NOTAUTHENTICATED);
				return;
			}
		}
		$this->logger->debug("Xbox Live authenticated: " . ($this->authenticated ? "YES" : "NO"));

		$checkXUID = $this->server->getConfigGroup()->getPropertyBool("player.verify-xuid", true);
		$myXUID = $this->info instanceof XboxLivePlayerInfo ? $this->info->getXuid() : "";
		$kickForXUIDMismatch = function(string $xuid) use ($checkXUID, $myXUID) : bool{
			if($checkXUID && $myXUID !== $xuid){
				$this->logger->debug("XUID mismatch: expected '$xuid', but got '$myXUID'");
				//TODO: Longer term, we should be identifying playerdata using something more reliable, like XUID or UUID.
				//However, that would be a very disruptive change, so this will serve as a stopgap for now.
				//Side note: this will also prevent offline players hijacking XBL playerdata on online servers, since their
				//XUID will always be empty.
				$this->disconnect("XUID does not match (possible impersonation attempt)");
				return true;
			}
			return false;
		};

		foreach($this->manager->getSessions() as $existingSession){
			if($existingSession === $this){
				continue;
			}
			$info = $existingSession->getPlayerInfo();
			if($info !== null && (strcasecmp($info->getUsername(), $this->info->getUsername()) === 0 || $info->getUuid()->equals($this->info->getUuid()))){
				if($kickForXUIDMismatch($info instanceof XboxLivePlayerInfo ? $info->getXuid() : "")){
					return;
				}
				$ev = new PlayerDuplicateLoginEvent($this, $existingSession);
				$ev->call();
				if($ev->isCancelled()){
					$this->disconnect($ev->getDisconnectMessage());
					return;
				}

				$existingSession->disconnect($ev->getDisconnectMessage());
			}
		}

		//TODO: make player data loading async
		//TODO: we shouldn't be loading player data here at all, but right now we don't have any choice :(
		$this->cachedOfflinePlayerData = $this->server->getOfflinePlayerData($this->info->getUsername());
		if($checkXUID){
			$recordedXUID = $this->cachedOfflinePlayerData !== null ? $this->cachedOfflinePlayerData->getTag(Player::TAG_LAST_KNOWN_XUID) : null;
			if(!($recordedXUID instanceof StringTag)){
				$this->logger->debug("No previous XUID recorded, no choice but to trust this player");
			}elseif(!$kickForXUIDMismatch($recordedXUID->getValue())){
				$this->logger->debug("XUID match");
			}
		}

		if(EncryptionContext::$ENABLED){
			$this->server->getAsyncPool()->submitTask(new PrepareEncryptionTask($clientPubKey, function(string $encryptionKey, string $handshakeJwt) : void{
				if(!$this->connected){
					return;
				}
				$this->sendDataPacket(ServerToClientHandshakePacket::create($handshakeJwt), true); //make sure this gets sent before encryption is enabled

				$this->cipher = EncryptionContext::fakeGCM($encryptionKey);

				$this->setHandler(new HandshakePacketHandler(function() : void{
					$this->onServerLoginSuccess();
				}));
				$this->logger->debug("Enabled encryption");
			}));
		}else{
			$this->onServerLoginSuccess();
		}
	}

	private function onServerLoginSuccess() : void{
		$this->loggedIn = true;

		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::LOGIN_SUCCESS));

		$this->logger->debug("Initiating resource packs phase");
		$this->setHandler(new ResourcePacksPacketHandler($this, $this->server->getResourcePackManager(), function() : void{
			$this->createPlayer();
		}));
	}

	private function beginSpawnSequence() : void{
		$this->setHandler(new PreSpawnPacketHandler($this->server, $this->player, $this, $this->invManager));
		$this->player->setImmobile(); //TODO: HACK: fix client-side falling pre-spawn

		$this->logger->debug("Waiting for chunk radius request");
	}

	public function notifyTerrainReady() : void{
		$this->logger->debug("Sending spawn notification, waiting for spawn response");
		$this->sendDataPacket(PlayStatusPacket::create(PlayStatusPacket::PLAYER_SPAWN));
		$this->setHandler(new SpawnResponsePacketHandler(function() : void{
			$this->onClientSpawnResponse();
		}));
	}

	private function onClientSpawnResponse() : void{
		$this->logger->debug("Received spawn response, entering in-game phase");
		$this->player->setImmobile(false); //TODO: HACK: we set this during the spawn sequence to prevent the client sending junk movements
		$this->player->doFirstSpawn();
		$this->forceAsyncCompression = false;
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function onServerDeath(Translatable|string $deathMessage) : void{
		if($this->handler instanceof InGamePacketHandler){ //TODO: this is a bad fix for pre-spawn death, this shouldn't be reachable at all at this stage :(
			$this->setHandler(new DeathPacketHandler($this->player, $this, $this->invManager ?? throw new AssumptionFailedError(), $deathMessage));
		}
	}

	public function onServerRespawn() : void{
		$this->entityEventBroadcaster->syncAttributes([$this], $this->player, $this->player->getAttributeMap()->getAll());
		$this->player->sendData(null);

		$this->syncAbilities($this->player);
		$this->invManager->syncAll();
		$this->setHandler(new InGamePacketHandler($this->player, $this, $this->invManager));
	}

	public function syncMovement(Vector3 $pos, ?float $yaw = null, ?float $pitch = null, int $mode = MovePlayerPacket::MODE_NORMAL) : void{
		if($this->player !== null){
			$location = $this->player->getLocation();
			$yaw = $yaw ?? $location->getYaw();
			$pitch = $pitch ?? $location->getPitch();

			$this->sendDataPacket(MovePlayerPacket::simple(
				$this->player->getId(),
				$this->player->getOffsetPosition($pos),
				$pitch,
				$yaw,
				$yaw, //TODO: head yaw
				$mode,
				$this->player->onGround,
				0, //TODO: riding entity ID
				0 //TODO: tick
			));

			if($this->handler instanceof InGamePacketHandler){
				$this->handler->forceMoveSync = true;
			}
		}
	}

	public function syncViewAreaRadius(int $distance) : void{
		$this->sendDataPacket(ChunkRadiusUpdatedPacket::create($distance));
	}

	public function syncViewAreaCenterPoint(Vector3 $newPos, int $viewDistance) : void{
		$this->sendDataPacket(NetworkChunkPublisherUpdatePacket::create(BlockPosition::fromVector3($newPos), $viewDistance * 16, [])); //blocks, not chunks >.>
	}

	public function syncPlayerSpawnPoint(Position $newSpawn) : void{
		$newSpawnBlockPosition = BlockPosition::fromVector3($newSpawn);
		//TODO: respawn causing block position (bed, respawn anchor)
		$this->sendDataPacket(SetSpawnPositionPacket::playerSpawn($newSpawnBlockPosition, DimensionIds::OVERWORLD, $newSpawnBlockPosition));
	}

	public function syncWorldSpawnPoint(Position $newSpawn) : void{
		$this->sendDataPacket(SetSpawnPositionPacket::worldSpawn(BlockPosition::fromVector3($newSpawn), DimensionIds::OVERWORLD));
	}

	public function syncGameMode(GameMode $mode, bool $isRollback = false) : void{
		$this->sendDataPacket(SetPlayerGameTypePacket::create(TypeConverter::getInstance()->coreGameModeToProtocol($mode)));
		if($this->player !== null){
			$this->syncAbilities($this->player);
			$this->syncAdventureSettings(); //TODO: we might be able to do this with the abilities packet alone
		}
		if(!$isRollback && $this->invManager !== null){
			$this->invManager->syncCreative();
		}
	}

	public function syncAbilities(Player $for) : void{
		$isOp = $for->hasPermission(DefaultPermissions::ROOT_OPERATOR);

		//ALL of these need to be set for the base layer, otherwise the client will cry
		$boolAbilities = [
			AbilitiesLayer::ABILITY_ALLOW_FLIGHT => $for->getAllowFlight(),
			AbilitiesLayer::ABILITY_FLYING => $for->isFlying(),
			AbilitiesLayer::ABILITY_NO_CLIP => !$for->hasBlockCollision(),
			AbilitiesLayer::ABILITY_OPERATOR => $isOp,
			AbilitiesLayer::ABILITY_TELEPORT => $for->hasPermission(DefaultPermissionNames::COMMAND_TELEPORT_SELF),
			AbilitiesLayer::ABILITY_INVULNERABLE => $for->isCreative(),
			AbilitiesLayer::ABILITY_MUTED => false,
			AbilitiesLayer::ABILITY_WORLD_BUILDER => false,
			AbilitiesLayer::ABILITY_INFINITE_RESOURCES => !$for->hasFiniteResources(),
			AbilitiesLayer::ABILITY_LIGHTNING => false,
			AbilitiesLayer::ABILITY_BUILD => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_MINE => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_DOORS_AND_SWITCHES => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_OPEN_CONTAINERS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_ATTACK_PLAYERS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_ATTACK_MOBS => !$for->isSpectator(),
			AbilitiesLayer::ABILITY_PRIVILEGED_BUILDER => false,
		];

		$layers = [
			//TODO: dynamic flying speed! FINALLY!!!!!!!!!!!!!!!!!
			new AbilitiesLayer(AbilitiesLayer::LAYER_BASE, $boolAbilities, 0.05, 0.1),
		];
		if(!$for->hasBlockCollision()){
			//TODO: HACK! In 1.19.80, the client starts falling in our faux spectator mode when it clips into a
			//block. We can't seem to prevent this short of forcing the player to always fly when block collision is
			//disabled. Also, for some reason the client always reads flight state from this layer if present, even
			//though the player isn't in spectator mode.

			$layers[] = new AbilitiesLayer(AbilitiesLayer::LAYER_SPECTATOR, [
				AbilitiesLayer::ABILITY_FLYING => true,
			], null, null);
		}

		$this->sendDataPacket(UpdateAbilitiesPacket::create(new AbilitiesData(
			$isOp ? CommandPermissions::OPERATOR : CommandPermissions::NORMAL,
			$isOp ? PlayerPermissions::OPERATOR : PlayerPermissions::MEMBER,
			$for->getId(),
			$layers
		)));
	}

	public function syncAdventureSettings() : void{
		if($this->player === null){
			throw new \LogicException("Cannot sync adventure settings for a player that is not yet created");
		}
		//everything except auto jump is handled via UpdateAbilitiesPacket
		$this->sendDataPacket(UpdateAdventureSettingsPacket::create(
			noAttackingMobs: false,
			noAttackingPlayers: false,
			worldImmutable: false,
			showNameTags: true,
			autoJump: $this->player->hasAutoJump()
		));
	}

	public function syncAvailableCommands() : void{
		$commandData = [];
		foreach($this->server->getCommandMap()->getCommands() as $name => $command){
			if(isset($commandData[$command->getLabel()]) || $command->getLabel() === "help" || !$command->testPermissionSilent($this->player)){
				continue;
			}

			$lname = strtolower($command->getLabel());
			$aliases = $command->getAliases();
			$aliasObj = null;
			if(count($aliases) > 0){
				if(!in_array($lname, $aliases, true)){
					//work around a client bug which makes the original name not show when aliases are used
					$aliases[] = $lname;
				}
				$aliasObj = new CommandEnum(ucfirst($command->getLabel()) . "Aliases", array_values($aliases));
			}

			$description = $command->getDescription();
			$data = new CommandData(
				$lname, //TODO: commands containing uppercase letters in the name crash 1.9.0 client
				$description instanceof Translatable ? $this->player->getLanguage()->translate($description) : $description,
				0,
				0,
				$aliasObj,
				[
					new CommandOverload(chaining: false, parameters: [CommandParameter::standard("args", AvailableCommandsPacket::ARG_TYPE_RAWTEXT, 0, true)])
				],
				chainedSubCommandData: []
			);

			$commandData[$command->getLabel()] = $data;
		}

		$this->sendDataPacket(AvailableCommandsPacket::create($commandData, [], [], []));
	}

	public function onChatMessage(Translatable|string $message) : void{
		if($message instanceof Translatable){
			$language = $this->player->getLanguage();
			if(!$this->server->isLanguageForced()){
				//we can't send nested translations to the client, so make sure they are always pre-translated by the server
				$parameters = array_map(fn(string|Translatable $p) => $p instanceof Translatable ? $language->translate($p) : $p, $message->getParameters());
				$this->sendDataPacket(TextPacket::translation($language->translateString($message->getText(), $parameters, "pocketmine."), $parameters));
			}else{
				$this->sendDataPacket(TextPacket::raw($language->translate($message)));
			}
		}else{
			$this->sendDataPacket(TextPacket::raw($message));
		}
	}

	/**
	 * @param string[] $parameters
	 */
	public function onJukeboxPopup(string $key, array $parameters) : void{
		$this->sendDataPacket(TextPacket::jukeboxPopup($key, $parameters));
	}

	public function onPopup(string $message) : void{
		$this->sendDataPacket(TextPacket::popup($message));
	}

	public function onTip(string $message) : void{
		$this->sendDataPacket(TextPacket::tip($message));
	}

	public function onFormSent(int $id, Form $form) : bool{
		return $this->sendDataPacket(ModalFormRequestPacket::create($id, json_encode($form, JSON_THROW_ON_ERROR)));
	}

	/**
	 * Instructs the networksession to start using the chunk at the given coordinates. This may occur asynchronously.
	 * @param \Closure $onCompletion To be called when chunk sending has completed.
	 * @phpstan-param \Closure() : void $onCompletion
	 */
	public function startUsingChunk(int $chunkX, int $chunkZ, \Closure $onCompletion) : void{
		$world = $this->player->getLocation()->getWorld();
		ChunkCache::getInstance($world, $this->compressor)->request($chunkX, $chunkZ)->onResolve(

			//this callback may be called synchronously or asynchronously, depending on whether the promise is resolved yet
			function(CompressBatchPromise $promise) use ($world, $onCompletion, $chunkX, $chunkZ) : void{
				if(!$this->isConnected()){
					return;
				}
				$currentWorld = $this->player->getLocation()->getWorld();
				if($world !== $currentWorld || ($status = $this->player->getUsedChunkStatus($chunkX, $chunkZ)) === null){
					$this->logger->debug("Tried to send no-longer-active chunk $chunkX $chunkZ in world " . $world->getFolderName());
					return;
				}
				if(!$status->equals(UsedChunkStatus::REQUESTED_SENDING())){
					//TODO: make this an error
					//this could be triggered due to the shitty way that chunk resends are handled
					//right now - not because of the spammy re-requesting, but because the chunk status reverts
					//to NEEDED if they want to be resent.
					return;
				}
				$world->timings->syncChunkSend->startTiming();
				try{
					$this->queueCompressed($promise);
					$onCompletion();
				}finally{
					$world->timings->syncChunkSend->stopTiming();
				}
			}
		);
	}

	public function stopUsingChunk(int $chunkX, int $chunkZ) : void{

	}

	public function onEnterWorld() : void{
		if($this->player !== null){
			$world = $this->player->getWorld();
			$this->syncWorldTime($world->getTime());
			$this->syncWorldDifficulty($world->getDifficulty());
			$this->syncWorldSpawnPoint($world->getSpawnLocation());
			//TODO: weather needs to be synced here (when implemented)
		}
	}

	public function syncWorldTime(int $worldTime) : void{
		$this->sendDataPacket(SetTimePacket::create($worldTime));
	}

	public function syncWorldDifficulty(int $worldDifficulty) : void{
		$this->sendDataPacket(SetDifficultyPacket::create($worldDifficulty));
	}

	public function getInvManager() : ?InventoryManager{
		return $this->invManager;
	}

	/**
	 * @param Player[] $players
	 */
	public function syncPlayerList(array $players) : void{
		$this->sendDataPacket(PlayerListPacket::add(array_map(function(Player $player) : PlayerListEntry{
			return PlayerListEntry::createAdditionEntry($player->getUniqueId(), $player->getId(), $player->getDisplayName(), SkinAdapterSingleton::get()->toSkinData($player->getSkin()), $player->getXuid());
		}, $players)));
	}

	public function onPlayerAdded(Player $p) : void{
		$this->sendDataPacket(PlayerListPacket::add([PlayerListEntry::createAdditionEntry($p->getUniqueId(), $p->getId(), $p->getDisplayName(), SkinAdapterSingleton::get()->toSkinData($p->getSkin()), $p->getXuid())]));
	}

	public function onPlayerRemoved(Player $p) : void{
		if($p !== $this->player){
			$this->sendDataPacket(PlayerListPacket::remove([PlayerListEntry::createRemovalEntry($p->getUniqueId())]));
		}
	}

	public function onTitle(string $title) : void{
		$this->sendDataPacket(SetTitlePacket::title($title));
	}

	public function onSubTitle(string $subtitle) : void{
		$this->sendDataPacket(SetTitlePacket::subtitle($subtitle));
	}

	public function onActionBar(string $actionBar) : void{
		$this->sendDataPacket(SetTitlePacket::actionBarMessage($actionBar));
	}

	public function onClearTitle() : void{
		$this->sendDataPacket(SetTitlePacket::clearTitle());
	}

	public function onResetTitleOptions() : void{
		$this->sendDataPacket(SetTitlePacket::resetTitleOptions());
	}

	public function onTitleDuration(int $fadeIn, int $stay, int $fadeOut) : void{
		$this->sendDataPacket(SetTitlePacket::setAnimationTimes($fadeIn, $stay, $fadeOut));
	}

	public function onToastNotification(string $title, string $body) : void{
		$this->sendDataPacket(ToastRequestPacket::create($title, $body));
	}

	public function onOpenSignEditor(Vector3 $signPosition, bool $frontSide) : void{
		$this->sendDataPacket(OpenSignPacket::create(BlockPosition::fromVector3($signPosition), $frontSide));
	}

	public function tick() : void{
		if(!$this->isConnected()){
			$this->dispose();
			return;
		}

		if($this->info === null){
			if(time() >= $this->connectTime + 10){
				$this->disconnect("Login timeout");
			}

			return;
		}

		if($this->player !== null){
			$this->player->doChunkRequests();

			$dirtyAttributes = $this->player->getAttributeMap()->needSend();
			$this->entityEventBroadcaster->syncAttributes([$this], $this->player, $dirtyAttributes);
			foreach($dirtyAttributes as $attribute){
				//TODO: we might need to send these to other players in the future
				//if that happens, this will need to become more complex than a flag on the attribute itself
				$attribute->markSynchronized();
			}
		}
		Timings::$playerNetworkSendInventorySync->startTiming();
		try{
			$this->invManager?->flushPendingUpdates();
		}finally{
			Timings::$playerNetworkSendInventorySync->stopTiming();
		}

		$this->flushSendBuffer();
	}
}

<?php

declare(strict_types=1);

namespace pocketmine\command\defaults;

use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\utils\TextFormat;

final class SetViewDistanceCommand extends VanillaCommand {

	/**
	 * @param CommandSender $sender
	 * @param string        $commandLabel
	 * @param string[]      $args
	 */
	public function execute(CommandSender $sender, string $commandLabel, array $args): void {
		if (count($args) <= 0) {
			$sender->sendMessage(TextFormat::RED . 'Usage: /' . $commandLabel . ' <distance>');

			return;
		}

		if (!is_numeric($args[0])) {
			$sender->sendMessage(TextFormat::RED . 'Please provide a valid format');

			return;
		}

		Server::getInstance()->getConfigGroup()->setConfigInt("view-distance", intval($args[0]));
		Server::getInstance()->getConfigGroup()->save();

		foreach (Server::getInstance()->getOnlinePlayers() as $target) $target->setViewDistance(intval($args[0]));

		Server::getInstance()->broadcastMessage(TextFormat::GREEN . 'The server view distance was changed to ' . $args[0]);
	}
}
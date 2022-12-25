<?php

declare(strict_types=1);

namespace pocketmine\event\server;

final class ServerPreventCrashEvent extends ServerEvent {

	/**
	 * @param string $who
	 * @param int    $attempts
	 */
	public function __construct(
		private string $who,
		private int $attempts
	) {}

	/**
	 * @return string
	 */
	public function getWho(): string {
		return $this->who;
	}

	/**
	 * @return int
	 */
	public function getAttempts(): int {
		return $this->attempts;
	}
}
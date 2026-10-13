<?php

declare(strict_types=1);

namespace muqsit\simplepackethandler\utils;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\DataPacket;

interface PacketInterceptorHandler{

	public function intercept(DataPacket $packet, NetworkSession $session) : bool;
}

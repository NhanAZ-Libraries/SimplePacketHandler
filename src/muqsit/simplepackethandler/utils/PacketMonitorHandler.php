<?php

declare(strict_types=1);

namespace muqsit\simplepackethandler\utils;

use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\DataPacket;

interface PacketMonitorHandler{

	public function monitor(DataPacket $packet, NetworkSession $session) : void;
}

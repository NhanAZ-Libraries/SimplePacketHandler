<?php

declare(strict_types=1);

namespace muqsit\simplepackethandler\utils;

use Closure;
use LogicException;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\DataPacket;
use function call_user_func;
use function is_a;
use function is_bool;

final class TypedPacketInterceptorHandler implements PacketInterceptorHandler{

	private readonly Closure $handler;

	/**
	 * @param list<non-empty-list<string>> $type_groups
	 * @param Closure $handler
	 */
	public function __construct(private readonly array $type_groups, Closure $handler){
		$this->handler = $handler;
	}

	public function intercept(DataPacket $packet, NetworkSession $session) : bool{
		if(!$this->matches($packet)){
			return true;
		}
		$result = call_user_func($this->handler, $packet, $session);
		if(!is_bool($result)){
			throw new LogicException("Packet interceptor must return bool");
		}
		return $result;
	}

	private function matches(DataPacket $packet) : bool{
		foreach($this->type_groups as $group){
			foreach($group as $type){
				if(!is_a($packet, $type)){
					continue 2;
				}
			}
			return true;
		}
		return false;
	}
}

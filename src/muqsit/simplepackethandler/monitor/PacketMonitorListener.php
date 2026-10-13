<?php

declare(strict_types=1);

namespace muqsit\simplepackethandler\monitor;

use Closure;
use muqsit\simplepackethandler\utils\Utils;
use muqsit\simplepackethandler\utils\PacketMonitorHandler;
use muqsit\simplepackethandler\utils\TypedPacketMonitorHandler;
use pocketmine\event\EventPriority;
use pocketmine\event\HandlerListManager;
use pocketmine\event\RegisteredListener;
use pocketmine\event\server\DataPacketDecodeEvent;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\server\DataPacketSendEvent;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\Packet;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\plugin\Plugin;
use pocketmine\Server;
use function count;
use function spl_object_id;

final class PacketMonitorListener implements IPacketMonitor{

	/** @var RegisteredListener<DataPacketReceiveEvent>|null */
	private ?RegisteredListener $incoming_event_handler = null;

	/** @var RegisteredListener<DataPacketSendEvent>|null */
	private ?RegisteredListener $outgoing_event_handler = null;

	/** @var RegisteredListener<DataPacketDecodeEvent>|null */
	private ?RegisteredListener $decode_event_handler = null;

	/** @var array<int, array<int, PacketMonitorHandler>> */
	private array $incoming_handlers = [];

	/** @var array<int, array<int, PacketMonitorHandler>> */
	private array $outgoing_handlers = [];

	public function __construct(
		readonly private Plugin $register,
		readonly private PacketPool $pool,
		readonly private bool $handle_cancelled
	){}

	/**
	 * @template TPacket of Packet
	 * @template UPacket of TPacket
	 * @param Closure(UPacket, NetworkSession) : void $handler
	 * @param class-string<TPacket> $class
	 * @return array{pids: non-empty-list<int>, type_groups: list<non-empty-list<string>>}
	 */
	private function parseHandlerData(Closure $handler, string $class) : array{
		$classes = Utils::parseClosureSignature($handler, [$class, NetworkSession::class], "void");
		return [
			"pids" => Utils::flattenPacketPidsFromGroups($this->pool, $classes[0]),
			"type_groups" => $classes[0]
		];
	}

	private function onStateChange() : void{
		if(count($this->incoming_handlers) > 0 || count($this->outgoing_handlers) > 0){
			$this->decode_event_handler ??= Server::getInstance()->getPluginManager()->registerEvent(DataPacketDecodeEvent::class, function(DataPacketDecodeEvent $event) : void{
				$pid = $event->getPacketId();
				if(isset($this->incoming_handlers[$pid]) || isset($this->outgoing_handlers[$pid])){
					$event->uncancel();
				}
			}, EventPriority::NORMAL, $this->register, true);
		}elseif($this->decode_event_handler !== null){
			HandlerListManager::global()->getListFor(DataPacketDecodeEvent::class)->unregister($this->decode_event_handler);
			$this->decode_event_handler = null;
		}
	}

	public function monitorIncoming(Closure $handler) : IPacketMonitor{
		$registration = $this->parseHandlerData($handler, ServerboundPacket::class);
		$packet_handler = new TypedPacketMonitorHandler($registration["type_groups"], $handler);
		foreach($registration["pids"] as $pid){
			$this->incoming_handlers[$pid][spl_object_id($handler)] = $packet_handler;
		}
		$this->incoming_event_handler ??= Server::getInstance()->getPluginManager()->registerEvent(DataPacketReceiveEvent::class, function(DataPacketReceiveEvent $event) : void{
			/** @var DataPacket&ServerboundPacket $packet */
			$packet = $event->getPacket();
			if(isset($this->incoming_handlers[$pid = $packet->pid()])){
				$origin = $event->getOrigin();
				foreach($this->incoming_handlers[$pid] as $handler){
					$handler->monitor($packet, $origin);
				}
			}
		}, EventPriority::MONITOR, $this->register, $this->handle_cancelled);
		$this->onStateChange();
		return $this;
	}

	public function monitorOutgoing(Closure $handler) : IPacketMonitor{
		$registration = $this->parseHandlerData($handler, ClientboundPacket::class);
		$packet_handler = new TypedPacketMonitorHandler($registration["type_groups"], $handler);
		foreach($registration["pids"] as $pid){
			$this->outgoing_handlers[$pid][spl_object_id($handler)] = $packet_handler;
		}
		$this->outgoing_event_handler ??= Server::getInstance()->getPluginManager()->registerEvent(DataPacketSendEvent::class, function(DataPacketSendEvent $event) : void{
			/** @var DataPacket&ClientboundPacket $packet */
			foreach($event->getPackets() as $packet){
				if(isset($this->outgoing_handlers[$pid = $packet->pid()])){
					foreach($event->getTargets() as $target){
						foreach($this->outgoing_handlers[$pid] as $handler){
							$handler->monitor($packet, $target);
						}
					}
				}
			}
		}, EventPriority::MONITOR, $this->register, $this->handle_cancelled);
		$this->onStateChange();
		return $this;
	}

	public function unregisterIncomingMonitor(Closure $handler) : IPacketMonitor{
		$hid = spl_object_id($handler);
		foreach($this->parseHandlerData($handler, ServerboundPacket::class)["pids"] as $pid){
			if(isset($this->incoming_handlers[$pid][$hid])){
				unset($this->incoming_handlers[$pid][$hid]);
				if(count($this->incoming_handlers[$pid]) === 0){
					unset($this->incoming_handlers[$pid]);
					if(count($this->incoming_handlers) === 0){
						if($this->incoming_event_handler !== null){
							HandlerListManager::global()->getListFor(DataPacketReceiveEvent::class)->unregister($this->incoming_event_handler);
						}
						$this->incoming_event_handler = null;
					}
				}
			}
		}
		$this->onStateChange();
		return $this;
	}

	public function unregisterOutgoingMonitor(Closure $handler) : IPacketMonitor{
		$hid = spl_object_id($handler);
		foreach($this->parseHandlerData($handler, ClientboundPacket::class)["pids"] as $pid){
			if(isset($this->outgoing_handlers[$pid][$hid])){
				unset($this->outgoing_handlers[$pid][$hid]);
				if(count($this->outgoing_handlers[$pid]) === 0){
					unset($this->outgoing_handlers[$pid]);
					if(count($this->outgoing_handlers) === 0){
						if($this->outgoing_event_handler !== null){
							HandlerListManager::global()->getListFor(DataPacketSendEvent::class)->unregister($this->outgoing_event_handler);
						}
						$this->outgoing_event_handler = null;
					}
				}
			}
		}
		$this->onStateChange();
		return $this;
	}
}

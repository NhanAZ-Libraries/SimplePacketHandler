<?php

declare(strict_types=1);

use muqsit\simplepackethandler\utils\Utils;
use muqsit\simplepackethandler\utils\TypedPacketInterceptorHandler;
use muqsit\simplepackethandler\utils\TypedPacketMonitorHandler;
use PHPUnit\Framework\TestCase;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\network\mcpe\protocol\SetTimePacket;
use pocketmine\network\mcpe\protocol\TextPacket;

final class PacketIdResolutionTest extends TestCase{

	public function test_single_packet_signature_resolves_to_registered_network_id() : void{
		$handler = static function(TextPacket $packet, NetworkSession $session) : void{};
		$groups = Utils::parseClosureSignature($handler, [ServerboundPacket::class, NetworkSession::class], "void");

		self::assertSame(
			[TextPacket::NETWORK_ID],
			Utils::flattenPacketPidsFromGroups(PacketPool::getInstance(), $groups[0])
		);
	}

	public function test_union_signature_resolves_each_registered_packet_id_once() : void{
		$handler = static function(TextPacket|SetTimePacket $packet, NetworkSession $session) : void{};
		$groups = Utils::parseClosureSignature($handler, [ClientboundPacket::class, NetworkSession::class], "void");
		$actual = Utils::flattenPacketPidsFromGroups(PacketPool::getInstance(), $groups[0]);
		$expected = [TextPacket::NETWORK_ID, SetTimePacket::NETWORK_ID];
		sort($actual);
		sort($expected);

		self::assertSame($expected, $actual);
	}

	public function test_intersection_signature_matches_packet_and_direction_types() : void{
		$handler = static function(TextPacket&ClientboundPacket $packet, NetworkSession $session) : void{};
		$groups = Utils::parseClosureSignature($handler, [ClientboundPacket::class, NetworkSession::class], "void");

		self::assertSame(
			[TextPacket::NETWORK_ID],
			Utils::flattenPacketPidsFromGroups(PacketPool::getInstance(), $groups[0])
		);
	}

	public function test_packet_id_method_matches_the_pool_registration_key() : void{
		$packet = PacketPool::getInstance()->getPacketById(TextPacket::NETWORK_ID);

		self::assertInstanceOf(TextPacket::class, $packet);
		self::assertSame(TextPacket::NETWORK_ID, $packet->pid());
	}

	public function test_typed_dispatchers_ignore_packets_outside_the_callback_type() : void{
		$session = (new \ReflectionClass(NetworkSession::class))->newInstanceWithoutConstructor();
		$pool = PacketPool::getInstance();
		$matching_packet = $pool->getPacketById(TextPacket::NETWORK_ID);
		$other_packet = $pool->getPacketById(SetTimePacket::NETWORK_ID);
		self::assertInstanceOf(TextPacket::class, $matching_packet);
		self::assertInstanceOf(SetTimePacket::class, $other_packet);

		$interceptor = new TypedPacketInterceptorHandler(
			[[TextPacket::class]],
			static fn(TextPacket $packet, NetworkSession $session) : bool => false
		);
		self::assertTrue($interceptor->intercept($other_packet, $session));
		self::assertFalse($interceptor->intercept($matching_packet, $session));

		$monitor = new TypedPacketMonitorHandler(
			[[TextPacket::class]],
			static function(TextPacket $packet, NetworkSession $session) : void{
				throw new \RuntimeException("Matching packet reached its monitor");
			}
		);
		$monitor->monitor($other_packet, $session);
		$this->expectException(RuntimeException::class);
		$monitor->monitor($matching_packet, $session);
	}
}

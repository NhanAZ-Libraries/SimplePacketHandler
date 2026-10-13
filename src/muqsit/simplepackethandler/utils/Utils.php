<?php

declare(strict_types=1);

namespace muqsit\simplepackethandler\utils;

use Closure;
use InvalidArgumentException;
use pocketmine\network\mcpe\NetworkSession;
use pocketmine\network\mcpe\protocol\ClientboundPacket;
use pocketmine\network\mcpe\protocol\DataPacket;
use pocketmine\network\mcpe\protocol\PacketPool;
use pocketmine\network\mcpe\protocol\ServerboundPacket;
use pocketmine\utils\Utils as PocketMineUtils;
use ReflectionFunction;
use ReflectionIntersectionType;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionType;
use ReflectionUnionType;
use function count;
use function get_class;
use function implode;
use function in_array;
use function is_a;

final class Utils{

	/**
	 * Parses a closure and validates it against $params and $return_type. Returns a type group list for
	 * each parameter.
	 * fn(A1, B1&B2) : bool, [ABase::class, BBase::class], "bool" = [[[A1]], [[B1, B2]]]
	 *
	 * @param Closure $closure
	 * @param list<string> $params
	 * @param string $return_type
	 * @return list<non-empty-list<non-empty-list<string>>>
	 */
	public static function parseClosureSignature(Closure $closure, array $params, string $return_type) : array{
		/** @noinspection PhpUnhandledExceptionInspection */
		$method = new ReflectionFunction($closure);
		$type = $method->getReturnType();
		if(!($type instanceof ReflectionNamedType) || $type->allowsNull() || $type->getName() !== $return_type){
			throw new InvalidArgumentException("Return value of " . PocketMineUtils::getNiceClosureName($closure) . " must be {$return_type}");
		}

		$parsed_params = [];
		$parameters = $method->getParameters();
		count($parameters) === count($params) || throw new InvalidArgumentException("Method " . PocketMineUtils::getNiceClosureName($closure) . " must accept exactly " . count($params) . " parameters");

		$parameter_index = 0;
		foreach($parameters as $index => $parameter){
			$parameter_type = $parameter->getType();
			$parameter_compare = $params[$parameter_index++];
			$parameter_type !== null || throw new InvalidArgumentException("Argument " . ($index + 1) . " of " . PocketMineUtils::getNiceClosureName($closure) . " must accept a value of type {$parameter_compare}");
			!$parameter_type->allowsNull() || throw new InvalidArgumentException("Argument " . ($index + 1) . " of " . PocketMineUtils::getNiceClosureName($closure) . " must not be nullable");
			$names = self::groupTypes($parameter_type);
			foreach($names as $group){
				foreach($group as $name){
					self::isCompatibleParameterType($name, $parameter_compare) || throw new InvalidArgumentException("Parameter {$name} in " . PocketMineUtils::getNiceClosureName($closure) . " does not satisfy type {$parameter_compare}");
				}
			}
			$parsed_params[] = $names;
		}

		count($parsed_params) === count($params) || throw new InvalidArgumentException(PocketMineUtils::getNiceClosureName($closure) . " must satisfy signature (" . implode(", ", $params) . ") : {$return_type}");
		return $parsed_params;
	}

	private static function isCompatibleParameterType(string $type, string $expected) : bool{
		return match($expected){
			ServerboundPacket::class => is_a($type, ServerboundPacket::class, true),
			ClientboundPacket::class => is_a($type, ClientboundPacket::class, true),
			NetworkSession::class => is_a($type, NetworkSession::class, true),
			default => $type === $expected
		};
	}

	/**
	 * Groups composite type names based on the kind of join.
	 * A = [[A]]
	 * A|B = [[A], [B]]
	 * (A*C)|(B*C)|D = [[A, C], [B, C], [D]]
	 *
	 * @param ReflectionType $type
	 * @return non-empty-list<non-empty-list<string>>
	 */
	public static function groupTypes(ReflectionType $type) : array{
		$result = [];
		$stack = [[$type, 0]];
		$offset = 0;
		while(isset($stack[$offset])){
			[$type, $index] = $stack[$offset++];
			if($type instanceof ReflectionNamedType){ // perform the frequent case first
				$result[$index][] = $type->getName();
			}elseif($type instanceof ReflectionUnionType){
				foreach($type->getTypes() as $child){
					$index = count($result);
					$result[$index] = [];
					$stack[] = [$child, $index];
				}
			}elseif($type instanceof ReflectionIntersectionType){
				$result[$index] = [];
				foreach($type->getTypes() as $child){
					$stack[] = [$child, $index];
				}
			}else{
				throw new InvalidArgumentException("Don't know how to resolve " . $type . "(" . get_class($type) . ")");
			}
		}
		/** @var non-empty-list<non-empty-list<string>> $result */
		return $result;
	}

	/**
	 * @param PacketPool $pool
	 * @param non-empty-list<non-empty-list<string>> $groups
	 * @return non-empty-list<int>
	 */
	public static function flattenPacketPidsFromGroups(PacketPool $pool, array $groups) : array{
		$network_ids = [];
		$packets = (new ReflectionProperty($pool, "pool"))->getValue($pool);
		$packets instanceof \SplFixedArray || throw new InvalidArgumentException("Packet pool must expose a SplFixedArray-backed packet registry");
		foreach($groups as $group){
			$found = 0;
			foreach($packets as $pid => $packet){
				if(!$packet instanceof DataPacket){
					continue;
				}
				foreach($group as $validate){
					if(!is_a($packet, $validate)){
						continue 2;
					}
				}
				if(!in_array($pid, $network_ids, true)){
					$network_ids[] = $pid;
				}
				$found++;
			}
			$found > 0 || throw new InvalidArgumentException("No packets match criteria " . implode("&", $group));
		}
		/** @var non-empty-list<int> $network_ids */
		return $network_ids;
	}
}

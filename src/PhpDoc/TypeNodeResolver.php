<?php declare(strict_types = 1);

namespace PHPStan\PhpDoc;

use PHPStan\Analyser\NameScope;
use PHPStan\Broker\Broker;
use PHPStan\PhpDocParser\Ast\Type\ArrayTypeNode;
use PHPStan\PhpDocParser\Ast\Type\GenericTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IdentifierTypeNode;
use PHPStan\PhpDocParser\Ast\Type\IntersectionTypeNode;
use PHPStan\PhpDocParser\Ast\Type\NullableTypeNode;
use PHPStan\PhpDocParser\Ast\Type\ThisTypeNode;
use PHPStan\PhpDocParser\Ast\Type\TypeNode;
use PHPStan\PhpDocParser\Ast\Type\UnionTypeNode;
use PHPStan\Type\ArrayType;
use PHPStan\Type\BooleanType;
use PHPStan\Type\CallableType;
use PHPStan\Type\Constant\ConstantBooleanType;
use PHPStan\Type\ErrorType;
use PHPStan\Type\FloatType;
use PHPStan\Type\IntegerType;
use PHPStan\Type\IntersectionType;
use PHPStan\Type\IterableType;
use PHPStan\Type\MixedType;
use PHPStan\Type\NonexistentParentClassType;
use PHPStan\Type\NullType;
use PHPStan\Type\ObjectType;
use PHPStan\Type\ObjectWithoutClassType;
use PHPStan\Type\ResourceType;
use PHPStan\Type\StaticType;
use PHPStan\Type\StringType;
use PHPStan\Type\ThisType;
use PHPStan\Type\Type;
use PHPStan\Type\TypeCombinator;
use PHPStan\Type\TypeWithClassName;
use PHPStan\Type\UnionType;
use PHPStan\Type\VoidType;

class TypeNodeResolver
{

	public function resolve(TypeNode $typeNode, NameScope $nameScope): Type
	{
		if ($typeNode instanceof IdentifierTypeNode) {
			return $this->resolveIdentifierTypeNode($typeNode, $nameScope);

		} elseif ($typeNode instanceof ThisTypeNode) {
			return $this->resolveThisTypeNode($typeNode, $nameScope);

		} elseif ($typeNode instanceof NullableTypeNode) {
			return $this->resolveNullableTypeNode($typeNode, $nameScope);

		} elseif ($typeNode instanceof UnionTypeNode) {
			return $this->resolveUnionTypeNode($typeNode, $nameScope);

		} elseif ($typeNode instanceof IntersectionTypeNode) {
			return $this->resolveIntersectionTypeNode($typeNode, $nameScope);

		} elseif ($typeNode instanceof ArrayTypeNode) {
			return $this->resolveArrayTypeNode($typeNode, $nameScope);

		} elseif ($typeNode instanceof GenericTypeNode) {
			return $this->resolveGenericTypeNode($typeNode, $nameScope);
		}

		return new ErrorType();
	}

	protected function resolveIdentifierTypeNode(IdentifierTypeNode $typeNode, NameScope $nameScope): Type
	{
		switch (strtolower($typeNode->name)) {
			case 'int':
			case 'integer':
				return new IntegerType();

			case 'string':
				return new StringType();

			case 'bool':
			case 'boolean':
				return new BooleanType();

			case 'true':
				return new ConstantBooleanType(true);

			case 'false':
				return new ConstantBooleanType(false);

			case 'null':
				return new NullType();

			case 'float':
			case 'double':
				return new FloatType();

			case 'array':
				return new ArrayType(new MixedType(true), new MixedType(true));

			case 'scalar':
				return new UnionType([
					new IntegerType(),
					new FloatType(),
					new StringType(),
					new BooleanType(),
				]);

			case 'number':
				return new UnionType([
					new IntegerType(),
					new FloatType(),
				]);

			case 'iterable':
				return new IterableType(new MixedType(true), new MixedType(true));

			case 'callable':
				return new CallableType();

			case 'resource':
				return new ResourceType();

			case 'mixed':
				return new MixedType(true);

			case 'void':
				return new VoidType();

			case 'object':
				return new ObjectWithoutClassType();
		}

		if ($nameScope->getClassName() !== null) {
			switch (strtolower($typeNode->name)) {
				case 'self':
					return new ObjectType($nameScope->getClassName());

				case 'static':
					return new StaticType($nameScope->getClassName());

				case 'parent':
					$broker = Broker::getInstance();
					if ($broker->hasClass($nameScope->getClassName())) {
						$classReflection = $broker->getClass($nameScope->getClassName());
						if ($classReflection->getParentClass() !== false) {
							return new ObjectType($classReflection->getParentClass()->getName());
						}
					}

					return new NonexistentParentClassType();
			}
		}

		return new ObjectType($nameScope->resolveStringName($typeNode->name));
	}

	protected function resolveThisTypeNode(ThisTypeNode $typeNode, NameScope $nameScope): Type
	{
		if ($nameScope->getClassName() !== null) {
			return new ThisType($nameScope->getClassName());
		}

		return new ErrorType();
	}

	protected function resolveNullableTypeNode(NullableTypeNode $typeNode, NameScope $nameScope): Type
	{
		return TypeCombinator::addNull($this->resolve($typeNode->type, $nameScope));
	}

	protected function resolveUnionTypeNode(UnionTypeNode $typeNode, NameScope $nameScope): Type
	{
		$iterableTypeNodes = [];
		$otherTypeNodes = [];

		foreach ($typeNode->types as $innerTypeNode) {
			if ($innerTypeNode instanceof ArrayTypeNode) {
				$iterableTypeNodes[] = $innerTypeNode->type;
			} else {
				$otherTypeNodes[] = $innerTypeNode;
			}
		}

		$otherTypeTypes = $this->resolveMultiple($otherTypeNodes, $nameScope);

		if (count($otherTypeTypes) === 2) {
			static $mockClassNames = [
				'PHPUnit_Framework_MockObject_MockObject' => true,
				'PHPUnit\Framework\MockObject\MockObject' => true,
			];

			foreach ($otherTypeTypes as $otherType) {
				if ($otherType instanceof TypeWithClassName && isset($mockClassNames[$otherType->getClassName()])) {
					return TypeCombinator::intersect(...$otherTypeTypes);
				}
			}
		}

		if (count($iterableTypeNodes) > 0) {
			$arrayTypeTypes = $this->resolveMultiple($iterableTypeNodes, $nameScope);
			$arrayTypeType = TypeCombinator::union(...$arrayTypeTypes);
			$addArray = true;

			foreach ($otherTypeTypes as &$type) {
				if (!$type->isIterable()->yes() || !$type->getIterableValueType()->isSuperTypeOf($arrayTypeType)->yes()) {
					continue;
				}

				if ($type instanceof ObjectType) {
					$type = new IntersectionType([$type, new IterableType(new MixedType(), $arrayTypeType)]);
				} elseif ($type instanceof ArrayType) {
					$type = new ArrayType(new MixedType(), $arrayTypeType);
				} elseif ($type instanceof IterableType) {
					$type = new IterableType(new MixedType(), $arrayTypeType);
				} else {
					continue;
				}

				$addArray = false;
			}

			if ($addArray) {
				$otherTypeTypes[] = new ArrayType(new MixedType(), $arrayTypeType);
			}
		}

		return TypeCombinator::union(...$otherTypeTypes);
	}

	protected function resolveIntersectionTypeNode(IntersectionTypeNode $typeNode, NameScope $nameScope): Type
	{
		$types = $this->resolveMultiple($typeNode->types, $nameScope);
		return TypeCombinator::intersect(...$types);
	}

	protected function resolveArrayTypeNode(ArrayTypeNode $typeNode, NameScope $nameScope): Type
	{
		$itemType = $this->resolve($typeNode->type, $nameScope);
		return new ArrayType(new MixedType(), $itemType);
	}

	protected function resolveGenericTypeNode(GenericTypeNode $typeNode, NameScope $nameScope): Type
	{
		$mainType = strtolower($typeNode->type->name);
		$genericTypes = $this->resolveMultiple($typeNode->genericTypes, $nameScope);

		if ($mainType === 'array') {
			if (count($genericTypes) === 1) { // array<ValueType>
				return new ArrayType(new MixedType(true), $genericTypes[0]);

			}

			if (count($genericTypes) === 2) { // array<KeyType, ValueType>
				return new ArrayType($genericTypes[0], $genericTypes[1]);
			}

		} elseif ($mainType === 'iterable') {
			if (count($genericTypes) === 1) { // iterable<ValueType>
				return new IterableType(new MixedType(true), $genericTypes[0]);

			}

			if (count($genericTypes) === 2) { // iterable<KeyType, ValueType>
				return new IterableType($genericTypes[0], $genericTypes[1]);
			}
		}

		return new ErrorType();
	}

	/**
	 * @param TypeNode[] $typeNodes
	 * @param NameScope $nameScope
	 * @return Type[]
	 */
	protected function resolveMultiple(array $typeNodes, NameScope $nameScope): array
	{
		$types = [];
		foreach ($typeNodes as $typeNode) {
			$types[] = $this->resolve($typeNode, $nameScope);
		}

		return $types;
	}

}

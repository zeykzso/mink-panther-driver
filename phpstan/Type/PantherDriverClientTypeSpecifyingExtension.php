<?php

declare(strict_types=1);

namespace Lctrs\MinkPantherDriver\PHPStan\Type;

use Lctrs\MinkPantherDriver\PantherDriver;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PHPStan\Analyser\Scope;
use PHPStan\Analyser\SpecifiedTypes;
use PHPStan\Analyser\TypeSpecifier;
use PHPStan\Analyser\TypeSpecifierAwareExtension;
use PHPStan\Analyser\TypeSpecifierContext;
use PHPStan\Reflection\MethodReflection;
use PHPStan\Type\MethodTypeSpecifyingExtension;
use PHPStan\Type\TypeCombinator;

/**
 * @internal
 */
final class PantherDriverClientTypeSpecifyingExtension implements MethodTypeSpecifyingExtension, TypeSpecifierAwareExtension
{
    /** @var TypeSpecifier */
    private $typeSpecifier;

    public function setTypeSpecifier(TypeSpecifier $typeSpecifier) : void
    {
        $this->typeSpecifier = $typeSpecifier;
    }

    public function getClass() : string
    {
        return PantherDriver::class;
    }

    public function isMethodSupported(MethodReflection $methodReflection, MethodCall $node, TypeSpecifierContext $context) : bool
    {
        return $methodReflection->getName() === 'ensureClientIsStarted';
    }

    public function specifyTypes(MethodReflection $methodReflection, MethodCall $node, Scope $scope, TypeSpecifierContext $context) : SpecifiedTypes
    {
        $expr = new PropertyFetch(new Variable('this'), 'client');

        return $this->typeSpecifier->create(
            $expr,
            TypeCombinator::removeNull($scope->getType($expr)),
            TypeSpecifierContext::createTruthy()
        );
    }
}

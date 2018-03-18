<?php

namespace Maks3w\PhpUnitMethodsTrait;

use Zend\Code\Generator\DocBlock\Tag\AbstractTypeableTag;
use Zend\Code\Generator\DocBlockGenerator;
use Zend\Code\Generator\FileGenerator;
use Zend\Code\Generator\MethodGenerator;
use Zend\Code\Generator\ParameterGenerator;
use Zend\Code\Generator\TraitGenerator;
use Zend\Code\Reflection\ClassReflection;
use Zend\Code\Reflection\MethodReflection;

/**
 * class with useful methods for placeholders checks.
 */
class Compiler
{
    /**
     * @var string
     */
    private $inputClass;

    /**
     * @var TraitGenerator
     */
    private $basicTrait;

    /**
     * @var string[]
     */
    private $blackListMethodNames;

    /**
     * @var string Trait namespace.
     */
    private $traitNamespace;

    /**
     * @param string $inputClass
     * @param string $traitNamespace Trait namespace.
     * @param string[] $blackListMethodNames List of methods to exclude from the result
     */
    public function __construct($inputClass, $traitNamespace, array $blackListMethodNames = [])
    {
        $this->inputClass = $inputClass;
        $this->traitNamespace = $traitNamespace;
        $this->blackListMethodNames = array_merge(
            [
                '__construct',
            ],
            $blackListMethodNames
        );
        $this->basicTrait = $this->process($inputClass);
    }

    public function exportAsAbstractMethods()
    {
        $file = new FileGenerator();
        $file->setClass($this->basicTrait);

        return $file->generate();
    }

    /**
     * @param string $class
     *
     * @return TraitGenerator
     */
    protected function process($class)
    {
        $classReflection = new ClassReflection($class);

        // Build trait name
        $traitName = $classReflection->getName();
        $delimiter = null;
        if (strpos($traitName, '_') !== false) {
            $delimiter = '_';
        }elseif (strpos($traitName, '\\') !== false) {
            $delimiter = '\\';
        }

        if ($delimiter) {
            $traitNameParts = explode($delimiter, $traitName);
            $traitName = array_pop($traitNameParts);
        }

        $traitName .= 'Trait';

        // Create trait from original class
        $traitGenerator = new TraitGenerator($traitName, $this->traitNamespace);

        foreach ($classReflection->getMethods() as $reflectionMethod) {
            if (
                $reflectionMethod->isPrivate() ||
                $reflectionMethod->isStatic() ||
                $this->methodIsBlackListed($reflectionMethod->getName()) ||
                !$reflectionMethod->getBody()
            ) {
                continue;
            }

            $method = $this->methodGeneratorFromReflection($reflectionMethod);
            if ($method->getDocBlock()) {
                $this->importTypesPresentInDocBlock($method->getDocBlock(), $traitGenerator);
            }

            $traitGenerator->addMethodFromGenerator($method);
        }

        return $traitGenerator;
    }

    /**
     * @param  MethodReflection $reflectionMethod
     *
     * @return MethodGenerator
     */
    protected function methodGeneratorFromReflection(MethodReflection $reflectionMethod)
    {
        $method = new MethodGenerator();

        $method->setSourceContent($reflectionMethod->getContents(false));
        $method->setSourceDirty(false);

        if ($reflectionMethod->getDocComment() != '') {
            $method->setDocBlock(DocBlockGenerator::fromReflection($reflectionMethod->getDocBlock()));
        }

        $method->setAbstract(true);
        $method->setFinal($reflectionMethod->isFinal());

        if ($reflectionMethod->isPrivate()) {
            $method->setVisibility(MethodGenerator::VISIBILITY_PRIVATE);
        } elseif ($reflectionMethod->isProtected()) {
            $method->setVisibility(MethodGenerator::VISIBILITY_PROTECTED);
        } else {
            $method->setVisibility(MethodGenerator::VISIBILITY_PUBLIC);
        }

        $method->setStatic($reflectionMethod->isStatic());

        $method->setName($reflectionMethod->getName());

        foreach ($reflectionMethod->getParameters() as $reflectionParameter) {
            $method->setParameter(ParameterGenerator::fromReflection($reflectionParameter));
        }

        return $method;
    }

    /**
     * @param DocBlockGenerator $docBlock
     * @param TraitGenerator $traitGenerator
     */
    protected function importTypesPresentInDocBlock(DocBlockGenerator $docBlock, TraitGenerator $traitGenerator)
    {
        foreach ($docBlock->getTags() as $tag) {
            if (!($tag instanceof AbstractTypeableTag)) {
                continue;
            }

            foreach ($tag->getTypes() as $type) {
                // Type is in global namespace
                if ($type[0] === '\\') {
                    continue;
                }

                if ($this->isPhpDocType($type)) {
                    continue;
                }

                $traitGenerator->addUse($type);
            }
        }
    }

    /**
     * Indicate if method name is present in the black list.
     *
     * @param string $methodName
     *
     * @return bool
     */
    protected function methodIsBlackListed($methodName)
    {
        return in_array($methodName, $this->blackListMethodNames, true);
    }

    /**
     * @param string $type
     *
     * @return bool
     */
    protected function isPhpDocType($type)
    {
        $type = trim($type, '[]');

        switch (strtolower($type)) {
            case '$this':
            case 'array':
            case 'bool':
            case 'boolean':
            case 'callable':
            case 'false':
            case 'float':
            case 'int':
            case 'integer':
            case 'mixed':
            case 'null':
            case 'object':
            case 'resource':
            case 'self':
            case 'static':
            case 'string':
            case 'true':
            case 'void':
                return true;
            default:
                return false;
        }
    }
}

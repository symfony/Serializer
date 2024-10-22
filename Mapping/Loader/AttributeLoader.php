<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfony\Component\Serializer\Mapping\Loader;

use Symfony\Component\Serializer\Attribute\Context;
use Symfony\Component\Serializer\Attribute\DiscriminatorMap;
use Symfony\Component\Serializer\Attribute\Groups;
use Symfony\Component\Serializer\Attribute\Ignore;
use Symfony\Component\Serializer\Attribute\MaxDepth;
use Symfony\Component\Serializer\Attribute\SerializedName;
use Symfony\Component\Serializer\Attribute\SerializedPath;
use Symfony\Component\Serializer\Exception\MappingException;
use Symfony\Component\Serializer\Mapping\AttributeMetadata;
use Symfony\Component\Serializer\Mapping\AttributeMetadataInterface;
use Symfony\Component\Serializer\Mapping\ClassDiscriminatorMapping;
use Symfony\Component\Serializer\Mapping\ClassMetadataInterface;

/**
 * Loader for PHP attributes.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 * @author Alexander M. Turek <me@derrabus.de>
 * @author Alexandre Daubois <alex.daubois@gmail.com>
 */
class AttributeLoader implements LoaderInterface
{
    private const KNOWN_ATTRIBUTES = [
        DiscriminatorMap::class,
        Groups::class,
        Ignore::class,
        MaxDepth::class,
        SerializedName::class,
        SerializedPath::class,
        Context::class,
    ];

    public function __construct()
    {
    }

    public function loadClassMetadata(ClassMetadataInterface $classMetadata): bool
    {
        $reflectionClass = $classMetadata->getReflectionClass();
        $className = $reflectionClass->name;
        $loaded = false;
        $classGroups = [];
        $classContextAnnotation = null;

        $attributesMetadata = $classMetadata->getAttributesMetadata();

        foreach ($this->loadAttributes($reflectionClass) as $annotation) {
            match (true) {
                $annotation instanceof DiscriminatorMap => $classMetadata->setClassDiscriminatorMapping(new ClassDiscriminatorMapping($annotation->getTypeProperty(), $annotation->getMapping())),
                $annotation instanceof Groups => $classGroups = $annotation->getGroups(),
                $annotation instanceof Context => $classContextAnnotation = $annotation,
                default => null,
            };
        }

        foreach ($reflectionClass->getProperties() as $property) {
            if (!isset($attributesMetadata[$property->name])) {
                $attributesMetadata[$property->name] = new AttributeMetadata($property->name);
                $classMetadata->addAttributeMetadata($attributesMetadata[$property->name]);
            }

            $attributeMetadata = $attributesMetadata[$property->name];
            if ($property->getDeclaringClass()->name === $className) {
                if ($classContextAnnotation) {
                    $this->setAttributeContextsForGroups($classContextAnnotation, $attributeMetadata);
                }

                foreach ($classGroups as $group) {
                    $attributeMetadata->addGroup($group);
                }

                foreach ($this->loadAttributes($property) as $annotation) {
                    $loaded = true;

                    if ($annotation instanceof Groups) {
                        foreach ($annotation->getGroups() as $group) {
                            $attributeMetadata->addGroup($group);
                        }

                        continue;
                    }

                    match (true) {
                        $annotation instanceof MaxDepth => $attributeMetadata->setMaxDepth($annotation->getMaxDepth()),
                        $annotation instanceof SerializedName => $attributeMetadata->setSerializedName($annotation->getSerializedName()),
                        $annotation instanceof SerializedPath => $attributeMetadata->setSerializedPath($annotation->getSerializedPath()),
                        $annotation instanceof Ignore => $attributeMetadata->setIgnore(true),
                        $annotation instanceof Context => $this->setAttributeContextsForGroups($annotation, $attributeMetadata),
                        default => null,
                    };
                }
            }
        }

        foreach ($reflectionClass->getMethods() as $method) {
            if ($method->getDeclaringClass()->name !== $className) {
                continue;
            }

            if (0 === stripos($method->name, 'get') && $method->getNumberOfRequiredParameters()) {
                continue; /*  matches the BC behavior in `Symfony\Component\Serializer\Normalizer\ObjectNormalizer::extractAttributes` */
            }

            $accessorOrMutator = preg_match('/^(get|is|has|set)(.+)$/i', $method->name, $matches);
            if ($accessorOrMutator) {
                $attributeName = lcfirst($matches[2]);

                if (isset($attributesMetadata[$attributeName])) {
                    $attributeMetadata = $attributesMetadata[$attributeName];
                } else {
                    $attributesMetadata[$attributeName] = $attributeMetadata = new AttributeMetadata($attributeName);
                    $classMetadata->addAttributeMetadata($attributeMetadata);
                }
            }

            foreach ($this->loadAttributes($method) as $annotation) {
                if ($annotation instanceof Groups) {
                    if (!$accessorOrMutator) {
                        throw new MappingException(\sprintf('Groups on "%s::%s()" cannot be added. Groups can only be added on methods beginning with "get", "is", "has" or "set".', $className, $method->name));
                    }

                    foreach ($annotation->getGroups() as $group) {
                        $attributeMetadata->addGroup($group);
                    }
                } elseif ($annotation instanceof MaxDepth) {
                    if (!$accessorOrMutator) {
                        throw new MappingException(\sprintf('MaxDepth on "%s::%s()" cannot be added. MaxDepth can only be added on methods beginning with "get", "is", "has" or "set".', $className, $method->name));
                    }

                    $attributeMetadata->setMaxDepth($annotation->getMaxDepth());
                } elseif ($annotation instanceof SerializedName) {
                    if (!$accessorOrMutator) {
                        throw new MappingException(\sprintf('SerializedName on "%s::%s()" cannot be added. SerializedName can only be added on methods beginning with "get", "is", "has" or "set".', $className, $method->name));
                    }

                    $attributeMetadata->setSerializedName($annotation->getSerializedName());
                } elseif ($annotation instanceof SerializedPath) {
                    if (!$accessorOrMutator) {
                        throw new MappingException(\sprintf('SerializedPath on "%s::%s()" cannot be added. SerializedPath can only be added on methods beginning with "get", "is", "has" or "set".', $className, $method->name));
                    }

                    $attributeMetadata->setSerializedPath($annotation->getSerializedPath());
                } elseif ($annotation instanceof Ignore) {
                    if ($accessorOrMutator) {
                        $attributeMetadata->setIgnore(true);
                    }
                } elseif ($annotation instanceof Context) {
                    if (!$accessorOrMutator) {
                        throw new MappingException(\sprintf('Context on "%s::%s()" cannot be added. Context can only be added on methods beginning with "get", "is", "has" or "set".', $className, $method->name));
                    }

                    $this->setAttributeContextsForGroups($annotation, $attributeMetadata);
                }

                $loaded = true;
            }
        }

        return $loaded;
    }

    private function loadAttributes(\ReflectionMethod|\ReflectionClass|\ReflectionProperty $reflector): iterable
    {
        foreach ($reflector->getAttributes() as $attribute) {
            if ($this->isKnownAttribute($attribute->getName())) {
                try {
                    yield $attribute->newInstance();
                } catch (\Error $e) {
                    if (\Error::class !== $e::class) {
                        throw $e;
                    }
                    $on = match (true) {
                        $reflector instanceof \ReflectionClass => ' on class '.$reflector->name,
                        $reflector instanceof \ReflectionMethod => \sprintf(' on "%s::%s()"', $reflector->getDeclaringClass()->name, $reflector->name),
                        $reflector instanceof \ReflectionProperty => \sprintf(' on "%s::$%s"', $reflector->getDeclaringClass()->name, $reflector->name),
                        default => '',
                    };

                    throw new MappingException(\sprintf('Could not instantiate attribute "%s"%s.', $attribute->getName(), $on), 0, $e);
                }
            }
        }
    }

    private function setAttributeContextsForGroups(Context $annotation, AttributeMetadataInterface $attributeMetadata): void
    {
        $context = $annotation->getContext();
        $groups = $annotation->getGroups();
        $normalizationContext = $annotation->getNormalizationContext();
        $denormalizationContext = $annotation->getDenormalizationContext();

        if ($normalizationContext || $context) {
            $attributeMetadata->setNormalizationContextForGroups($normalizationContext ?: $context, $groups);
        }

        if ($denormalizationContext || $context) {
            $attributeMetadata->setDenormalizationContextForGroups($denormalizationContext ?: $context, $groups);
        }
    }

    private function isKnownAttribute(string $attributeName): bool
    {
        foreach (self::KNOWN_ATTRIBUTES as $knownAttribute) {
            if (is_a($attributeName, $knownAttribute, true)) {
                return true;
            }
        }

        return false;
    }
}

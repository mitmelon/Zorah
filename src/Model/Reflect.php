<?php
namespace Manomite\Model;

use \ReflectionClass;
use \ReflectionMethod;
use \ReflectionException;
use \Exception;

class Reflect
{
    private $instance = null;
    private $className;
    private $reflector = null;

    public function __construct(string $className, array $constructorArgs = [])
    {
        $this->className = $this->resolveClassName($className);
        
        try {
            $this->reflector = new ReflectionClass($this->className);
            if (!$this->reflector->isInstantiable()) {
                throw new Exception("Class '{$this->className}' is not instantiable (it may be abstract or an interface).");
            }
            
            if (!empty($constructorArgs)) {
                $this->instance = $this->reflector->newInstanceArgs($constructorArgs);
            } else {
                $this->instance = $this->reflector->newInstance();
            }
            
        } catch (ReflectionException $e) {
            throw new Exception("Failed to reflect class '{$this->className}': " . $e->getMessage());
        } catch (Exception $e) {
            throw new Exception("Failed to instantiate class '{$this->className}': " . $e->getMessage());
        }
    }

    private function resolveClassName(string $className): string
    {
        if (strpos($className, '\\') !== false) {
            return $className;
        }
        
        return __NAMESPACE__ . '\\' . $className;
    }

    public function __call(string $method, array $arguments)
    {
        try {
            if (!method_exists($this->instance, $method)) {
                throw new Exception("Method '{$method}' does not exist in class '{$this->className}'.");
            }
            
            $reflectionMethod = new ReflectionMethod($this->instance, $method);
            if (!$reflectionMethod->isPublic()) {
                throw new Exception("Method '{$method}' in class '{$this->className}' is not public.");
            }
            
            return call_user_func_array([$this->instance, $method], $arguments);
            
        } catch (ReflectionException $e) {
            throw new Exception("Failed to call method '{$method}' on class '{$this->className}': " . $e->getMessage());
        }
    }

    public function getInstance(): object
    {
        return $this->instance;
    }

    public function getMethods(): array
    {
        if (!$this->reflector) {
            return [];
        }
        
        $methods = $this->reflector->getMethods(ReflectionMethod::IS_PUBLIC);
        $methodNames = [];
        
        foreach ($methods as $method) {
            // Exclude magic methods and constructor
            if (!$method->isConstructor() && strpos($method->getName(), '__') !== 0) {
                $methodNames[] = $method->getName();
            }
        }
        
        return $methodNames;
    }

    public function getMethodInfo(string $methodName): array
    {
        try {
            $method = new ReflectionMethod($this->instance, $methodName);
            
            $parameters = [];
            foreach ($method->getParameters() as $param) {
                $paramInfo = [
                    'name' => $param->getName(),
                    'type' => $param->getType() ? $param->getType()->getName() : 'mixed',
                    'optional' => $param->isOptional(),
                    'default' => $param->isDefaultValueAvailable() ? $param->getDefaultValue() : null
                ];
                $parameters[] = $paramInfo;
            }
            
            return [
                'name' => $method->getName(),
                'parameters' => $parameters,
                'return_type' => $method->getReturnType() ? $method->getReturnType()->getName() : 'mixed',
                'is_static' => $method->isStatic(),
                'is_public' => $method->isPublic(),
                'doc_comment' => $method->getDocComment()
            ];
            
        } catch (ReflectionException $e) {
            throw new Exception("Method '{$methodName}' does not exist in class '{$this->className}'.");
        }
    }

    public function hasMethod(string $methodName): bool
    {
        if (!method_exists($this->instance, $methodName)) {
            return false;
        }
        
        try {
            $method = new ReflectionMethod($this->instance, $methodName);
            return $method->isPublic();
        } catch (ReflectionException $e) {
            return false;
        }
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function getProperties(int $filter = \ReflectionProperty::IS_PUBLIC): array
    {
        if (!$this->reflector) {
            return [];
        }
        
        $properties = $this->reflector->getProperties($filter);
        $propertyNames = [];
        
        foreach ($properties as $property) {
            $propertyNames[] = $property->getName();
        }
        
        return $propertyNames;
    }

    public function getProperty(string $propertyName)
    {
        try {
            $property = $this->reflector->getProperty($propertyName);
            
            if (!$property->isPublic()) {
                $property->setAccessible(true);
            }
            
            return $property->getValue($this->instance);
            
        } catch (ReflectionException $e) {
            throw new Exception("Property '{$propertyName}' does not exist in class '{$this->className}'.");
        }
    }

    public function setProperty(string $propertyName, $value): void
    {
        try {
            $property = $this->reflector->getProperty($propertyName);
            
            if (!$property->isPublic()) {
                $property->setAccessible(true);
            }
            
            $property->setValue($this->instance, $value);
            
        } catch (ReflectionException $e) {
            throw new Exception("Property '{$propertyName}' does not exist in class '{$this->className}'.");
        }
    }

    public function __get(string $name)
    {
        return $this->getProperty($name);
    }

    public function __set(string $name, $value): void
    {
        $this->setProperty($name, $value);
    }

    public function __isset(string $name): bool
    {
        try {
            $this->reflector->getProperty($name);
            return true;
        } catch (ReflectionException $e) {
            return false;
        }
    }

    public static function create(string $className, array $constructorArgs = []): self
    {
        return new self($className, $constructorArgs);
    }

    public function __toString(): string
    {
        return "Reflect[{$this->className}]";
    }
}

#!/usr/bin/env php
<?php
set_exception_handler(function (Exception $e) {
    echo $e->getMessage() . "\n";
});
if (version_compare(PHP_VERSION, '5.4', '<')) {
    throw new RuntimeException('php version requires >=5.4');
}

if (!extension_loaded('reflection')) {
    throw new RuntimeException('requires reflection extension');
}

if ($argc == 1) {
    exit(sprintf("Usage: %s EXTENSION_NAME\n", pathinfo(__FILE__, PATHINFO_BASENAME)));
}

$extension = $argv[1];
if (!extension_loaded($extension)) {
    throw new RuntimeException(sprintf('extension %s not found', $extension));
}

$result    = "<?php\n";
$ref       = new ReflectionExtension($extension);
$constants = $ref->getConstants();
foreach ($constants as $name => $value) {
    $str = getConstantStr($name, $value);
    $result .= "$str\n";
}

$functions = $ref->getFunctions();
foreach ($functions as $func) {
    $params      = $func->getParameters();
    $paramStrArr = array();
    foreach ($params as $param) {
        $paramStrArr[] = getParamStr($param);
    }
    $result .= sprintf("function %s(%s){}\n", $func->getName(), join(', ', $paramStrArr));
}

$classes = $ref->getClasses();
foreach ($classes as $class) {
    $str         = '';
    $parentClass = null;
    if ($class->inNamespace()) {
        $str .= 'namespace ' . $class->getNamespaceName() . "\n";
    }
    if ($class->isTrait()) {
        $title = 'trait ' . $class->getName();
    } else if ($class->isInterface()) {
        $title = 'interface ' . $class->getName();
    } else {
        $modifiers   = Reflection::getModifierNames($class->getModifiers());
        $title       = trim(join(' ', $modifiers) . ' class ' . $class->getName());
        $parentClass = $class->getParentClass();
        if ($parentClass && !$parentClass->isInterface()) {
            $title .= ' extends ' . $parentClass->getName();
        }
        $implements = $class->getInterfaceNames();
        if (!empty($implements)) {
            $title .= ' implements ' . join(', ', $implements);
        }
    }
    $str .= $title . " {\n";
    $constants = $class->getConstants();
    foreach ($constants as $name => $value) {
        $str .= "\t" . getConstantStr($name, $value) . "\n";
    }

    $defaultProperties = $class->getDefaultProperties();
    foreach ($class->getProperties(ReflectionProperty::IS_PUBLIC | ReflectionProperty::IS_PROTECTED) as $property) {
        if (!isInheritProperty($property, $parentClass)) {
            $str .= "\t" . getPropertyStr($property, $defaultProperties) . "\n";
        }
    }

    foreach ($class->getMethods(ReflectionMethod::IS_PUBLIC | ReflectionMethod::IS_PROTECTED) as $method) {
        if (!isInheritMethod($method, $parentClass)) {
            $str .= "\t" . getMethodStr($method, $class->isInterface()) . "\n";
        }
    }
    $str .= "}\n";
    $result .= $str;
}

$dir  = dirname(__FILE__) . '/output/';
$file = $dir . $extension . '.php';
if (is_writable($dir)) {
    file_put_contents($file, $result);
    echo "save result to {$file}\n";
} else {
    throw new RuntimeException(sprintf('directory %s is not writable', $dir));
}

function getConstantStr($name, $value) {
    $value = var_export($value, true);
    return "const {$name} = {$value};";
}

function getParamStr(ReflectionParameter $param) {
    $name = $param->getName();
    //support variable-length argument lists
    if ($name == '...') {
        return '$_ = null';
    }
    $str = '$' . $name;
    if ($param->isPassedByReference()) {
        $str = '&' . $str;
    }

    if ($param->isArray()) {
        $str = 'array ' . $str;
    } else if ($param->isCallable()) {
        $str = 'callable ' . $str;
    }

    //it's impossible to get default value of build-in function's parameter, just for forward compatible
    if ($param->isOptional() && $param->isDefaultValueAvailable()) {
        if ($param->isDefaultValueConstant()) {
            $default = $param->getDefaultValueConstantName();
        } else {
            $default = $param->getDefaultValue();
        }
        $str .= ' = ' . $default;
    }

    return $str;
}

function getMethodStr(ReflectionMethod $method, $isInterface = false) {
    $modifiers = Reflection::getModifierNames($method->getModifiers());
    if ($isInterface) {
        foreach ($modifiers as $k => $modifier) {
            if (strtolower($modifier) == 'abstract') {
                unset($modifiers[$k]);
            }
        }
    }
    $str         = join(' ', $modifiers) . ' function ' . $method->getName();
    $params      = $method->getParameters();
    $paramStrArr = array();
    foreach ($params as $param) {
        $paramStrArr[] = getParamStr($param);
    }
    $str .= '(' . join(', ', $paramStrArr) . ')';
    if ($method->isAbstract()) {
        $str .= ';';
    } else {
        $str .= '{}';
    }
    return $str;
}

function getPropertyStr(ReflectionProperty $property, $defaultProperties) {
    $name      = $property->getName();
    $modifiers = Reflection::getModifierNames($property->getModifiers());
    $str       = join(' ', $modifiers) . ' $' . $name;
    if (isset($defaultProperties[$name]) && !is_null($defaultProperties[$name])) {
        $str .= ' = ' . var_export($defaultProperties[$name], true);
    }
    return $str . ';';
}

function isInheritProperty(ReflectionProperty $property, $parentClass = null) {
    /* @var ReflectionClass $parentClass */
    if (!$parentClass) {
        return false;
    }
    if ($parentClass->isInterface()) {
        return false;
    }
    $propertyName = $property->getName();
    if (!$parentClass->hasProperty($propertyName)) {
        return false;
    }
    return true;
}

function isInheritMethod(ReflectionMethod $method, $parentClass = null) {
    /* @var ReflectionClass $parentClass */
    if (!$parentClass) {
        return false;
    }
    if ($parentClass->isInterface()) {
        return false;
    }

    $methodName = $method->getName();
    if (!$parentClass->hasMethod($methodName)) {
        return false;
    }

    $parentMethod = $parentClass->getMethod($methodName);
    if ($parentMethod->isAbstract()) {
        return false;
    }

    if ($parentMethod->isFinal()) {
        return true;
    }
    return true;
}
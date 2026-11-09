# AOT Compiler Class Inheritance Rules

## 📋 Overview

The AOT (Ahead-Of-Time) compiler performs strict checks on class inheritance relationships during the compilation stage. Unlike traditional PHP runtime checking, the AOT compiler requires that the existence of all parent classes and interfaces can be determined at **compile time**.

---

## ⚠️ Core Rules

### Rule 1: Can Only Inherit Existing Classes

```php
// ✅ Correct: inheriting a PHP built-in class
class MyException extends Exception {}

// ✅ Correct: inheriting a class defined in the same project
class BaseClass {}
class ChildClass extends BaseClass {}

// ❌ Wrong: inheriting a non-existent class
class MyClass extends NonExistentClass {}
// Compile error: Class `MyClass` inherits from a non-existent class `NonExistentClass`
```

### Rule 2: Cannot Inherit Classes Loaded at Runtime by autoload

```php
// ❌ Wrong: a class that depends on autoload
class MyClass extends ExternalLibrary\BaseClass {}
// If BaseClass needs to be loaded by autoload at runtime, compilation will fail

// ✅ Correct: use an interface instead
interface BaseInterface {}
class MyClass implements BaseInterface {}
```

### Rule 3: Native Classes and Plain Zend Classes Cannot Inherit from Each Other

```php
// ✅ Correct: inheriting a Zend internal class (detected via
// Reflection::isInternalClass at compile time)
class MyArray extends ArrayObject {}
class MyDb extends PDO {}

// ❌ Wrong: a Native class inheriting from a Zend class (or vice versa)
// class MyNative extends SomeZendClass {}   // Native cannot extend Zend
// class MyZend extends SomeNativeClass {}   // Zend cannot extend Native
// Compile error: Native and ZendVM-backed classes cannot inherit from each other
```

---

## 🔍 Compile-Time Checking Mechanism

### Checking Process

1. **Lexical analysis stage**
   - Parses the `extends` and `implements` keywords
   - Extracts the names of the parent class and interfaces

2. **Symbol table construction**
   - Collects all classes defined in the current file
   - Queries the PHP built-in class list
   - Verifies the legality of the inheritance relationship

3. **Error reporting**
   - Reports an error immediately when illegal inheritance is found
   - Provides detailed error location and reason

### Checking Code Location

The relevant implementation is in `src/Translator.php` (inheritance checks) and
`src/Preprocessor.php` (internal-class detection):

```php
// src/Translator.php — compile-time inheritance checks (v0.9.5)
if ($this->classDef->extends and !$this->classDef->inheritedFromInternalClass) {
    $parentClass = $this->getNamespacedClassName($this->parseIdentifier($class->extends));
    if ($this->hasClass($parentClass)) {
        $parent = $this->getClass($parentClass);
        if ($this->classDef->nativeObject !== $parent->nativeObject) {
            $this->fatalError($class,
                'Native and ZendVM-backed classes cannot inherit from each other');
        }
        if ($parent->flags & Modifiers::FINAL) {
            $this->fatalError($class, "Class `{$this->class}` cannot extend final class `{$parentClass}`");
        }
        $this->assertReadonlyInheritanceContract($class, $parentClass, ...);
    } else {
        $this->fatalError($class, "Class `{$this->class}` inherits from a non-existent class `{$parentClass}`");
    }
}
// src/Preprocessor.php — internal-class detection: when the parent is a
// built-in class, inheritedFromInternalClass is set and only the readonly
// contract is checked against the host runtime's reflection. Inheriting
// built-in classes is allowed.
```

---

## 🎯 Legal Inheritance Examples

### Example 1: Inheriting a Class Defined in the Project

```php
<?php
// Define the base class
abstract class Shape {
    protected float $area = 0.0;
    
    abstract public function calculateArea(): float;
    
    public function getArea(): float {
        return $this->area;
    }
}

// Inherit the base class (✅ allowed)
class Circle extends Shape {
    private float $radius;
    
    public function __construct(float $radius) {
        $this->radius = $radius;
    }
    
    public function calculateArea(): float {
        $this->area = M_PI * $this->radius ** 2;
        return $this->area;
    }
}

function main() {
    $circle = new Circle(5);
    echo "Circle area: " . $circle->getArea() . "\n";
}
```

### Example 2: Implementing Interfaces

```php
<?php
// Define the interfaces
interface Renderable {
    public function render(): string;
}

interface Serializable {
    public function serialize(): string;
}

// Implement multiple interfaces (✅ allowed)
class Product implements Renderable, Serializable {
    private string $name;
    private float $price;
    
    public function __construct(string $name, float $price) {
        $this->name = $name;
        $this->price = $price;
    }
    
    public function render(): string {
        return "<div>{$this->name} - \${$this->price}</div>";
    }
    
    public function serialize(): string {
        return json_encode(['name' => $this->name, 'price' => $this->price]);
    }
}

function main() {
    $product = new Product('Widget', 19.99);
    echo $product->render() . "\n";
    echo $product->serialize() . "\n";
}
```

### Example 3: Multi-Level Inheritance

```php
<?php
// Base controller
abstract class Controller {
    protected array $data = [];
    
    public function setData(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }
    
    abstract public function execute(): void;
}

// Abstract user controller
abstract class UserController extends Controller {
    protected ?string $userId = null;
    
    public function setUserId(string $id): void {
        $this->userId = $id;
    }
}

// Concrete user list controller (✅ allowed)
class UserListController extends UserController {
    private array $users = [];
    
    public function execute(): void {
        echo "User List for ID: {$this->userId}\n";
        foreach ($this->users as $user) {
            echo " - {$user}\n";
        }
    }
}

function main() {
    $controller = new UserListController();
    $controller->setUserId('123');
    $controller->execute();
}
```

---

## ❌ Illegal Inheritance Examples

### Example 1: Inheriting a Non-Existent Class

```php
<?php
// ❌ Wrong: NonExistentBase is not defined
class MyClass extends NonExistentBase {
    // ...
}

function main() {
    $obj = new MyClass();
}
?>
```

**Compile error**:
```
Fatal error: Class `MyClass` inherits from a non-existent class `NonExistentBase` 
in /path/to/file.php on line X
```

**Solution**:
```php
<?php
// ✅ Define the base class first
class NonExistentBase {
    // ...
}

class MyClass extends NonExistentBase {
    // ...
}
```

### Example 2: An External Class That Depends on autoload

```php
<?php
// ❌ Wrong: ExternalLib\BaseClass needs to be loaded by autoload
use ExternalLib\BaseClass;

class MyService extends BaseClass {
    // ...
}

function main() {
    $service = new MyService();
}
?>
```

**Compile error**:
```
Fatal error: Class `MyService` inherits from a non-existent class `ExternalLib\BaseClass`
in /path/to/file.php on line X
```

**Solution**:

**Option A - Use an interface**:
```php
<?php
interface BaseServiceInterface {
    public function process(): mixed;
}

class MyService implements BaseServiceInterface {
    public function process(): mixed {
        // Implementation logic
    }
}
```

**Option B - Use composition**:
```php
<?php
use ExternalLib\BaseClass;

class MyService {
    private BaseClass $base;
    
    public function __construct() {
        $this->base = new BaseClass();
    }
    
    public function process(): mixed {
        return $this->base->doSomething();
    }
}
```

### Example 3: A Native Class Inheriting from a Zend Class

```php
<?php
// ❌ Wrong: a Zend class inheriting from a Native class (or the reverse)
class MyNative extends SomeZendClass {}

function main() {
    $obj = new MyNative();
}
?>
```

**Compile error**:
```
Fatal error: Native and ZendVM-backed classes cannot inherit from each other
in /path/to/file.php on line X
```

**Note**: inheriting Zend internal classes (such as `PDO` or `ArrayObject`) is
**allowed**; composition remains a recommended style but is not enforced by the
compiler.

```php
<?php
// ✅ Composition pattern (recommended style, not enforced)
class MyDatabase {
    private PDO $connection;
    
    public function __construct(string $dsn) {
        $this->connection = new PDO($dsn);
    }
    
    public function query(string $sql): array {
        return $this->connection->query($sql)->fetchAll();
    }
}
```

---

## 💡 Best Practices

### 1. Prefer Interfaces over Inheritance

```php
// ❌ Not recommended: deep inheritance chain
class A {}
class B extends A {}
class C extends B {}
class D extends C {}

// ✅ Recommended: use interfaces
interface ServiceInterface {}
class UserService implements ServiceInterface {}
class ProductService implements ServiceInterface {}
```

### 2. Use Composition Instead of Inheritance

```php
// ❌ Not recommended: inheriting an internal class
class MyCollection extends ArrayCollection {
    // ...
}

// ✅ Recommended: the composition pattern
class Collection {
    private ArrayCollection $items;
    
    public function __construct() {
        $this->items = new ArrayCollection();
    }
    
    public function add(mixed $item): void {
        $this->items->add($item);
    }
}
```

### 3. Define Inheritable Base Classes Within the Project

```php
<?php
// base.stub.php - define an inheritable base class

abstract class BaseController {
    protected array $middlewares = [];
    
    abstract public function handle(): void;
    
    protected function middleware(string $name): void {
        echo "Running middleware: {$name}\n";
    }
}

// api.php - inherit the base class within the project

class UserController extends BaseController {
    public function handle(): void {
        $this->middleware('auth');
        echo "Handling user request\n";
    }
}

function main() {
    $controller = new UserController();
    $controller->handle();
}
```

### 4. Avoid Circular Dependencies

```php
// ❌ Wrong: circular inheritance causes compilation to fail
// file1.php
class A extends B {}

// file2.php  
class B extends A {}

// ✅ Correct: one-way inheritance
// base.php
class Base {}

// child.php
class Child extends Base {}
```

---

## 🔧 Troubleshooting

### Problem 1: Compilation Reports "non-existent class"

**Symptom**:
```
Fatal error: Class `MyClass` inherits from a non-existent class `ParentClass`
```

**Diagnostic steps**:
1. Check whether `ParentClass` is defined in the compilation unit
2. Confirm there are no spelling errors
3. Verify that the class namespace is correct

**Solution**:
```php
// Ensure the parent class is defined before the subclass or in the same file
class ParentClass {}
class MyClass extends ParentClass {}
```

### Problem 2: Cannot Use a Third-Party Library Class as a Parent Class

**Symptom**:
Need to use the functionality of an external library, but cannot inherit it directly

**Solution**:

**Method A - Adapter pattern**:
```php
class ExternalAdapter {
    private ExternalClass $external;
    
    public function __construct() {
        $this->external = new ExternalClass();
    }
    
    public function doWork(): mixed {
        return $this->external->execute();
    }
}
```

**Method B - Delegation pattern**:
```php
class Wrapper {
    public function __call(string $name, array $args): mixed {
        $external = new ExternalClass();
        return call_user_func_array([$external, $name], $args);
    }
}
```

### Problem 3: Need to Extend PHP Built-in Functionality

**Symptom**:
Want to enhance or modify the behavior of PHP built-in classes

**Solution**:

**Use the decorator pattern**:
```php
class EnhancedStorage {
    private ArrayObject $storage;
    
    public function __construct() {
        $this->storage = new ArrayObject();
    }
    
    public function set(string $key, mixed $value): void {
        echo "Setting {$key}\n";
        $this->storage[$key] = $value;
    }
    
    public function get(string $key): mixed {
        echo "Getting {$key}\n";
        return $this->storage[$key] ?? null;
    }
}
```

---

## 📊 Comparison: AOT vs Traditional PHP

| Feature | AOT Compiler | Traditional PHP |
|------|-----------|----------|
| **Check timing** | Compile time | Runtime |
| **Error discovery** | Reports an error immediately at compile time | Reports an error only when the code is executed |
| **autoload support** | ❌ Not supported as a parent class | ✅ Fully supported |
| **Inheriting internal classes** | ✅ Allowed (Zend internal classes; readonly contract checked via reflection) | ✅ Allowed (partially) |
| **Performance impact** | No runtime overhead | Has runtime checking overhead |

---

## 📚 Related Resources

- **Type System**: [NATIVE_TYPES.md](NATIVE_TYPES.md)
- **Compatibility Limitations**: [INCOMPATIBLE_PHP_FEATURES.md](INCOMPATIBLE_PHP_FEATURES.md)
- **Compilation Modes**: [COMPILATION_MODES.md](COMPILATION_MODES.md)
- **Quick Start**: [QUICKSTART.md](QUICKSTART.md)

---

## ❓ FAQ

### Q: Why doesn't the AOT compiler allow inheriting autoload classes?

A: The AOT compiler needs to know the complete structure of all classes at the compilation stage. autoload is a mechanism triggered at runtime, so the compiler cannot obtain class definition information at compile time and therefore cannot generate correct C++ code.

### Q: How do I determine whether a class is an internal class?

A: The AOT compiler uses the `Reflection::isInternalClass()` function to determine this. The function scans all declared classes and uses the `ReflectionClass::isInternal()` method to detect.

### Q: Can interfaces be implemented?

A: ✅ Yes! The AOT compiler fully supports implementing interfaces. Interfaces only define method signatures and do not involve concrete implementation, so they can be verified at compile time.

### Q: Can traits be used?

A: The use of traits is likewise restricted. Traits must be visible at compile time and cannot depend on autoload for dynamic loading.

---

**Last updated**: October 2026 (aligned with v0.9.5 behavior: Zend internal classes can be inherited)
**Applicable version**: PHP AOT Compiler v0.9.5

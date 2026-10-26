# AOT 编译器类继承规则

## 📋 概述

AOT（Ahead-Of-Time）编译器在编译阶段会对类的继承关系进行严格检查。与传统的 PHP 运行时检查不同，AOT 编译器要求在**编译期**就能确定所有父类和接口的存在性。

---

## ⚠️ 核心规则

### 规则一：只能继承已存在的类

```php
// ✅ 正确：继承 PHP 内置类
class MyException extends Exception {}

// ✅ 正确：继承同一项目中定义的类
class BaseClass {}
class ChildClass extends BaseClass {}

// ❌ 错误：继承不存在的类
class MyClass extends NonExistentClass {}
// 编译错误：Class `MyClass` inherits from a non-existent class `NonExistentClass`
```

### 规则二：不能继承 autoload 运行时载入的类

```php
// ❌ 错误：依赖 autoload 的类
class MyClass extends ExternalLibrary\BaseClass {}
// 如果 BaseClass 需要通过 autoload 在运行时加载，编译将失败

// ✅ 正确：使用接口代替
interface BaseInterface {}
class MyClass implements BaseInterface {}
```

### 规则三：Native 类与普通 Zend 类不能互相继承

```php
// ✅ 正确：继承 Zend 内部类（编译期按 Reflection::isInternalClass 判定）
class MyArray extends ArrayObject {}
class MyDb extends PDO {}

// ❌ 错误：Native 类与普通 Zend 类互继
// class MyNative extends SomeZendClass {}   // Native 类不能继承 Zend 类
// class MyZend extends SomeNativeClass {}   // Zend 类不能继承 Native 类
// 编译错误：Native and ZendVM-backed classes cannot inherit from each other
```

---

## 🔍 编译期检查机制

### 检查流程

1. **词法分析阶段**
   - 解析 `extends` 和 `implements` 关键字
   - 提取父类和接口的名称

2. **符号表构建**
   - 收集当前文件中所有已定义的类
   - 查询 PHP 内置类列表
   - 验证继承关系的合法性

3. **错误报告**
   - 发现非法继承时立即报错
   - 提供详细的错误位置和原因

### 检查代码位置

相关实现在 `src/Translator.php`（类继承检查）与 `src/Preprocessor.php`（内部类判定）：

```php
// src/Translator.php — 编译期继承检查（v0.9.5）
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
// src/Preprocessor.php — 内部类判定：父类是内置类时标记 inheritedFromInternalClass，
// 后续只按宿主运行时反射校验 readonly 契约，允许继承。
```

---

## 🎯 合法继承示例

### 示例一：继承项目内定义的类

```php
<?php
// 定义基类
abstract class Shape {
    protected float $area = 0.0;
    
    abstract public function calculateArea(): float;
    
    public function getArea(): float {
        return $this->area;
    }
}

// 继承基类（✅ 允许）
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

### 示例二：实现接口

```php
<?php
// 定义接口
interface Renderable {
    public function render(): string;
}

interface Serializable {
    public function serialize(): string;
}

// 实现多个接口（✅ 允许）
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

### 示例三：多层继承

```php
<?php
// 基础控制器
abstract class Controller {
    protected array $data = [];
    
    public function setData(string $key, mixed $value): void {
        $this->data[$key] = $value;
    }
    
    abstract public function execute(): void;
}

// 抽象的用户控制器
abstract class UserController extends Controller {
    protected ?string $userId = null;
    
    public function setUserId(string $id): void {
        $this->userId = $id;
    }
}

// 具体的用户列表控制器（✅ 允许）
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

## ❌ 非法继承示例

### 示例一：继承不存在的类

```php
<?php
// ❌ 错误：NonExistentBase 未定义
class MyClass extends NonExistentBase {
    // ...
}

function main() {
    $obj = new MyClass();
}
?>
```

**编译错误**:
```
Fatal error: Class `MyClass` inherits from a non-existent class `NonExistentBase` 
in /path/to/file.php on line X
```

**解决方案**:
```php
<?php
// ✅ 先定义基类
class NonExistentBase {
    // ...
}

class MyClass extends NonExistentBase {
    // ...
}
```

### 示例二：依赖 autoload 的外部类

```php
<?php
// ❌ 错误：ExternalLib\BaseClass 需要通过 autoload 加载
use ExternalLib\BaseClass;

class MyService extends BaseClass {
    // ...
}

function main() {
    $service = new MyService();
}
?>
```

**编译错误**:
```
Fatal error: Class `MyService` inherits from a non-existent class `ExternalLib\BaseClass`
in /path/to/file.php on line X
```

**解决方案**:

**方案 A - 使用接口**:
```php
<?php
interface BaseServiceInterface {
    public function process(): mixed;
}

class MyService implements BaseServiceInterface {
    public function process(): mixed {
        // 实现逻辑
    }
}
```

**方案 B - 使用组合**:
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

### 示例三：Native 类与 Zend 类互继

```php
<?php
// ❌ 错误：Zend 类继承 Native 类（或反向）
class MyNative extends SomeZendClass {}

function main() {
    $obj = new MyNative();
}
?>
```

**编译错误**:
```
Fatal error: Native and ZendVM-backed classes cannot inherit from each other
in /path/to/file.php on line X
```

**说明**：继承 Zend 内部类（如 `PDO`、`ArrayObject`）是**允许**的；组合模式仍然是
值得推荐的风格，但不是编译器强制要求。

```php
<?php
// ✅ 组合模式（推荐风格，非强制）
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

## 💡 最佳实践

### 1. 优先使用接口而非继承

```php
// ❌ 不推荐：深度继承链
class A {}
class B extends A {}
class C extends B {}
class D extends C {}

// ✅ 推荐：使用接口
interface ServiceInterface {}
class UserService implements ServiceInterface {}
class ProductService implements ServiceInterface {}
```

### 2. 优先组合而非继承

```php
// ❌ 不推荐：为复用而继承（继承链过深或继承内部类以外的场景）
class MyCollection extends ArrayCollection {
    // ...
}

// ✅ 推荐：组合模式
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

### 3. 在项目内定义可继承的基类

```php
<?php
// base.stub.php - 定义可继承的基类

abstract class BaseController {
    protected array $middlewares = [];
    
    abstract public function handle(): void;
    
    protected function middleware(string $name): void {
        echo "Running middleware: {$name}\n";
    }
}

// api.php - 继承项目内基类

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

### 4. 避免循环依赖

```php
// ❌ 错误：循环继承会导致编译失败
// file1.php
class A extends B {}

// file2.php  
class B extends A {}

// ✅ 正确：单向继承
// base.php
class Base {}

// child.php
class Child extends Base {}
```

---

## 🔧 故障排除

### 问题一：编译时报 "non-existent class"

**症状**:
```
Fatal error: Class `MyClass` inherits from a non-existent class `ParentClass`
```

**诊断步骤**:
1. 检查 `ParentClass` 是否在编译单元中定义
2. 确认没有拼写错误
3. 验证类的命名空间是否正确

**解决方案**:
```php
// 确保父类在子类之前定义或在同一文件中
class ParentClass {}
class MyClass extends ParentClass {}
```

### 问题二：无法使用第三方库的类作为父类

**症状**:
需要使用外部库的功能，但不能直接继承

**解决方案**:

**方法 A - 适配器模式**:
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

**方法 B - 委托模式**:
```php
class Wrapper {
    public function __call(string $name, array $args): mixed {
        $external = new ExternalClass();
        return call_user_func_array([$external, $name], $args);
    }
}
```

### 问题三：需要扩展 PHP 内置功能

**症状**:
想要增强或修改 PHP 内置类的行为

**解决方案**:

**使用装饰器模式**:
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

## 📊 对比：AOT vs 传统 PHP

| 特性 | AOT 编译器 | 传统 PHP |
|------|-----------|----------|
| **检查时机** | 编译期 | 运行时 |
| **错误发现** | 编译时立即报错 | 执行到代码时才报错 |
| **autoload 支持** | ❌ 不支持作为父类 | ✅ 完全支持 |
| **继承内部类** | ✅ 允许（Zend 内部类，readonly 契约按反射校验） | ✅ 允许（部分） |
| **性能影响** | 无运行时开销 | 有运行时检查开销 |

---

## 📚 相关资源

- **类型系统**: [NATIVE_TYPES.md](NATIVE_TYPES.md)
- **兼容性限制**: [INCOMPATIBLE_PHP_FEATURES.md](INCOMPATIBLE_PHP_FEATURES.md)
- **编译模式**: [COMPILATION_MODES.md](COMPILATION_MODES.md)
- **快速入门**: [QUICKSTART.md](QUICKSTART.md)

---

## ❓ 常见问题

### Q: 为什么 AOT 编译器不允许继承 autoload 类？

A: AOT 编译器在编译阶段就需要知道所有类的完整结构。autoload 是在运行时触发的机制，编译期无法获取类的定义信息，因此无法生成正确的 C++ 代码。

### Q: 如何判断一个类是否是内部类？

A: AOT 编译器使用 `Reflection::isInternalClass()` 函数来判断。该函数会扫描所有已声明的类，并使用 `ReflectionClass::isInternal()` 方法检测。

### Q: 可以实现接口吗？

A: ✅ 可以！AOT 编译器完全支持实现接口。接口只定义方法签名，不涉及具体实现，因此可以在编译期验证。

### Q: trait 可以使用吗？

A: Trait 的使用同样受到限制。Trait 必须在编译期可见，不能依赖 autoload 动态加载。

---

**最后更新**: 2026 年 10 月（对齐 v0.9.5 实际行为：允许继承 Zend 内部类）  
**适用版本**: PHP AOT Compiler v0.9.5

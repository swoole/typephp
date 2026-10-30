# compile-time-attribute

Compile-time attribute providers: ordinary `#[Attribute]` classes that a separate provider class
expands while the project is being translated. A provider receives a `Generator` describing the
annotated target and asks for extra members or property hooks, so the generated code is lowered by
the same pipeline as hand-written code — no runtime reflection and no interpreter involved.

Build and run from the repository root:

```sh
php bin/tpc.php examples/compile-time-attribute/project.yml --no-progress
LD_LIBRARY_PATH="$PHPX_HOME/lib" ./examples/compile-time-attribute/attribute_demo
```

## Layout

| Path | Contents |
| --- | --- |
| `src/Attributes.php` | The annotations themselves, all plain `#[Attribute]` classes. |
| `src/Model.php` | Annotated hosts: a final class, an abstract class, a `readonly` class, a trait, an interface and two enums. |
| `src/main.php` | Uses the generated API only — every member below is produced at compile time. |
| `providers/*.php` | One provider class per annotation, registered in `project.yml`. |

## What each provider contributes

| Annotation | Target | Provider build step |
| --- | --- | --- |
| `#[Entity]` | class | Adds a class constant, a trait usage (`use \Shop\HasSku;`), a private property with accessors, and a `get…()` accessor for every public property that has a default. |
| `#[Audited]` | class | Counts writes: adds a counter plus a setter layer for every public `int` property with a default. |
| `#[Described]` | class | Reads `classInfo()`, `constants()`, `methods()`, `properties()` and `site()`, then emits a static `describe()` summary. |
| `#[Stamp]` | trait | Emits a method into the annotated trait. |
| `#[Labelled]` | enum | Reads the annotation arguments, `isEnum` and `enumBackingType`, then emits a label method. |
| `#[Clamped]` | property | Setter layer that rewrites `{{value}}` before passing it on through `{{call}}`. |
| `#[Trimmed]` | property | Second setter layer on a string property. |
| `#[Surcharge]` | property | Getter layer that reads the backing value through `{{value}}`. |
| `#[Derived]` | property | Turns a property without a default into a virtual getter that evaluates the expression passed to the annotation. |
| `#[Traced]` | method | Renders the host signature from `MethodSignature`/`ParamInfo` (visibility, `static`, by-reference and variadic parameters, defaults) and records the annotation location. |

The layers stack: `$order->total` carries both the `#[Clamped]` rewrite and the `#[Audited]` counter,
and `$order->net` is read through `#[Surcharge]` while writes still go through `#[Audited]`.

## Output

```text
kind=order
kind=order
sku=sku:generic
total=1000
customer=ada
net=110
doubleTotal=2000
writes=3
history=manual
price=1000
seen=1
trace=public add(int $amount, &int $seen=?, ...int $extra): int
trace2=private static internal(): void
site=Shop\Traced on method add() at Model.php:79
describe=fqcn=Shop\Order|final=yes|abstract=no|readonly=no|extends=-|implements=Shop\Priced|constants=-|methods=price,internal,add|properties=total:public:int=,customer:public:string=,net:public:int=,doubleTotal:public:int|promoted=-|via=Shop\Described
document=fqcn=Shop\Document|final=no|abstract=yes|readonly=no|extends=-|implements=Shop\Priced|constants=-|methods=price|properties=pages:public:int=|promoted=-|via=Shop\Described
receipt=fqcn=Shop\Receipt|final=yes|abstract=no|readonly=no|extends=Shop\Document|implements=-|constants=-|methods=price|properties=-|promoted=-|via=Shop\Described
receipt-price=10
money=fqcn=Shop\Money|final=yes|abstract=no|readonly=yes|extends=-|implements=-|constants=-|methods=__construct|properties=amount:public:int|promoted=amount|via=Shop\Described
trait=stamp:Shop\Countable
count=0
enum=order:Shop\State:enum=yes:backing=string
enum2=enum:Shop\Priority:enum=yes:backing=int
```

`describe()` reports the host as written, before expansion, which is why `Order` lists neither
`KIND` nor the generated accessors; `total=1000` shows the clamp, `customer=ada` the trim,
`net=110` the surcharge getter and `doubleTotal=2000` the virtual getter.

## Limits worth knowing

- One getter layer per property; setter layers may stack because each one calls `{{call}}`.
- Providers are only read from a project manifest. Single-file and library builds reject them.
- Provider files are loaded while the project boots, so they must not run code at the top level.
- Generated snippets are parsed as members only: attribute groups, and member names starting with
  `__typephp_`, are rejected.

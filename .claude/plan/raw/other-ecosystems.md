# Typed JSON loading outside PHP — state of the art in 2026 and patterns worth porting

A research report covering the Python, TypeScript/JS, Rust, Java/Kotlin, and Go ecosystems plus code generators, focused on the problem: *"load JSON, optionally validate it against JSON Schema, work on a typed object tree with automatic type conversion"*.

---

## 1. Python

### Pydantic v2 — the gold standard of "model-first"

- **Model API**: declarative classes (`class User(BaseModel)`) with type annotations as the single source of truth. Metadata via `Field(...)`, `Annotated[int, Field(gt=0)]`. For types outside models — `TypeAdapter` (e.g. `TypeAdapter(list[User])`).
- **Validation + deserialization**: **a single step**. [`model_validate_json()`](https://pydantic.dev/docs/validation/latest/concepts/json/) parses JSON *directly in Rust* (pydantic-core) — with no intermediate `json.loads()`. This is the key architectural decision: the parser knows what type it expects, so it validates and constructs objects in a single pass.
- **Coercion**: two modes — *lax* (default: `"123"` → `123`, `"2026-01-01"` → `date`) and *strict* (`ConfigDict(strict=True)` or `Strict()` per field). A separate, looser coercion table for JSON input than for Python input — a deliberate design (JSON has no dates, so string→date is OK even in strict mode).
- **Advanced types**: unions (smart-union — tries strict on all members before falling back to lax), [**discriminated unions**](https://pydantic.dev/docs/validation/latest/concepts/unions/) via `Field(discriminator="type")` + `Literal` (efficient and with better errors), native enums, `datetime/date/UUID/Decimal` out-of-the-box, `Optional[X]` ≠ optional field (nullable vs `Field(default=...)` — explicitly separated), `default_factory`.
- **Errors**: `ValidationError` **aggregates all errors** from the entire tree; each has `loc` (a tuple path, easily convertible to a JSON Pointer), `type` (machine code), `msg`, `input`. Known weaknesses: errors in a non-discriminated union can explode combinatorially ([issue #11043](https://github.com/pydantic/pydantic/issues/11043)).
- **JSON Schema**: generated **FROM models** — `model_json_schema()` / `TypeAdapter.json_schema()` (draft 2020-12), with `validation`/`serialization` modes ([docs](https://pydantic.dev/docs/validation/latest/concepts/json_schema/)). This defines the "model-first" approach.
- **Performance**: core in Rust, 5–50× faster than v1; validators compiled once at class definition (the "core schema" is built from annotations at import time).

### msgspec — performance above all

- [`msgspec.Struct`](https://github.com/msgspec/msgspec) — declarative classes in C, validation **exclusively during decoding** (`msgspec.json.decode(data, type=User)`). One pass, zero intermediate allocations — hence ~12× faster than Pydantic v2 ([benchmarks](https://msgspec.dev/benchmarks)).
- Philosophy: type validation exactly as the annotation declares, minimal coercion, tagged unions via `tag`/`tag_field`. Less ergonomics (no custom validators at Pydantic's level, [comparison of drawbacks](https://hrekov.com/blog/msgspec-vs-pydantic-drawbacks)). Lesson: **validation during parsing is the biggest performance lever**.

### dataclasses + marshmallow — the old two-step approach

- Marshmallow: the schema as a separate class (`UserSchema(Schema)`) alongside the data class — **two sources of truth**, two steps (`json.loads` → `schema.load()`). Errors aggregated in a nested dictionary. In 2026 clearly in retreat in favor of Pydantic ([comparison](https://rootstack.com/en/blog/data-structure-and-validation-python-pydantic-marshmallow-and-dataclasses)) — precisely because of the schema/model duplication. This is an anti-pattern to avoid.

---

## 2. TypeScript / JavaScript

### Zod 4 — the "parse, don't validate" pattern in practice

- **Model API**: schema-as-code — the schema is built with combinators (`z.object({ name: z.string() })`), and the static type is **derived from the schema**: `type User = z.infer<typeof UserSchema>`. A single source of truth for runtime and compile-time — this is the heart of Zod's popularity.
- **Validation + deserialization**: JSON is parsed with `JSON.parse` (JS has a native tree), but `schema.parse(data)` returns a value **with a narrowed type** — a realization of Alexis King's essay ["Parse, don't validate"](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/): the function doesn't answer "yes/no", it returns proof in the type. `safeParse` returns `{ success, data | error }` without exceptions.
- **Coercion/transformations**: `z.coerce.number()`, `z.string().transform(...)`, `z.iso.datetime()`, `.default(...)`, `.catch(...)`. An important distinction: `.optional()` (the field may not exist) vs `.nullable()` (it may be `null`) vs `.nullish()`.
- **Advanced types**: `z.union`, `z.discriminatedUnion("type", [...])`, `z.enum`, `z.literal`, recursion via getters (v4).
- **Errors**: `ZodError.issues[]` with `path` (an array path), `code`, `message`; formatting via [`z.treeifyError` / `z.prettifyError` / `z.flattenError`](https://zod.dev/error-formatting). All errors aggregated at once.
- **JSON Schema**: v4 has native `z.toJSONSchema()` — model-first with schema export.
- **Performance**: v4 — 14× faster string parsing and 6.5× faster object parsing vs v3 ([2026 comparison](https://www.pkgpulse.com/guides/zod-v4-vs-arktype-vs-typebox-vs-valibot-2026)); still slower than compiled Ajv, but at ~1 µs/parse that's irrelevant outside hot loops.

### TypeBox + Ajv — the "schema-first" approach

- [TypeBox](https://www.pkgpulse.com/guides/zod-vs-typebox-2026): you build **literally JSON Schema** (`Type.Object({...})` produces an object conforming to draft 2020-12), and the TS type is derived from it (`Static<typeof T>`). The schema is a first-class artifact — ideal when JSON Schema must be the contract (OpenAPI, Fastify).
- [Ajv](https://ajv.js.org/HOME.html): a pure JSON Schema validator (draft-04…2020-12 + JSON Type Definition RFC 8927). **Compiles the schema to a JS function** (also [ahead-of-time to a file](https://ajv.js.org/api.html) when `eval` is forbidden) — hence the highest performance in the ecosystem. [Opt-in coercion](https://ajv.js.org/coercion.html) (`coerceTypes: true/"array"`), with an explicitly documented table of rules and in-place data mutation. Errors with `instancePath` — a **real JSON Pointer** (`/prop/1/subProp`) — plus `schemaPath` pointing at the schema rule; aggregation via `allErrors: true`.

### Valibot, io-ts, Standard Schema

- [Valibot](https://valibot.dev/guides/comparison/): a functional API instead of a chained one (`v.pipe(v.string(), v.email())`) — thereby tree-shakeable, ~90% smaller bundle than Zod. The same mental model as Zod.
- io-ts: pioneer of the codec pattern (decode/encode as a pair) — in 2026 **in maintenance mode**; its successor is [Effect Schema](https://effect.website/docs/other/fp-ts) (the author of io-ts joined the Effect team). Effect Schema adds an important pattern: a **bidirectional schema** — the same definition describes both `decode` (JSON→domain) and `encode` (domain→JSON).
- **[Standard Schema 1.0](https://standardschema.dev/json-schema)** — the novelty defining 2026: a ~60-line common TS interface written jointly by the creators of Zod, Valibot, and ArkType. Frameworks (tRPC, TanStack Form, Hono) accept "any schema conforming to Standard Schema" instead of tying themselves to a single library ([discussion](https://blog.openreplay.com/standard-schema-explained-flexible-validation/)). Lesson for PHP: it's worth designing for a **common, minimal validator interface**, not for a specific library.

---

## 3. Rust — serde + serde_json + schemars

- **Model API**: derive macros on structs: `#[derive(Serialize, Deserialize)]` — model-first, the validating-deserializing code is **generated at compile time** (zero runtime reflection). [serde_json](https://github.com/serde-rs/json) also provides an untyped `Value` as a fallback.
- **A single step**: `serde_json::from_str::<User>(s)` — the parser drives a visitor of the target type; a type mismatch = a parsing error. The "parse, don't validate" pattern in its purest form. Validation of *business rules* (ranges etc.) belongs to separate crates (`validator`, `garde`) — a second step.
- **Advanced types**: Rust enums = true sum types; attributes `#[serde(tag = "type")]` (internally tagged), `#[serde(tag, content)]` (adjacently), `untagged` — the richest range of discriminated-union representations of all ecosystems. `Option<T>` + `#[serde(default)]` separate nullable from optional; `#[serde(rename_all = "camelCase")]`, `deserialize_with` for dates (chrono/time).
- **JSON Schema**: [schemars](https://github.com/GREsau/schemars) — `#[derive(JsonSchema)]` generates the schema **by reading the same `#[serde(...)]` attributes**, so the schema is guaranteed to match the actual (de)serialization ([docs](https://graham.cool/schemars/)). An elegant pattern: one annotation, two artifacts.
- **Errors**: serde stops at the **first error** (with line/column and a path via the `serde_path_to_error` crate) — a deliberate performance trade-off; error aggregation is the domain of second-step validators.
- **Performance**: compile-time monomorphization — the reference point for everyone else (simd-json is even faster).

---

## 4. Java / Kotlin

### Jackson (databind)

- Two levels: the untyped `JsonNode` tree (the equivalent of an array in PHP, but with a navigational API `at("/a/b/0")` — JSON Pointer built in) and typed POJOs: `mapper.readValue(json, User.class)` — a single step, reflection + introspection cache.
- Polymorphism via [`@JsonTypeInfo`/`@JsonSubTypes`](https://serpro69.medium.com/kotlin-with-jackson-deserializing-kotlin-sealed-classes-c95f837e9164) — a configurable discriminator field (property / wrapper array / existing property). [jackson-module-kotlin](https://github.com/FasterXML/jackson-module-kotlin) automatically detects sealed class subclasses.
- Coercion configurable at a very fine grain (`CoercionConfig` per type and input shape), dates via `JavaTimeModule`, default values from the Kotlin constructor. Errors: an exception on the first problem with `JsonMappingException.getPath()`. Rule validation = a separate step (Bean Validation / Hibernate Validator — `@NotNull`, `@Size` annotations, errors aggregated with `propertyPath`).

### kotlinx.serialization

- `@Serializable` + a **compiler plugin** generates serializers at compile time (zero reflection — works on multiplatform/native). A sealed hierarchy = an [automatic discriminated union](https://kotlinlang.org/api/kotlinx.serialization/kotlinx-serialization-core/kotlinx.serialization/-sealed-class-serializer/) with a `type` field (configurable via `classDiscriminator`).
- Separation of nullable (`String?`) from optional (a default value in the constructor) — exemplarily clean. In 2026 Gson is legacy in practice (no support for Kotlin null-safety and default constructors).

---

## 5. Go

- [`encoding/json`](https://betterstack.com/community/guides/scaling-go/json-in-go/): struct tags (`json:"name,omitempty"`), runtime reflection, a single step `json.Unmarshal(data, &user)`. Minimalism: no unions, no discriminators (manual two-step decoding via `json.RawMessage`), no distinction between "field absent" vs "zero value" (a classic pain point — workarounds via `*string` pointers).
- [`encoding/json/v2`](https://go.dev/blog/jsonv2-exp) (experimental since Go 1.25, `GOEXPERIMENT=jsonv2`): fixes decades of legacy — case-sensitive field matching by default, structural errors reported instead of ignored, `omitempty` based on JSON emptiness rather than the Go zero value, ~1.8× faster decoder, a streaming `jsontext` architecture ([overview of changes](https://antonz.org/go-json-v2/)). Direction: **safer defaults**.
- Rule validation: [go-playground/validator](https://pkg.go.dev/github.com/go-playground/validator/v10) — tags `validate:"required,email,gte=18"`, **always a second step** after Unmarshal; errors aggregated with `Namespace()` (field path). The Go ecosystem accepts the two-step approach as the price of stdlib simplicity.

---

## 6. Code generators from JSON Schema

| Tool | Input → output | Notes |
|---|---|---|
| [quicktype](https://github.com/glideapps/quicktype) | JSON / JSON Schema / GraphQL / TS → 20+ languages (including PHP!) | generates types **and converters** with runtime validation |
| [datamodel-code-generator](https://apidog.com/blog/json-schema-generator/) | JSON Schema / OpenAPI → Pydantic v1/v2 models, dataclasses, msgspec | the standard in Python for schema-first |
| [json-schema-to-typescript](https://npmtrends.com/dts-generator-vs-json-schema-to-typescript-vs-quicktype) | JSON Schema → TS interfaces (types only, no runtime) | 1.1M downloads/week — the most popular |
| openapi-generator | OpenAPI → clients+models, dozens of languages | heavy, template-based, per-language quality uneven |

Codegen wins on performance against reflection (also confirmed in PHP: [DTO benchmark](https://www.dereuromark.de/2026/03/02/dtos-at-the-speed-of-plain-php/) — generated code vs runtime reflection is an order of magnitude with nesting), but loses ergonomically: a build step, generated code in the repo, drift with manual edits.

---

## Answers to the key questions

### (a) Dominant API patterns and why Pydantic/Zod are beloved

1. **A single source of truth = the type in the host language.** You write the class/schema once and get: a static type, a runtime validator, documentation, and JSON Schema. Zero duplication (anti-example: marshmallow).
2. **Parse, don't validate.** The function takes `string|mixed` and returns `User` — correctness is encoded in the return type, not in a boolean beside it. After a successful parse it is impossible to "forget" about validation.
3. **A single step: decoding = validation = construction.** The best libraries (Pydantic core, msgspec, serde) merge this into a single pass — faster and without the intermediate state of "the object exists, but is unvalidated".
4. **Two error APIs: throwing and non-throwing.** `parse`/`safeParse`, `model_validate` + `ValidationError`. A Result-type return is the norm for paths where "we expect bad data".
5. **Error aggregation with paths.** All errors at once, each with a machine code + a path (JSON Pointer in Ajv, an array in Zod, a `loc` tuple in Pydantic) + the input value. Machine format and human pretty-print kept separate.
6. **Explicit, opt-in coercion with a documented table of rules** (Pydantic's lax/strict, `z.coerce`, Ajv's `coerceTypes`). Never silent and uncontrolled.
7. **Discriminated unions as a first-class feature** — with a configurable discriminator field; it improves both performance and error messages.
8. **Separation of "optional" from "nullable"** — all mature libraries treat these as two different concepts.
9. **Compile/cache the validator once, use it many times** (Ajv compile, Pydantic core schema, serde at compile time).
10. **Interoperability over lock-in** — Standard Schema as the 2026 trend.

### (b) What can be ported to PHP 8.3+

Realistically portable (some of it already exists in [CuyZ/Valinor](https://github.com/CuyZ/Valinor), spatie/laravel-data, Symfony `#[MapRequestPayload]`):

- **Model-first on classes**: readonly classes with promoted constructor parameters as declarative models; attributes (`#[Discriminator('type')]`, `#[Format('date-time')]`) as the equivalent of `Field(...)`/`#[serde(...)]`. The constructor as a single validation point ≈ "parse, don't validate" (a readonly object cannot exist in an unvalidated state).
- **No native generics**: Valinor's solution — types in docblocks (`list<User>`, shaped arrays) parsed by the library and honored by PHPStan/Psalm — is the PHP equivalent of `z.infer`. Alternative: a `TypeAdapter`-style API: `$mapper->map('list<User>', $json)`.
- **A single step + both error styles**: a throwing `map()` and a `tryMap()` returning a Result object; an exception aggregating all errors with JSON Pointers (the Ajv `instancePath` pattern).
- **Discriminated unions**: PHP backed enums as discriminators + an interface/abstract class with an attribute mapping value→class (the `@JsonSubTypes`/`#[serde(tag)]` pattern). PHP 8.1+ enums map directly onto `Literal`/enum from schemas.
- **Optional vs nullable**: a parameter with a default value = optional; `?Type` = nullable — the semantics are already in the language, they just need to be honored (like kotlinx.serialization).
- **Explicit coercion via modes**: strict / lax (e.g. flexible casting in Valinor) with a documented table; a separate, looser mode for JSON sources (string→DateTimeImmutable always OK).
- **JSON Schema generation from classes** (the equivalent of schemars/`model_json_schema`) — reading the same attributes as the mapper, so the schema doesn't drift.
- **Performance**: reflection with a cache (like Jackson) suffices; optional codegen of compiled mappers to files (the Ajv standalone / symfony DI container pattern) for the hot path — PHP benchmarks show a ~10× difference on nested DTOs.

Not portable / hindered: deriving the static type from the schema within the language itself (Zod's `infer` role falls to PHPStan), compile-time monomorphization (serde), compiler plugins (kotlinx).

### (c) Schema-first vs model-first

| | **Schema-first** (JSON Schema = source of truth) | **Model-first** (classes = source of truth) |
|---|---|---|
| Representatives | TypeBox, Ajv, quicktype/datamodel-code-generator, OpenAPI | Pydantic, Zod, serde+schemars, kotlinx, Jackson |
| Direction | schema → types (codegen or inference) | classes → schema (generation) |
| Strengths | the schema is a contract between languages/teams; validation 1:1 with the specification; contract versioning independent of code | ergonomics (you write in the native language), IDE refactoring, no build step, the full richness of language types (enums, value objects, dates) |
| Weaknesses | JSON Schema cannot express domain types (value objects, constructor invariants); codegen = build step + drift | the schema is a secondary artifact — its shape tends to be "whatever came out"; interop requires discipline |
| When | public APIs, multi-language contracts, configuration validated by external tools | applications where the same team controls both the producer and consumer of the data |

The 2026 trend is clear: **model-first with JSON Schema export wins ergonomically** (Pydantic, Zod 4 with `z.toJSONSchema`, schemars), while schema-first remains a contract niche (OpenAPI/Fastify). The best projects support both directions: models generate the schema, and an optional validator can also check a document against an external JSON Schema as a pre-check.

---

## Sources

- Python: [Pydantic — JSON](https://pydantic.dev/docs/validation/latest/concepts/json/), [Pydantic — JSON Schema](https://pydantic.dev/docs/validation/latest/concepts/json_schema/), [Pydantic — Unions](https://pydantic.dev/docs/validation/latest/concepts/unions/), [msgspec](https://github.com/msgspec/msgspec), [msgspec benchmarks](https://msgspec.dev/benchmarks)
- TS/JS: [Zod — error formatting](https://zod.dev/error-formatting), [Zod v4 vs ArkType vs TypeBox vs Valibot 2026](https://www.pkgpulse.com/guides/zod-v4-vs-arktype-vs-typebox-vs-valibot-2026), [Ajv](https://ajv.js.org/HOME.html), [Ajv — coercion](https://ajv.js.org/coercion.html), [Standard Schema](https://standardschema.dev/json-schema), ["Parse, don't validate" — A. King](https://lexi-lambda.github.io/blog/2019/11/05/parse-don-t-validate/)
- Rust: [serde_json](https://github.com/serde-rs/json), [schemars](https://github.com/GREsau/schemars)
- Java/Kotlin: [jackson-module-kotlin](https://github.com/FasterXML/jackson-module-kotlin), [kotlinx SealedClassSerializer](https://kotlinlang.org/api/kotlinx.serialization/kotlinx-serialization-core/kotlinx.serialization/-sealed-class-serializer/)
- Go: [json/v2 — Go blog](https://go.dev/blog/jsonv2-exp), [go-playground/validator](https://pkg.go.dev/github.com/go-playground/validator/v10)
- Codegen: [quicktype](https://github.com/glideapps/quicktype), [generator overview](https://apidog.com/blog/json-schema-generator/)
- PHP context: [CuyZ/Valinor](https://github.com/CuyZ/Valinor), [DTOs at the speed of plain PHP](https://www.dereuromark.de/2026/03/02/dtos-at-the-speed-of-plain-php/)

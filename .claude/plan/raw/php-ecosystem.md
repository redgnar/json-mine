# PHP Ecosystem 2026: JSON → JSON Schema validation → typed PHP objects

Research report (as of: August 2026). Sources: Packagist, GitHub, project documentation, wiki.php.net.

---

## 1. JSON Schema validators

### `justinrainbow/json-schema` → now the **jsonrainbow** organization
- **Repo:** https://github.com/jsonrainbow/json-schema | Packagist: `justinrainbow/json-schema`
- **What it does:** the classic validator of JSON documents against JSON Schema; operates on the result of `json_decode()` (stdClass/array).
- **Activity:** the project moved under the *jsonrainbow* organization and **regained momentum** — latest version **6.10.0 (June 16, 2026)**, regular releases in 2024–2026. ~3.6k stars, **343 million installs**, 687 dependent packages (the most popular validator in PHP, used by Composer among others).
- **Drafts:** draft-3, 4, 6, 7 and **2019-09** (added in the 6.x line); **no full 2020-12** ("features of newer drafts might not be supported").
- **PHP 8.x:** works (requires PHP >= 7.2), but the API is dated (`CHECK_MODE_*` modes, working on stdClass).
- **Strengths:** ubiquity, stability, **type coercion** mode (`CHECK_MODE_COERCE_TYPES`) and applying `default` from the schema.
- **Weaknesses:** no draft 2020-12, zero hydration — the result is still stdClass/array.
- **Validation + hydration?** No — validation only.

### `opis/json-schema`
- **Repo:** https://github.com/opis/json-schema | https://opis.io/json-schema/
- **What it does:** the most complete validator: **draft-06, 07, 2019-09 and 2020-12**.
- **Activity:** version **2.6.0 (October 2025)** — maintained, though at a slow pace (single releases per year). 654 stars, 47 million installs.
- **PHP 8.x:** yes (7.4/8.0+), uses union types, named arguments, attributes.
- **Strengths:** the only widely used validator with full **2020-12**; extensions (`$filters`, `$map`, custom formats), readable errors, good `$ref`/resolvers.
- **Weaknesses:** slower release cycle; no hydration; the extension documentation goes beyond the standard (easy to get vendor lock-in on `$filters`).
- **Validation + hydration?** No — validation only (there is "casting", but it is value coercion, not construction of domain objects).

### `swaggest/php-json-schema` (`swaggest/json-schema`)
- **Repo:** https://github.com/swaggest/php-json-schema
- **What it does:** a validator **plus** mapping onto PHP classes via `ClassStructureTrait` — the class declares its own schema, and `::import($data)` **validates and hydrates in one step** (also `export` with validation).
- **Drafts:** only **draft-04, 06, 07** — no 2019-09/2020-12.
- **Activity:** **v0.12.43 (December 2024)** — maintenance mode, no releases in 2025–2026. 488 stars, 13.8 million installs, PHP >= 7.1.
- **Strengths:** historically the **closest answer to the full pipeline** (JSON → validation → typed object graph); dynamic properties via phpdoc; name mapping.
- **Weaknesses:** outdated model (trait + static `setUpProperties` instead of native PHP 8 types/attributes), no enums/readonly/constructor promotion, old drafts, waning activity, perpetual `0.x`.
- **Validation + hydration?** **YES** — but the schema is defined in PHP alongside the class (or generated from it), not "any external schema file + any class".

### `league/openapi-psr7-validator` (OpenAPI context)
- **Repo:** https://github.com/thephpleague/openapi-psr7-validator
- **What it does:** validation of **PSR-7 messages** (request/response) against an **OpenAPI 3.0** specification — i.e. JSON Schema in the OpenAPI dialect, including headers, cookies, security schemes. PSR-15 middleware, Slim adapter, PSR-6 cache.
- **Activity:** latest release **0.24** (May), regular small releases — maintained, but still `0.x`. 562 stars, 18 million installs, PHP >= 7.2.
- **Strengths:** the only sensible tool for validating HTTP against OpenAPI; granular exceptions.
- **Weaknesses:** OpenAPI 3.0 dialect (not pure JSON Schema 2020-12 from OpenAPI 3.1); no hydration; performance with large specs requires caching.
- **Validation + hydration?** No — validation only.

### Additional context: native support in PHP 8.6 (RFC)
- **RFC:** https://wiki.php.net/rfc/json_schema_validation (Jakub Zelenka / bukka)
- Proposal for a `JsonSchema` class + a schema parameter in `json_decode()`/`json_validate()`, **drafts 04–2020-12**, targeting **PHP 8.6 (November 2026)**; at the time of research the RFC was in the discussion/pre-vote phase. **Hydration/mapping onto classes is explicitly deferred to "Future Scope"** (a separate RFC). This is a strong signal that the "validation+hydration" gap is recognized at the language level.

---

## 2. Mapping / hydration onto typed objects

### `cuyz/valinor` — the current "gold standard" of mapping
- **Repo:** https://github.com/CuyZ/Valinor | https://valinor-php.dev
- **Activity:** **2.5.1 (July 28, 2026)** — very active. 1.5k stars, 15 million installs. PHP **8.2–8.5**.
- **What it does:** `TreeMapper` maps JSON/array onto a strongly typed object graph, with **recursive type validation** and readable errors (path to the offending node). It has a native `Source::json()` source, a flexible mode (coercion) and strict mode. It understands **PHPStan/Psalm** types: generics via PHPDoc, `list<T>`, shaped arrays, `non-empty-string`, `int<0,100>`, enums (including with patterns), readonly, constructor promotion, interfaces with resolvers.
- **Strengths:** guarantee of a correct object state after mapping; zero dependencies; the richest type system in the ecosystem; normalizer (object → JSON) since 1.7/2.x.
- **Weaknesses:** slower than generated hydrators (compensated by cache); **validation concerns PHP types, not JSON Schema rules** (it will not enforce `pattern`, `minLength`, `multipleOf` from a schema file — unless you express them as types à la `positive-int`); 2 security advisories in its history (patched).
- **Validation + hydration?** Hydration + **type** validation in one step, but **without consuming a JSON Schema document**.

### `symfony/serializer` (+ PropertyInfo) and the new `symfony/object-mapper`
- **Docs:** https://symfony.com/doc/current/serializer.html | https://symfony.com/doc/current/object_mapper.html
- **Serializer:** the mature framework standard; denormalization JSON → objects typed via PropertyInfo/PropertyAccess, attributes `#[Groups]`, `#[SerializedName]`, `COLLECT_DENORMALIZATION_ERRORS`. Strong in flexibility and many formats (XML, CSV, YAML). Weaker in strictness: historical problems with variadics, collections in constructors, generics; type validation errors less precise than in Valinor; configuration can be verbose.
- **ObjectMapper:** **a new component, introduced in Symfony 7.3** (https://symfony.com/blog/new-in-symfony-7-3-objectmapper-component), package `symfony/object-mapper`, currently **v8.1.4 (August 2026)**, PHP >= 8.4. Note: this is an **object → object** mapper (DTO ↔ entity, `#[Map]` attribute), and **not** a raw-JSON hydrator — for the JSON→object pipeline, Serializer is still the tool.
- **Validation + hydration?** Not against JSON Schema; validation is done separately with the `symfony/validator` component (PHP constraints, not JSON Schema).

### `jms/serializer`
- **Repo:** https://github.com/schmittjoh/serializer
- **Activity:** **3.32.7 (March 2026)** — maintained (Asmir Mustafic), 144 million installs, 2.3k stars. PHP 7.4/8.0+.
- **Strengths:** mature, XML+JSON, API versioning, exclusion strategies, Doctrine integration; PHP 8 attributes.
- **Weaknesses:** heavy, metadata-centric, purely maintenance-mode development (no major new features); typing weaker than Valinor (no advanced PHPDoc types); no structural validation.
- **Validation + hydration?** No — (de)serialization only.

### `spatie/laravel-data`
- **Repo:** https://github.com/spatie/laravel-data
- **Activity:** **4.23.0 (May 2026)**, very active; 38 million installs, ~1.8k stars. PHP 8.1+, **Laravel only** (10+).
- **What it does:** a "three-in-one" DTO: hydration from request/JSON, **automatic Laravel validation rules from PHP types**, transformation to API resources + TypeScript type generation. Enums, lazy properties, collections.
- **Weaknesses:** coupled to Laravel; validation is the Laravel validator, **not JSON Schema**; the magic hinders static analysis.
- **Validation + hydration?** Yes, in one step — but validation in the Laravel dialect, not JSON Schema.

### `eventsauce/object-hydrator`
- **Repo:** https://github.com/EventSaucePHP/ObjectHydrator
- **Activity:** **1.8.0 (February 2026)** — maintained (Frank de Jonge); 2.6 million installs. PHP 8.0+.
- **Strengths:** lightweight, constructor-based (no reflection magic on private fields), casters via attributes, snake_case→camelCase mapping, **generation of optimized hydrator code (3–10× faster)** — good for production.
- **Weaknesses:** minimal validation (only constructor type compatibility), no advanced PHPDoc types/generics, no schema validation.
- **Validation + hydration?** No — pure hydration.

### `netresearch/jsonmapper` (JsonMapper, Christian Weiske)
- **Repo:** https://github.com/cweiske/jsonmapper
- **Activity:** **v6.0.0 (June 29, 2026)** — still alive; **116 million installs**, ~1.6k stars. Since v6 requires PHP >= 8.1.
- **Strengths:** the veteran of the genre, simple, maps via docblocks and native types; supports typed properties, constructor promotion, **backed enums**, union types; configurable handling of missing/unknown fields.
- **Weaknesses:** no generics/shaped arrays; validation is limited to types; imperative API, less "holistic" than Valinor; OSL-3.0 license (can be problematic in companies).
- **Validation + hydration?** No — hydration with basic type checking.

### `crell/serde`
- **Repo:** https://github.com/Crell/Serde
- **Activity:** **1.6.0 (June 23, 2026)** — active (Larry Garfield); 354 stars, ~313k installs. PHP ~8.2.
- **Strengths:** inspired by Rust's Serde; `#[Field]` attributes (renaming, flattening, type maps), JSON/YAML/TOML/CSV formats, **JSON/CSV streaming**; good support for enums, readonly.
- **Weaknesses:** smaller adoption; no structural validation; LGPL-3.0 may put people off.
- **Validation + hydration?** No — (de)serialization.

### `brick/json-mapper`
- **Repo:** https://github.com/brick/json-mapper
- **Activity:** exists; **0.2.1 (February 2026)**, PHP 8.2+, 206 stars, ~72k installs (niche, author: BenMorel).
- **Strengths:** zero configuration, reads constructor types + PHPDoc, union types with automatic resolution, backed enums, type-compatibility guarantee, snake/camel name mapping.
- **Weaknesses:** early `0.x`, small adoption, no schema validation, fewer features than Valinor.
- **Validation + hydration?** No — hydration with type checking.

---

## 3. Code generators from JSON Schema

| Tool | What it does | Status |
|---|---|---|
| [`swaggest/php-code-builder`](https://github.com/swaggest/php-code-builder) | Generates PHP classes (ClassStructure) from JSON Schema — the generated code **validates on import/export** via swaggest/json-schema | v0.2.43 (February 2025), 77 stars; generates code compatible even with PHP 5.6 — stylistically archaic |
| [`wol-soft/php-json-schema-model-generator`](https://github.com/wol-soft/php-json-schema-model-generator) | Generates **immutable PHP models with validation rules compiled into the code** (no runtime validator) + Symfony converter | Active at a moderate pace; conceptually closest to a "pipeline in a single artifact", but generated, not runtime |
| [`api-platform/schema-generator`](https://github.com/api-platform/schema-generator) | Generates classes from **Schema.org/ActivityStreams vocabularies and from OpenAPI** (not from pure JSON Schema as the main use case) | v5.2.5 (January 2026), 474 stars, active |
| [quicktype](https://github.com/glideapps/quicktype) | Multi-language type generator from JSON/JSON Schema/GraphQL; **has a PHP target**, generates classes + (de)serialization | Active, but the PHP target is second-tier (generated code without schema validation, quality below the TS/Go targets) |
| [`martin-helmich/php-schema2class`](https://github.com/martin-helmich/php-schema2class) | Generates classes with getters/setters and a `validateInput()` method (JsonSchema validation in code) | Niche (35 stars), requires PHP 8.5 to run, moderate activity |

---

## 4. The key question: does a full pipeline exist in a single library?

**Short answer: there is no single, modern, actively developed runtime library in PHP today that will take raw JSON + an external JSON Schema document (2020-12), validate it, and return a typed tree of PHP 8.x objects. The pipeline has to be assembled from building blocks.**

The closest are:

1. **`swaggest/php-json-schema` (+ php-code-builder)** — the only runtime doing "schema validation + hydration" in one step, but: drafts only up to 07, an API from before the PHP 8 era (no enums, readonly, native types as the source of truth), waning activity (last release Dec 2024).
2. **`wol-soft/php-json-schema-model-generator`** — a full pipeline, but via **code generation** (build step), not runtime mapping; validation compiled into the models.
3. **`spatie/laravel-data`** — validation + hydration in one step, but validation in the Laravel dialect and only within the Laravel ecosystem.
4. **`cuyz/valinor`** — validation + hydration in one step, but the validation derives from **PHP types**, not from a JSON Schema document; rules like `pattern`/`minLength`/`format` have to be expressed as types or constructors.

**Recommended "building blocks" stack (2026):**
- pure JSON Schema 2020-12: **`opis/json-schema` (validation) → `cuyz/valinor` (hydration)** — the de facto standard combination;
- HTTP/OpenAPI context: **`league/openapi-psr7-validator` → Valinor / eventsauce/object-hydrator**;
- maximum performance without schema validation: **eventsauce/object-hydrator** (generated code);
- build-time approach: **wol-soft/php-json-schema-model-generator**.

**Identified market gap:** a library that (a) consumes a standard, external JSON Schema draft 2020-12, (b) validates the document with errors pointing to a JSON Pointer path, (c) in the same pass hydrates onto PHP 8.2+ objects (native types, enums, readonly, generics via PHPDoc), (d) can infer the schema↔class mapping without duplicating definitions. Nobody delivers this today; the double definition of types (once in the schema, once in PHP classes) and the double pass over the data are a real cost of every current solution. The fact that the PHP 8.6 RFC adds native JSON Schema validation while explicitly pushing hydration to "future scope" confirms that the gap is known and still open — the native `JsonSchema` in PHP 8.6 (planned for November 2026) will likely make the validation layer free and shift the value of libraries precisely toward integrated hydration.

**Main sources:** [jsonrainbow/json-schema](https://github.com/jsonrainbow/json-schema), [opis/json-schema](https://github.com/opis/json-schema), [swaggest/php-json-schema](https://github.com/swaggest/php-json-schema), [league/openapi-psr7-validator](https://github.com/thephpleague/openapi-psr7-validator), [CuyZ/Valinor](https://github.com/CuyZ/Valinor) (+ [comparison of alternatives](https://valinor-php.dev/latest/project/alternatives/)), [Symfony ObjectMapper](https://symfony.com/blog/new-in-symfony-7-3-objectmapper-component), [jms/serializer](https://github.com/schmittjoh/serializer), [spatie/laravel-data](https://github.com/spatie/laravel-data), [EventSaucePHP/ObjectHydrator](https://github.com/EventSaucePHP/ObjectHydrator), [cweiske/jsonmapper](https://github.com/cweiske/jsonmapper), [Crell/Serde](https://github.com/Crell/Serde), [brick/json-mapper](https://github.com/brick/json-mapper), [swaggest/php-code-builder](https://github.com/swaggest/php-code-builder), [wol-soft/php-json-schema-model-generator](https://github.com/wol-soft/php-json-schema-model-generator), [api-platform/schema-generator](https://github.com/api-platform/schema-generator), [quicktype](https://github.com/glideapps/quicktype), [martin-helmich/php-schema2class](https://github.com/martin-helmich/php-schema2class), [RFC JSON Schema validation](https://wiki.php.net/rfc/json_schema_validation), [PHP.Watch — PHP 8.6](https://php.watch/versions/8.6).

# Context: the library's first consumer — a forms project

Date: 2026-08-08. Continuation of [00-mvp-analysis.md](00-mvp-analysis.md).

## Findings from the discussion

- First project using the library: **a form definition** — the form as a described data input. Implementations (renderers) are secondary and there may be many; what matters is the **form description interface** and **validating form values**.
- Saved form data is also JSON; it is worth describing it with a schema or an object (DTO).
- **We are not building our own JSON Schema validator** — delegate to an existing one.
- PHP **>= 8.4** (8.5 considered); no need to care about older versions.
- **Performance matters** — especially data write/read.
- Versioning of form definitions: useful, but not everything at once.
- **Two kinds of forms are expected:**
  1. **Forms with classes known upfront** — the shape is fixed, dedicated PHP classes can describe both the definition and the data.
  2. **Generic forms, assembled in a configurator** ("clicked together"), with no class definitions — the shape exists only in the JSON definition, at runtime.

## Key architectural observation

There are **two JSON documents of different nature** in play, and they map onto the two variants from the MVP analysis:

| | Form definition | Form data (values) |
|---|---|---|
| Shape | stable, controlled by us | **per form** — different for each definition |
| Validated against | our definition JSON Schema (meta-schema) | a schema **derived from the definition** |
| PHP representation | classes (`FormDefinition`, fields as a discriminated union on `type`) | dynamic tree or a user DTO |
| Variant from the analysis | A (hydration into classes) | C (schema-first, no upfront classes) |
| Operation frequency | parsed rarely → aggressive caching | **hot path** (every submit/read) |

The "two kinds of forms" requirement confirms this on the data side as well: statically-known forms want DTO hydration (A), configurator-built forms want the dynamic typed tree (C). **Hybrid variant D from the analysis is therefore not a theoretical option — the forms project requires both fronts.**

## Proposed flow (target)

```
definition.json ──(1) validate w/ meta-schema──(2) hydrate──▶ FormDefinition (PHP classes)
                                                                 │
                                                                 ├─(3) derive──▶ data-schema.json (JSON Schema 2020-12)
                                                                 │                  │ (the same schema can validate on
                                                                 │                  │  the JS frontend — Ajv/Zod!)
data.json ────────(4) validate w/ data-schema──────(5) access──▶ typed tree / DTO
```

1. **Definition validation** against the meta-schema (JSON Schema 2020-12) — delegated to `opis/json-schema`, results in a unified error format (JSON Pointer + machine-readable code).
2. **Definition hydration** into readonly PHP classes: discriminated union on the field `type`, backed enums, `DateTimeImmutable`, optional≠nullable, error aggregation. This is the library's core (TreeMapper).
3. **Data-schema derivation** from `FormDefinition` — we generate a standard JSON Schema describing the form's *values*. Big win: the same schema validates data in PHP **and in the browser** (Ajv), and documents the stored data.
4. **Data validation** with the derived schema (again delegated) — hot path, so the schema is derived once and cached together with the definition.
5. **Data access**: either a dynamic typed tree (types converted per the schema) — the only option for configurator-built forms — or mapping onto a user DTO with the same TreeMapper, for forms with known classes.

## Boundary: what belongs to the library (ingot) vs the forms project

The library is a **generic toolkit** (nothing form-specific inside):

- **Schema module** — a facade over the validator (`opis/json-schema` today, native `JsonSchema` from PHP 8.6 as a future backend), schema compilation + caching, unified errors.
- **Mapper module** — JSON → readonly classes hydration: attributes (`#[Discriminator]`, `#[Format]`, `#[DefaultValue]`, `#[Name]`), generics via PHPDoc (`list<Field>`), strict/lax modes, `map()`/`tryMap()`.
- **SchemaGen module** — generating JSON Schema from classes (the same attributes the Mapper reads — zero drift) and a programmatic schema builder (for deriving schemas from models such as FormDefinition).
- **Tree module** — typed access to a document without classes, driven by a schema (converting `format` → PHP types).

The forms project (a separate package) consumes the toolkit: it defines the `FormDefinition`/`Field` classes, the meta-schema, and the rule for deriving the data-schema from a definition.

## Performance (design assumptions from day 1)

- **Definition**: parsed rarely → compiled into a cache artifact (var_export → PHP file → OPcache); the Mapper's reflection metadata cached the same way.
- **Data**: hot path → validation with a compiled/cached schema; hydration without repeated introspection; in stage 2 optional hydrator codegen (the eventsauce/Ajv-standalone pattern, ~3–10×).
- **PHP 8.4 provides concrete tools**: lazy objects (`ReflectionClass::newLazyGhost`) — lazy hydration of large trees; property hooks — a clean node API for the dynamic tree without magic `__get`.
- Measure from the start: phpbench + a "large form" fixture in the repo.

## Proposed MVP cut

**Stage 1 (MVP):**
1. Schema (facade + cache + errors) — a thin layer over `opis/json-schema`.
2. Mapper (hydration, discriminated unions, error aggregation, strict/lax) — the main effort.
3. Proof of concept: a minimal `FormDefinition` (3–4 field types) as a **simple test example inside the library repo** (fixtures/examples only — decided 2026-08-08: the real forms project is out of scope for this phase, no separate package yet).

**Stage 2:**
4. SchemaGen (classes → schema; builder for derivation).
5. Tree (dynamic access) + PHPDoc stub generator — **promoted in priority** by the generic/configurator forms requirement; may move into stage 1 if generic forms are needed early.
6. Hydrator codegen (performance), definition versioning (`version` field + migrations).

## Open questions

1. **Does form value validation fit within pure JSON Schema?** Conditional rules (field B required when A="x") are expressible (`if/then`, `dependentRequired`), but cross-field/business validations (e.g. "end date > start date") are a poor fit. Decide: (a) everything in JSON Schema (full frontend portability), (b) schema + an extended rules layer in PHP, (c) a custom, simpler rule language in the form definition, translated to JSON Schema where possible. → **Resolved**: the core does not deal with conditional/business rules at all — it only exposes the validator extension point; such rules live in consumer plugins (with their own vocabulary), which may or may not translate parts into the derived JSON Schema — see [03-custom-validation-design.md](03-custom-validation-design.md).
2. **Package structure**: a monorepo with two packages (`ingot/core` + `ingot/forms`) or a single repo for now, split later?
3. **8.4 vs 8.5 as the minimum**: 8.4 suffices (lazy objects, property hooks); 8.5 only if nothing existing must run it.
4. **How much Valinor do we "borrow"**: build the Mapper from scratch, or in the MVP internally base hydration on `cuyz/valinor` (behind our own API facade) and swap the engine later? Faster time-to-market vs control over performance and the error format.

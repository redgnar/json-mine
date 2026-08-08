# Context: second consumer — process/workflow definitions (BPMN / n8n-like)

Date: 2026-08-08. Continuation of [00-mvp-analysis.md](00-mvp-analysis.md) and [01-forms-context.md](01-forms-context.md).

## The use case

A second project will use the library for **process flow definitions** — broadly understood, in the spirit of BPMN or n8n: a JSON document describing a graph of nodes (tasks, gateways, events, triggers, actions) connected by edges, likely edited in a visual configurator, executed by some engine (the engine itself is out of scope for the library, just like form renderers are).

## What a workflow definition demands that a form does not

A form definition is essentially a **list/tree**. A workflow definition is a **graph with identity and references**. This surfaces new, generalizable requirements:

### 1. Intra-document references and referential integrity
Nodes have IDs; connections reference those IDs (`{"from": "node-1", "to": "node-2"}`). JSON has no native references and **JSON Schema cannot validate cross-references** ("every edge points to an existing node", "no dangling refs", "graph is acyclic where required").

→ The library needs a **semantic rules layer that runs after schema validation**, producing errors in the same unified format (JSON Pointer + code). Note this is the *same* conclusion the forms project reached from a different direction (cross-field rules like "end date > start date"). Two independent consumers hitting the same wall = this layer belongs in the **core toolkit**, not in consumer packages. → **Design resolved**: external `ObjectValidator` classes bound to mapped classes via a mapper registry — see [03-custom-validation-design.md](03-custom-validation-design.md).

Possibly also: first-class ID/reference support in the Mapper (an attribute like `#[References(Node::class)]` that resolves an ID string into an object reference during hydration, or at least validates it).

### 2. Open (extensible) discriminated unions
Forms have a smallish, known set of field types. Workflow node types are **plugin-territory** (n8n has hundreds; engines add custom nodes). The discriminator→class mapping cannot be a closed attribute list compiled into the library consumer:

- runtime **type registry** (register node type → class at bootstrap, e.g. by plugins),
- **fallback for unknown variants** — hydrate into a `GenericNode` that preserves the raw payload instead of failing, configurable per use (strict for execution, lenient for display/migration).

### 3. Lossless round-trip
A configurator loads a definition, edits it, saves it back. Unknown fields, vendor extensions (`x-*`), and unrecognized node types **must survive the round-trip**. Consequences:

- the **normalize direction (objects → JSON)** moves from "nice to have" into the core scope earlier than the forms project alone suggested,
- hydrated objects need a place to carry "unmapped extras" (an attribute like `#[Extras]` collecting unknown properties).

### 4. Dynamic islands inside typed trees
An n8n-style node has a typed skeleton (`id`, `type`, `name`, `position`, `connections`) plus a `parameters` object whose shape **depends on the node type** and may be described by a plugin-provided schema. That is exactly the Mapper (typed skeleton) composed with the Tree module (schema-driven dynamic access) **within one document**. The two modules must compose, not merely coexist.

### 5. Versioning and migrations
Workflows are long-lived, stored artifacts; engines evolve. Both consumers now want definition versioning (`version` field, migration hooks on load). Shared infrastructure candidate — still staged, but its priority rises.

### 6. Expressions in values (note only)
n8n embeds an expression language in values (`{{ $json.x }}`); BPMN has condition expressions. The library must not implement expressions, but the type system should tolerate "typed value OR expression string" (union value types / custom caster hook), so consumers can plug their own expression handling.

## What this confirms

- The **toolkit boundary holds**: nothing BPMN-specific enters the core; the workflow project supplies its own meta-schema, node classes, registry entries, and semantic rules — same pattern as the forms package.
- The **hybrid architecture (variant D)** is again required: typed skeleton + dynamic parts in a single document.
- The **derived-schema idea generalizes**: as a form definition derives a data-schema for its values, a workflow node type can derive a schema for its `parameters` — same SchemaGen builder.

## Impact on the MVP cut (updates to stage plan)

| Requirement | Previous position | New position |
|---|---|---|
| Semantic/referential rules layer (post-schema, unified errors) | implicit open question (forms Q1) | **core module, design for it in stage 1** (even if only the extension point ships in MVP) |
| Open discriminator registry + unknown-variant fallback | not planned | **stage 1** (Mapper design decision — hard to retrofit) |
| Normalizer (objects → JSON) with extras preservation | stage 2+ | **stage 2, design constraint from day 1** (Mapper metadata must be bidirectional) |
| Tree ⟷ Mapper composition (dynamic islands) | modules listed separately | explicit **composition requirement** |
| Versioning + migrations | "later" | stage 2, shared infra for both consumers |

The stage-1 code scope barely grows (registry + extension points), but several **design decisions must be made as if these features existed**, because they are hard to retrofit: bidirectional metadata, open unions, error-format extensibility.

## Open questions

1. **Reference resolution depth**: should the Mapper resolve ID references into object references (a real graph in memory), or only validate them and leave resolution to the consumer? Resolving creates cycles → conflicts with readonly/immutable trees; validating-only keeps the core simple. (Lean: validate in core, resolve via an optional lazy-object layer — PHP 8.4 lazy proxies fit here.)
2. **How far does "unknown variant tolerance" go** — is a strict mode required for execution engines (fail on unknown node) vs lenient for editors (preserve raw)? Probably a per-call mode flag.
3. Does the workflow project need **JSON Schema for the whole definition** at all, or is the meta-schema + semantic rules combination enough? (BPMN itself is XSD-based; n8n does not publish a JSON Schema for workflows — validation is mostly programmatic. Our schema-first approach would actually be ahead of n8n here.)

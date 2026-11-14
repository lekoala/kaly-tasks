# AGENTS.md

Kaly Tasks is pre-1.0 software, like Kaly.

Until 1.0, prefer the best long-term API and architecture over preserving backward compatibility.

This means agents may freely:

- rename, move, merge, or remove public classes and namespaces;
- change public APIs when the new shape is clearly simpler or more coherent;
- remove obsolete abstractions instead of keeping aliases, shims, or deprecation layers;
- fix naming and architectural mistakes while they are still cheap to fix.

Do not preserve a weaker design only because existing code already uses it.

Backward compatibility may still matter when a change has unusually high migration cost, but it is not the default constraint before 1.0.

When making a breaking change:

- update all first-party usages, tests, documentation, and examples in the same change;
- prefer one clean API over old/new APIs living side by side;
- mention the breaking change clearly in the release notes.

The goal before 1.0 is to converge toward the smallest, clearest, most durable public surface possible.

## Style

- Declare class constants first, then properties, then methods, starting with
  the constructor. Keep constructor-promoted properties in the constructor.
- Exception messages are a single sentence, with no trailing punctuation. This
  applies to native exceptions too (`InvalidArgumentException`, `LogicException`,
  `RuntimeException`).
- Kaly Tasks aims to stay usable without the Kaly framework. Exceptions extend
  SPL exceptions, never `Kaly\Ex` or `Kaly\Http\Exception\HttpException`.
  Kaly Tasks depends on `lekoala/kaly-contracts` (the task dispatcher
  interfaces), never on `lekoala/kaly`. kaly-di stays a dev and suggested
  dependency: the worker takes an invoker closure.

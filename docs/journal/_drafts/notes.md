
- core-feedback branch (PR open, for v0.3): native PHP 7.4 types across src/, plugin/, cli/ and the examples, from review on #66187. Interfaces now declare return types, which breaks any drop-in written against 0.2.x at class load. Plaintext values stay untyped on purpose (coercion would store false as an empty secret). Names are not narrowed. ADR 0013.

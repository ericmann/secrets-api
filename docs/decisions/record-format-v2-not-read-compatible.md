---
title: "Record format version bump policy"
description: "A future v2 record format will not be read-compatible with v1; the upgrade mechanism is left for when v2 exists."
---

## 🟡 Record format version bump policy

`'v' => 1` exists so a future format change is detectable rather than presenting as a decryption
failure.

**Decided: v2 will not be read-compatible with v1.** The upgrade path is therefore either a
migration pass over existing records, or a version-switched read path that keeps v1 handling
alongside v2. Which of the two is a decision for when v2 actually exists — they differ mainly in
whether sites take a one-time cost or the code carries two decoders indefinitely.

What that rules out, usefully: nobody should design v2 assuming a v1 reader can make sense of it,
and no future change should quietly widen v1's shape rather than bumping the version.

Still true and worth keeping in view when that day comes: `v` sits outside the AAD, so it is
unauthenticated metadata. It must be treated as a routing hint validated *before* decryption, and
an unknown `v` must be rejected outright rather than attempted. Today it is:
`secret_record_unsupported_version` is returned rather than guessed at, and that is tested.


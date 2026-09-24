# AWS KMS keyring build progress
Branch: (set by implement)
Started: (set by implement)

## Tasks
- [ ] P0-01 Add the keyring conformance suite and make Mock_Keyring pass it
- [ ] P0-02 State the non-determinism requirement in the keyring interface docblock
- [ ] P0-03 Push phase 0
- [ ] P1-01 Cache the unwrapped root key in WP_Secrets_Key_Manager for the request
- [ ] P1-02 Document root-key caching: examples README, spec page, ADR 0009
- [ ] P1-03 Push phase 1
- [ ] P2-01 Generalise wp secret rotate with --from and re-wrap under the active keyring
- [ ] P2-02 Push phase 2
- [ ] P3-01 Add the examples PHPUnit harness, Moto, and the AWS Secrets Manager conformance run
- [ ] P3-02 Add the examples CI job with a pinned Moto service container
- [ ] P3-03 Push phase 3
- [ ] P4-01 Write the AWS KMS keyring example and run the keyring conformance suite against Moto
- [ ] P4-02 Prove the KMS keyring end to end: round trip, one Decrypt per request, the adoption error, and adoption via rotate --from=config
- [ ] P4-03 Write the AWS KMS keyring README with the adoption walkthrough
- [ ] P4-04 Push phase 4
- [ ] P5-01 Bring the spec pages in line with the code
- [ ] P5-02 Update the journal tracking pages, the READMEs, and the index
- [ ] P5-03 Write the dev journal entry
- [ ] P5-04 Push phase 5, remove the Moto container, record the live-KMS check as not verified

## Log
(one entry per task, appended by implement)
